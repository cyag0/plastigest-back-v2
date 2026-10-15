<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\AdjustmentReasonCode;
use App\Models\Admin\Location;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Ver, editar y eliminar un ajuste de inventario: solo los de la propia
 * sucursal (y empresa), solo los registrados a mano, y una sola vez.
 */
class AdjustmentScopeTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MANAGER = ['inventory_list', 'inventory_read', 'inventory_manage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    /** Merma de $qty piezas registrada por el administrador en $location. */
    private function adjustmentAt(Location $location, Product $product, float $qty, string $direction = 'out'): InventoryAdjustmentDetail
    {
        $id = $this->actingInBranch($this->user, $location)
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $product->id,
                    'direction' => $direction,
                    'quantity' => $qty,
                    'unit_id' => $product->unit_id,
                    'reason_code' => 'damage',
                ]],
            ])->assertCreated()->json('data.0.id');

        return InventoryAdjustmentDetail::findOrFail($id);
    }

    public function test_adjustments_of_another_branch_or_company_are_not_found(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct();
        $this->setStock($coco, $otra, 50);
        $deOtra = $this->adjustmentAt($otra, $coco, 5);

        $encargado = $this->createBranchUser($this->location, self::MANAGER);
        $url = "/api/auth/admin/inventory-adjustments/{$deOtra->id}";

        // b8 de la auditoría: se abría, editaba y borraba por id desde otra sucursal.
        $this->actingInBranch($encargado)->getJson($url)->assertNotFound();
        $this->actingInBranch($encargado)->putJson($url, ['quantity' => 40])->assertNotFound();
        $this->actingInBranch($encargado)->deleteJson($url)->assertNotFound();

        $this->assertEquals(45, $this->stockOf($coco, $otra));
        $this->assertDatabaseHas('inventory_adjustment_details', ['id' => $deOtra->id, 'quantity' => 5]);

        // Y de otra empresa, ni el administrador.
        [$otraEmpresa, $sucursalAjena] = $this->createCompanyWithBranch();
        $ajeno = InventoryAdjustmentDetail::create([
            'company_id' => $otraEmpresa->id, 'location_id' => $sucursalAjena->id, 'created_by' => $this->user->id,
            'product_id' => $coco->id, 'direction' => 'out', 'quantity' => 1, 'unit_id' => $coco->unit_id,
            'previous_stock' => 1, 'new_stock' => 0, 'reason_code' => AdjustmentReasonCode::LOSS, 'applied_at' => now(),
        ]);
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/inventory-adjustments/{$ajeno->id}")->assertNotFound();
    }

    public function test_own_branch_manager_can_see_edit_and_delete_a_manual_adjustment(): void
    {
        $coco = $this->createProduct([], 50);
        $merma = $this->adjustmentAt($this->location, $coco, 5);
        $encargado = $this->createBranchUser($this->location, self::MANAGER);
        $url = "/api/auth/admin/inventory-adjustments/{$merma->id}";

        $this->actingInBranch($encargado)->getJson($url)->assertOk()->assertJsonPath('data.quantity', 5);

        $this->actingInBranch($encargado)->putJson($url, ['quantity' => 8])->assertOk();
        $this->assertEquals(42, $this->stockOf($coco));

        $this->actingInBranch($encargado)->deleteJson($url)->assertOk();
        $this->assertEquals(50, $this->stockOf($coco));
    }

    public function test_an_adjustment_cannot_be_moved_to_another_branch_or_product(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct([], 50);
        $agua = $this->createProduct([], 50);
        $this->setStock($coco, $otra, 50);
        $merma = $this->adjustmentAt($this->location, $coco, 5);
        $url = "/api/auth/admin/inventory-adjustments/{$merma->id}";

        // Antes location_id aceptaba cualquier sucursal (incluso de otra
        // empresa) y el efecto en el stock se aplicaba allá.
        $this->actingInBranch($this->user)->putJson($url, ['location_id' => $otra->id, 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonPath('code', 'immutable_adjustment');

        $this->actingInBranch($this->user)->putJson($url, ['product_id' => $agua->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'immutable_adjustment');

        $this->assertEquals(45, $this->stockOf($coco));
        $this->assertEquals(50, $this->stockOf($coco, $otra));
        $this->assertEquals(50, $this->stockOf($agua));

        // Mandar los mismos valores (el registro completo) sí se acepta.
        $this->actingInBranch($this->user)
            ->putJson($url, ['location_id' => $this->location->id, 'product_id' => $coco->id, 'quantity' => 6])
            ->assertOk();
        $this->assertEquals(44, $this->stockOf($coco));
    }

    public function test_adjustments_generated_by_counts_production_or_transfers_cannot_be_changed(): void
    {
        $coco = $this->createProduct([], 50);
        $make = fn(array $extra) => InventoryAdjustmentDetail::create(array_merge([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'created_by' => $this->user->id,
            'product_id' => $coco->id, 'direction' => 'out', 'quantity' => 3, 'unit_id' => $coco->unit_id,
            'previous_stock' => 53, 'new_stock' => 50, 'reason_code' => AdjustmentReasonCode::DAMAGE, 'applied_at' => now(),
        ], $extra));

        $generated = [
            $make(['reference_type' => 'inventory_count', 'reference_id' => 1, 'reason_code' => AdjustmentReasonCode::COUNT_DIFF]),
            $make(['reference_type' => 'production_order', 'reference_id' => 1]),
            $make(['content' => ['created_from_transfer' => true, 'source' => 'inventory_transfer_receive_difference']]),
        ];

        foreach ($generated as $adjustment) {
            $url = "/api/auth/admin/inventory-adjustments/{$adjustment->id}";

            $this->actingInBranch($this->user)->deleteJson($url)
                ->assertStatus(422)
                ->assertJsonPath('code', 'generated_adjustment');

            $this->actingInBranch($this->user)->putJson($url, ['quantity' => 1])
                ->assertStatus(422)
                ->assertJsonPath('code', 'generated_adjustment');
        }

        // Ninguno revirtió nada.
        $this->assertEquals(50, $this->stockOf($coco));
        $this->assertSame(3, InventoryAdjustmentDetail::count());
    }

    public function test_deleting_twice_reverts_the_stock_only_once(): void
    {
        $coco = $this->createProduct([], 50);
        $merma = $this->adjustmentAt($this->location, $coco, 5);
        $url = "/api/auth/admin/inventory-adjustments/{$merma->id}";

        $this->actingInBranch($this->user)->deleteJson($url)->assertOk();
        // Antes el segundo DELETE (doble toque) revertía otra vez: 55.
        $this->actingInBranch($this->user)->deleteJson($url)->assertNotFound();

        $this->assertEquals(50, $this->stockOf($coco));
    }

    public function test_deleting_an_entry_whose_stock_was_already_used_is_a_422_with_the_reason(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 0);
        $entrada = $this->adjustmentAt($this->location, $coco, 5, 'in');
        $this->setStock($coco, $this->location, 2); // se vendieron 3

        $this->actingInBranch($this->user)->deleteJson("/api/auth/admin/inventory-adjustments/{$entrada->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');

        $this->assertEquals(2, $this->stockOf($coco));
        $this->assertDatabaseHas('inventory_adjustment_details', ['id' => $entrada->id]);
    }

    public function test_an_adjustment_with_a_product_of_another_company_is_rejected(): void
    {
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $ajeno = $this->createProduct(['company_id' => $otraEmpresa->id]);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $ajeno->id, 'direction' => 'in', 'quantity' => 1,
                    'unit_id' => $ajeno->unit_id, 'reason_code' => 'found',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.product_id');
    }

    public function test_an_out_adjustment_without_stock_is_a_422_with_the_reason(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 1);

        // Antes: 500 "Error al crear ajustes" con el motivo escondido en "error".
        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $coco->id, 'direction' => 'out', 'quantity' => 3,
                    'unit_id' => $coco->unit_id, 'reason_code' => 'damage',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_an_adjustment_photo_over_5_mb_gets_a_clear_message(): void
    {
        $coco = $this->createProduct([], 10);

        $this->actingInBranch($this->user)
            ->post('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $coco->id, 'direction' => 'out', 'quantity' => 1,
                    'unit_id' => $coco->unit_id, 'reason_code' => 'damage',
                    'evidence' => [\Illuminate\Http\UploadedFile::fake()->image('merma.jpg')->size(5500)],
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cada foto de evidencia debe pesar 5 MB o menos. Tómala con menos resolución o elige otra.');

        $this->assertEquals(10, $this->stockOf($coco));
    }
}
