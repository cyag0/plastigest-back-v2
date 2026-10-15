<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryTransfer;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Dos pasos sobre la misma transferencia al mismo tiempo. Cada prueba arma la
 * intercalación con una copia vieja del registro, como la que tiene un
 * request que lo leyó antes de que otro confirmara su cambio: el servicio
 * tiene que decidir con el estado bloqueado y releído, no con esa copia.
 */
class TransferConcurrencyTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    private TransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
        $this->service = app(TransferService::class);
    }

    /** Matriz con 100 cocos y una transferencia de 10 a la sucursal del contexto. */
    private function scenario(): array
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 100);

        return [$matriz, $coco, $this->requestTransfer($matriz, $this->location, $coco, 10)];
    }

    private function receive(InventoryTransfer $transfer): void
    {
        $this->actingInBranch($this->user, $transfer->toLocation)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", $this->fullReceivePayload($transfer))
            ->assertOk();
    }

    public function test_cancel_that_read_in_transit_does_not_return_stock_already_received(): void
    {
        [$matriz, $coco, $transfer] = $this->scenario();
        $stale = $this->shipTransfer($transfer);   // la pantalla de cancelar lo leyó en tránsito
        $this->receive($stale);                     // el destino recibe antes

        try {
            $this->service->cancel($stale, 'Cancelada por el usuario');
            $this->fail('Cancelar una transferencia ya recibida debe rechazarse.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_status', $e->errorCode());
        }

        // Antes: el origen recuperaba los 10 y el destino conservaba los 10 (110 de 100).
        $this->assertEquals(90, $this->stockOf($coco, $matriz));
        $this->assertEquals(10, $this->stockOf($coco));
        $this->assertSame(TransferStatus::COMPLETED, $transfer->fresh()->status);
    }

    public function test_two_cancels_return_the_shipped_stock_only_once(): void
    {
        [$matriz, $coco, $transfer] = $this->scenario();
        $transfer = $this->shipTransfer($transfer);
        $first = InventoryTransfer::find($transfer->id);
        $second = InventoryTransfer::find($transfer->id);

        $this->service->cancel($first, 'Doble toque');

        $this->expectException(BusinessRuleException::class);
        try {
            $this->service->cancel($second, 'Doble toque');
        } finally {
            $this->assertEquals(100, $this->stockOf($coco, $matriz), 'devuelto una sola vez');
            $this->assertSame(TransferStatus::CANCELLED, $transfer->fresh()->status);
        }
    }

    public function test_cancel_that_read_approved_returns_what_was_shipped_meanwhile(): void
    {
        [$matriz, $coco, $transfer] = $this->scenario();
        $stale = $this->approveTransfer($transfer);  // se leyó aprobada
        $this->shipTransfer_withoutApproving($stale); // el origen la envía antes

        $this->service->cancel($stale, 'Ya no se necesita');

        // Antes: no devolvía nada (creía que seguía aprobada) y los 10 cocos
        // quedaban fuera del inventario sin poder recibirse.
        $this->assertEquals(100, $this->stockOf($coco, $matriz));
        $this->assertSame(TransferStatus::CANCELLED, $transfer->fresh()->status);
    }

    public function test_reject_that_read_in_transit_does_not_return_stock_already_received(): void
    {
        [$matriz, $coco, $transfer] = $this->scenario();
        $stale = $this->shipTransfer($transfer);
        $this->receive($stale);

        $this->expectException(BusinessRuleException::class);
        try {
            $this->service->reject($stale, 'Llegó tarde');
        } finally {
            $this->assertEquals(90, $this->stockOf($coco, $matriz));
            $this->assertEquals(10, $this->stockOf($coco));
            $this->assertSame(TransferStatus::COMPLETED, $transfer->fresh()->status);
        }
    }

    public function test_approve_does_not_revive_a_cancelled_request(): void
    {
        [, , $transfer] = $this->scenario();
        $stale = InventoryTransfer::find($transfer->id);   // pendiente al leerla

        $this->service->cancel(InventoryTransfer::find($transfer->id), 'Ya no se necesita');

        $this->expectException(BusinessRuleException::class);
        try {
            $this->service->approve($stale, []);
        } finally {
            $this->assertSame(TransferStatus::CANCELLED, $transfer->fresh()->status);
        }
    }

    public function test_cancelling_a_received_transfer_through_the_api_is_a_422(): void
    {
        [, , $transfer] = $this->scenario();
        $transfer = $this->shipTransfer($transfer);
        $this->receive($transfer);

        $this->actingInBranch($this->user)
            ->deleteJson("/api/auth/admin/inventory-transfers/{$transfer->id}")
            ->assertStatus(422);
    }

    /** Envía una transferencia que ya está aprobada. */
    private function shipTransfer_withoutApproving(InventoryTransfer $transfer): void
    {
        $detail = $transfer->details->first();

        $this->actingInBranch($this->user, $transfer->fromLocation)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $detail->id, 'quantity_shipped' => (float) $detail->quantity_requested]],
                'evidence' => [\Illuminate\Http\UploadedFile::fake()->image('camion.jpg')],
            ])->assertOk();
    }
}
