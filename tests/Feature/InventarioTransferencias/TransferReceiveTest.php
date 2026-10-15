<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\TransferStatus;
use App\Models\Admin\Location;
use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Envío y recepción: renglones completos y fotos de evidencia.
 */
class TransferReceiveTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    private Location $matriz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
        $this->matriz = $this->createBranch(['name' => 'Matriz']);
    }

    /** Transferencia en tránsito de dos productos (10 cocos y 4 litros de agua). */
    private function inTransitWithTwoLines(): array
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $agua = $this->createProduct(['name' => 'Agua de coco'], 0, 'Litro');
        $this->setStock($coco, $this->matriz, 50);
        $this->setStock($agua, $this->matriz, 50);

        $id = $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', [
                'from_location_id' => $this->matriz->id,
                'to_location_id' => $this->location->id,
                'details' => [
                    ['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity_requested' => 10],
                    ['product_id' => $agua->id, 'unit_id' => $agua->unit_id, 'quantity_requested' => 4],
                ],
            ])->assertCreated()->json('data.id');

        $transfer = $this->approveTransfer(InventoryTransfer::findOrFail($id));

        $this->actingInBranch($this->user, $this->matriz)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => $transfer->details->map(fn($d) => ['detail_id' => $d->id, 'quantity_shipped' => (float) $d->quantity_requested])->all(),
                'evidence' => [UploadedFile::fake()->image('camion.jpg')],
            ])->assertOk();

        return [$transfer->fresh('details'), $coco, $agua];
    }

    public function test_receiving_without_every_shipped_line_is_rejected(): void
    {
        [$transfer, $coco, $agua] = $this->inTransitWithTwoLines();
        $cocoLine = $transfer->details->firstWhere('product_id', $coco->id);

        // Antes: el agua (omitida) nunca llegaba al destino, no pedía motivo ni
        // evidencia y la transferencia quedaba "recibida completa".
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", [
                'items' => [['detail_id' => $cocoLine->id, 'quantity_received' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_lines')
            ->assertJsonPath('message', 'Falta indicar cuánto llegó de: Agua de coco.');

        $this->assertSame(TransferStatus::IN_TRANSIT, $transfer->fresh()->status);
        $this->assertEquals(0, $this->stockOf($coco));
        $this->assertEquals(0, $this->stockOf($agua));
    }

    public function test_a_shortage_needs_evidence_and_leaves_no_files_when_rejected(): void
    {
        [$transfer] = $this->inTransitWithTwoLines();
        $payload = $this->fullReceivePayload($transfer);
        $payload['items'][0]['quantity_received'] = (float) $payload['items'][0]['quantity_received'] - 2;
        $payload['items'][0]['adjustment_reason'] = 'damage';
        $payload['items'][0]['adjustment_comment'] = 'Se rompieron en el camino';
        $payload['evidence'] = [UploadedFile::fake()->image('llegada.jpg')];

        $filesBefore = count(Storage::disk('public')->allFiles());

        $this->actingInBranch($this->user)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", $payload, ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertCount($filesBefore, Storage::disk('public')->allFiles(), 'una recepción rechazada no guarda fotos');
        $this->assertSame(TransferStatus::IN_TRANSIT, $transfer->fresh()->status);
    }

    public function test_receiving_with_a_shortage_records_everything_together(): void
    {
        [$transfer, $coco, $agua] = $this->inTransitWithTwoLines();
        $cocoLine = $transfer->details->firstWhere('product_id', $coco->id);
        $aguaLine = $transfer->details->firstWhere('product_id', $agua->id);

        $this->actingInBranch($this->user)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", [
                'items' => [
                    [
                        'detail_id' => $cocoLine->id,
                        'quantity_received' => 8,
                        'adjustment_reason' => 'damage',
                        'adjustment_comment' => 'Se rompieron en el camino',
                        'adjustment_evidence' => [UploadedFile::fake()->image('rotos.jpg')],
                    ],
                    ['detail_id' => $aguaLine->id, 'quantity_received' => 4],
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.content.step_3.has_differences', true);

        $this->assertEquals(8, $this->stockOf($coco));
        $this->assertEquals(4, $this->stockOf($agua));

        $adjustment = InventoryAdjustmentDetail::where('product_id', $coco->id)->sole();
        $this->assertEquals(2, (float) $adjustment->quantity);
        $this->assertTrue($adjustment->content['created_from_transfer']);
        $this->assertCount(1, $adjustment->content['evidence_files']);

        $content = $transfer->fresh()->content;
        $this->assertSame($adjustment->id, $content['workflow']['step_3']['adjustments'][0]['id']);
    }

    // ─── Fotos de evidencia ─────────────────────────────────────────────────

    public function test_a_photo_over_5_mb_gets_a_clear_message(): void
    {
        $coco = $this->createProduct();
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->approveTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 5));

        // Antes: 400 "El archivo evidence.0 no debe pesar más de 5120 kilobytes."
        $this->actingInBranch($this->user, $this->matriz)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => 5]],
                'evidence' => [UploadedFile::fake()->image('camion.jpg')->size(6000)],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cada foto de evidencia debe pesar 5 MB o menos. Tómala con menos resolución o elige otra.')
            ->assertJsonValidationErrors('evidence.0');

        $this->assertEquals(50, $this->stockOf($coco, $this->matriz));
    }

    public function test_a_photo_cut_by_the_php_upload_limit_gets_the_same_message(): void
    {
        $coco = $this->createProduct();
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->approveTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 5));

        // Arriba de upload_max_filesize la foto llega como subida fallida.
        $cut = new UploadedFile(tempnam(sys_get_temp_dir(), 'ev'), 'camion.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingInBranch($this->user, $this->matriz)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => 5]],
                'evidence' => [$cut],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cada foto de evidencia debe pesar 5 MB o menos. Tómala con menos resolución o elige otra.');
    }

    public function test_shipping_without_photos_asks_for_one(): void
    {
        $coco = $this->createProduct();
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->approveTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 5));

        $this->actingInBranch($this->user, $this->matriz)
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sube al menos una foto de evidencia del envío.');
    }

    public function test_a_shortage_photo_over_5_mb_on_receive_gets_the_same_message(): void
    {
        [$transfer] = $this->inTransitWithTwoLines();
        $payload = $this->fullReceivePayload($transfer);
        $payload['items'][0]['quantity_received'] = 0;
        $payload['items'][0]['adjustment_reason'] = 'loss';
        $payload['items'][0]['adjustment_comment'] = 'No llegó';
        $payload['items'][0]['adjustment_evidence'] = [UploadedFile::fake()->image('caja.jpg')->size(5200)];

        $this->actingInBranch($this->user)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/receive", $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cada foto de evidencia debe pesar 5 MB o menos. Tómala con menos resolución o elige otra.');
    }

    public function test_shipping_more_than_the_origin_has_is_a_422_with_the_reason(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->approveTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 20));
        $this->setStock($coco, $this->matriz, 5); // se vendió mientras tanto

        $this->actingInBranch($this->user, $this->matriz)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => 20]],
                'evidence' => [UploadedFile::fake()->image('camion.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 5 Pieza, solicitado: 20 Pieza.');

        $this->assertSame(TransferStatus::APPROVED, $transfer->fresh()->status);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
