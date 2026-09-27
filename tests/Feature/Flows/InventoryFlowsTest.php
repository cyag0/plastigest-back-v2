<?php

namespace Tests\Feature\Flows;

use App\Enums\TransferStatus;
use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\InventoryType;
use App\Models\Operations\ProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Flujos que mueven existencias: ajustes, producción y transferencias.
 * Cada prueba cubre un bug que llegó a producción (puntos 8, 11 y 15).
 */
class InventoryFlowsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    // ─── Ajustes (punto 15) ──────────────────────────────────────────────────

    public function test_adjustment_converts_to_the_product_unit(): void
    {
        // Producto medido en gramos; el ajuste se captura en kilogramos.
        $pulpa = $this->createProduct([], 0, 'Gramo');

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $pulpa->id,
                    'direction' => 'in',
                    'quantity' => 0.5,
                    'unit_id' => $this->unitId('Kilogramo'),
                    'reason_code' => 'found',
                ]],
            ])->assertCreated();

        // Antes quedaban 0.5 g (mil veces menos).
        $this->assertEqualsWithDelta(500, $this->stockOf($pulpa), 0.001);
    }

    public function test_deleting_an_adjustment_reverts_its_stock(): void
    {
        $pulpa = $this->createProduct([], 100, 'Gramo');

        $id = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $pulpa->id,
                    'direction' => 'out',
                    'quantity' => 0.03,
                    'unit_id' => $this->unitId('Kilogramo'),
                    'reason_code' => 'damage',
                ]],
            ])->assertCreated()->json('data.0.id');

        $this->assertEqualsWithDelta(70, $this->stockOf($pulpa), 0.001);

        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/inventory-adjustments/{$id}")
            ->assertOk();

        $this->assertEqualsWithDelta(100, $this->stockOf($pulpa), 0.001);
    }

    // ─── Producción (punto 8) ────────────────────────────────────────────────

    private function productionPayload(int $cocoId, int $aguaId, array $overrides = []): array
    {
        return array_merge([
            'production_date' => now()->toDateString(),
            'responsible_user_id' => $this->user->id,
            'consumptions' => [['product_id' => $cocoId, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 14]],
            'outputs' => [['product_id' => $aguaId, 'unit_id' => $this->unitId('Litro'), 'quantity' => 10]],
        ], $overrides);
    }

    public function test_draft_does_not_move_stock_until_completed(): void
    {
        $coco = $this->createProduct([], 50);
        $agua = $this->createProduct([], 0, 'Litro', InventoryType::FINISHED_PRODUCT);

        $orderId = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', $this->productionPayload($coco->id, $agua->id))
            ->assertCreated()->json('data.id');

        $this->assertSame('draft', ProductionOrder::find($orderId)->status->value);
        $this->assertEquals(50, $this->stockOf($coco));

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$orderId}/complete")
            ->assertOk();

        $this->assertEquals(36, $this->stockOf($coco));
        $this->assertEquals(10, $this->stockOf($agua));
        $this->assertSame('completed', ProductionOrder::find($orderId)->status->value);
    }

    public function test_editing_a_draft_with_affect_stock_completes_it(): void
    {
        $coco = $this->createProduct([], 50);
        $agua = $this->createProduct([], 0, 'Litro', InventoryType::FINISHED_PRODUCT);

        $orderId = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', $this->productionPayload($coco->id, $agua->id))
            ->json('data.id');

        // Antes la edición ignoraba affect_stock y la orden seguía en borrador.
        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/production-orders/{$orderId}", $this->productionPayload($coco->id, $agua->id, ['affect_stock' => true]))
            ->assertOk();

        $this->assertSame('completed', ProductionOrder::find($orderId)->status->value);
        $this->assertEquals(36, $this->stockOf($coco));
    }

    public function test_insufficient_stock_rolls_back_the_whole_order(): void
    {
        $coco = $this->createProduct([], 5);
        $agua = $this->createProduct([], 0, 'Litro', InventoryType::FINISHED_PRODUCT);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', $this->productionPayload($coco->id, $agua->id, ['affect_stock' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        $this->assertSame(0, ProductionOrder::count(), 'no debe quedar un borrador huérfano');
        $this->assertEquals(5, $this->stockOf($coco));
    }

    public function test_draft_keeps_its_wastes_when_loaded_for_editing(): void
    {
        $coco = $this->createProduct([], 50);
        $agua = $this->createProduct([], 0, 'Litro', InventoryType::FINISHED_PRODUCT);

        $orderId = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', $this->productionPayload($coco->id, $agua->id, [
                'wastes' => [['product_id' => $coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 2, 'reason' => 'damage']],
            ]))->json('data.id');

        $this->withHeaders($this->tenantHeaders())
            ->getJson("/api/auth/admin/production-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.wastes.0.quantity', 2);
    }

    // ─── Transferencias (punto 11) ───────────────────────────────────────────

    /** Crea una solicitud: la sucursal del contexto pide a $origin. */
    private function requestTransfer(Location $origin, int $productId, float $qty): InventoryTransfer
    {
        $id = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-transfers', [
                'from_location_id' => $origin->id,
                'to_location_id' => $this->location->id,
                'details' => [['product_id' => $productId, 'unit_id' => $this->unitId('Pieza'), 'quantity_requested' => $qty, 'unit_cost' => 10]],
            ])->assertCreated()->json('data.id');

        return InventoryTransfer::with('details')->findOrFail($id);
    }

    private function ship(InventoryTransfer $transfer, Location $origin, float $qty): void
    {
        $this->withHeaders($this->tenantHeaders($origin))
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => $qty]],
                'evidence' => [UploadedFile::fake()->image('camion.jpg')],
            ])->assertOk();
    }

    public function test_ship_step_uses_the_quantity_approved(): void
    {
        $origin = Location::factory()->create(['company_id' => $this->company->id]);
        $coco = $this->createProduct();
        $this->setStock($coco, $origin, 36);

        $transfer = $this->requestTransfer($origin, $coco->id, 10);

        // Quien aprueba lo baja a 5. Antes quedaba solo en content y el envío proponía 10.
        $this->withHeaders($this->tenantHeaders($origin))
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/approve", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_requested' => 5]],
            ])->assertOk();

        $this->assertEquals(5, (float) $transfer->details->first()->fresh()->quantity_requested);

        $this->ship($transfer->fresh('details'), $origin, 5);
        $this->assertEquals(31, $this->stockOf($coco, $origin));

        $this->withHeaders($this->tenantHeaders())
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_received' => 5]],
            ])->assertOk();

        $this->assertEquals(5, $this->stockOf($coco));
        $this->assertSame(TransferStatus::COMPLETED, $transfer->fresh()->status);
    }

    public function test_origin_can_reject_a_transfer_request(): void
    {
        $origin = Location::factory()->create(['company_id' => $this->company->id]);
        $coco = $this->createProduct();
        $this->setStock($coco, $origin, 50);

        $transfer = $this->requestTransfer($origin, $coco->id, 10);

        // Antes fallaba: 'rejected' no estaba en el enum de la columna status.
        $this->withHeaders($this->tenantHeaders($origin))
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/reject", ['rejection_reason' => 'Sin reparto'])
            ->assertOk();

        $this->assertSame(TransferStatus::REJECTED, $transfer->fresh()->status);
        $this->assertEquals(50, $this->stockOf($coco, $origin));
    }

    public function test_cancelling_an_in_transit_transfer_returns_stock_to_origin(): void
    {
        $origin = Location::factory()->create(['company_id' => $this->company->id]);
        $coco = $this->createProduct();
        $this->setStock($coco, $origin, 200);

        $transfer = $this->requestTransfer($origin, $coco->id, 10);
        $this->withHeaders($this->tenantHeaders($origin))
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/approve", [])->assertOk();
        $this->ship($transfer->fresh('details'), $origin, 10);
        $this->assertEquals(190, $this->stockOf($coco, $origin));

        // Antes respondía 500 (columnas inexistentes) y el stock se perdía.
        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/inventory-transfers/{$transfer->id}")
            ->assertOk();

        $this->assertSame(TransferStatus::CANCELLED, $transfer->fresh()->status);
        $this->assertEquals(200, $this->stockOf($coco, $origin));
    }
}
