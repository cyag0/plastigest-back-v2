<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryCountDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Conteo físico (C8): "Correcto" toma la existencia del servidor al guardar,
 * cambiar solo las notas no vuelve a tomar la existencia, completar acepta
 * JSON y nadie escribe en el conteo de otra sucursal o empresa.
 */
class CountDetailTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const DETAILS = '/api/auth/admin/inventory-counts-details';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function openCount(): InventoryCount
    {
        $id = $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-counts', [
                'name' => 'Conteo semanal',
                'count_date' => now()->toDateString(),
            ])->assertCreated()->json('data.id');

        return InventoryCount::findOrFail($id);
    }

    private function saveDetail(InventoryCount $count, Product $product, array $payload)
    {
        return $this->actingInBranch($this->user)->postJson(self::DETAILS, array_merge([
            'inventory_count_id' => $count->id,
            'product_id' => $product->id,
        ], $payload));
    }

    private function completeAsJson(InventoryCount $count)
    {
        // Lo que manda la app (inventoryCounts.complete): solo el
        // encabezado y el estado, como application/json.
        return $this->actingInBranch($this->user)->putJson("/api/auth/admin/inventory-counts/{$count->id}", [
            'name' => 'Conteo semanal',
            'count_date' => now()->toDateString(),
            'notes' => 'Sin novedades',
            'status' => 'completed',
        ]);
    }

    public function test_correcto_counts_the_server_stock_at_save_time_not_the_screen_value(): void
    {
        $coco = $this->createProduct([], 100);
        $count = $this->openCount();

        // La pantalla cargó 100; mientras tanto se vendieron 20.
        $this->setStock($coco, $this->location, 80);

        $this->saveDetail($count, $coco, ['counted_quantity' => 100, 'system_quantity' => 100, 'matches_system' => 1])
            ->assertCreated()
            ->assertJsonPath('data.counted_quantity', '80.000')
            ->assertJsonPath('data.system_quantity', '80.000')
            ->assertJsonPath('data.difference', '0.000');

        // Antes: diferencia +20 y el cierre devolvía el stock a 100.
        $this->completeAsJson($count)->assertOk();

        $this->assertEquals(80, $this->stockOf($coco));
        $this->assertSame('completed', $count->fresh()->status);
        $this->assertFalse(InventoryAdjustmentDetail::where('reference_type', 'inventory_count')->exists());
    }

    public function test_editing_only_the_notes_keeps_the_system_snapshot(): void
    {
        $coco = $this->createProduct([], 100);
        $count = $this->openCount();

        $id = $this->saveDetail($count, $coco, ['counted_quantity' => 90])
            ->assertCreated()
            ->assertJsonPath('data.difference', '-10.000')
            ->json('data.id');

        // Se venden 30 y después solo se agrega una nota (la app reenvía lo contado).
        $this->setStock($coco, $this->location, 70);

        $this->saveDetail($count, $coco, ['counted_quantity' => 90, 'notes' => 'Dos cocos golpeados'])
            ->assertOk()
            ->assertJsonPath('data.system_quantity', '100.000')
            ->assertJsonPath('data.difference', '-10.000')
            ->assertJsonPath('data.notes', 'Dos cocos golpeados');

        // Lo mismo por PUT.
        $this->actingInBranch($this->user)->putJson(self::DETAILS . "/{$id}", ['notes' => 'Revisado'])
            ->assertOk()
            ->assertJsonPath('data.system_quantity', '100.000')
            ->assertJsonPath('data.difference', '-10.000');

        // Un conteo nuevo sí se compara con la existencia de ahora.
        $this->saveDetail($count, $coco, ['counted_quantity' => 65])
            ->assertOk()
            ->assertJsonPath('data.system_quantity', '70.000')
            ->assertJsonPath('data.difference', '-5.000');

        $this->assertSame(1, InventoryCountDetail::where('inventory_count_id', $count->id)->count());
    }

    public function test_completion_with_a_json_body_applies_the_differences_and_closes_the_count(): void
    {
        $coco = $this->createProduct([], 100);
        $agua = $this->createProduct([], 10);
        $count = $this->openCount();

        $this->saveDetail($count, $coco, ['counted_quantity' => 92.5])->assertCreated();
        $this->saveDetail($count, $agua, ['matches_system' => true])->assertCreated();
        $this->assertSame('counting', $count->fresh()->status);

        $this->completeAsJson($count)->assertOk();

        $this->assertEquals(92.5, $this->stockOf($coco));
        $this->assertEquals(10, $this->stockOf($agua));
        $this->assertSame(1, InventoryAdjustmentDetail::where('reference_type', 'inventory_count')->where('reference_id', $count->id)->count());

        // Ya cerrado: no se cuentan más productos ni se reabre.
        $this->saveDetail($count, $agua, ['counted_quantity' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'count_closed');
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/inventory-counts/{$count->id}", ['status' => 'counting'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'count_closed');

        // Repetir el cierre no vuelve a aplicar la diferencia.
        $this->completeAsJson($count)->assertOk();
        $this->assertEquals(92.5, $this->stockOf($coco));
    }

    public function test_a_cancelled_count_cannot_be_completed(): void
    {
        $coco = $this->createProduct([], 100);
        $count = $this->openCount();
        $this->saveDetail($count, $coco, ['counted_quantity' => 40])->assertCreated();

        $this->actingInBranch($this->user)->putJson("/api/auth/admin/inventory-counts/{$count->id}", ['status' => 'cancelled'])
            ->assertOk();

        // Antes aplicaba la diferencia de un conteo cancelado (-60).
        $this->completeAsJson($count)->assertStatus(422)->assertJsonPath('code', 'count_closed');
        $this->assertEquals(100, $this->stockOf($coco));
        $this->assertSame('cancelled', $count->fresh()->status);
    }

    public function test_nobody_writes_into_the_count_of_another_branch_or_company(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct([], 100);
        $this->setStock($coco, $otra, 50);

        $id = $this->actingInBranch($this->user, $otra)
            ->postJson('/api/auth/admin/inventory-counts', ['name' => 'Conteo de otra', 'count_date' => now()->toDateString()])
            ->assertCreated()->json('data.id');
        $deOtra = InventoryCount::findOrFail($id);
        $renglon = $this->actingInBranch($this->user, $otra)
            ->postJson(self::DETAILS, ['inventory_count_id' => $deOtra->id, 'product_id' => $coco->id, 'counted_quantity' => 45])
            ->assertCreated()->json('data.id');

        $encargado = $this->createBranchUser($this->location, ['inventory_list', 'inventory_read', 'inventory_create', 'inventory_update']);

        // Antes guardaba el renglón y la diferencia se aplicaba al cerrar el de la otra sucursal.
        $this->actingInBranch($encargado)
            ->postJson(self::DETAILS, ['inventory_count_id' => $deOtra->id, 'product_id' => $coco->id, 'counted_quantity' => 0])
            ->assertNotFound();
        $this->actingInBranch($encargado)->putJson(self::DETAILS . "/{$renglon}", ['counted_quantity' => 0])->assertNotFound();
        $this->assertEquals(45, (float) InventoryCountDetail::findOrFail($renglon)->counted_quantity);

        // El conteo de otra empresa no existe para esta.
        [$otraEmpresa, $sucursalAjena] = $this->createCompanyWithBranch();
        $ajeno = InventoryCount::create([
            'company_id' => $otraEmpresa->id, 'location_id' => $sucursalAjena->id, 'user_id' => $this->user->id,
            'name' => 'Ajeno', 'count_date' => now()->toDateString(), 'status' => 'counting',
        ]);
        $this->actingInBranch($this->user)
            ->postJson(self::DETAILS, ['inventory_count_id' => $ajeno->id, 'product_id' => $coco->id, 'counted_quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('inventory_count_id');
        $this->assertFalse(InventoryCountDetail::where('inventory_count_id', $ajeno->id)->exists());
    }
}
