<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\InventoryType;
use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Operations\FormulaOutput;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El registro de producción como lo usa la app: los procesos con lo que hay
 * en la sucursal, los graneles con sus presentaciones, y el rendimiento de
 * cada producción (litros de agua por coco) contra lo que espera el proceso.
 */
class ProductionFlowTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private Product $coco;
    private Product $agua;
    private Product $pulpa;
    private Product $botella;
    private Product $tapa;
    private Product $aguaLitro;
    private Formula $abrirCocos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);

        $this->coco = $this->createProduct(['name' => 'Coco entero'], 300);
        $this->agua = $this->createProduct(['name' => 'Agua de coco (granel)', 'for_sale' => false], 0, 'Litro', InventoryType::SEMI_FINISHED);
        $this->pulpa = $this->createProduct(['name' => 'Pulpa de coco (granel)', 'for_sale' => false], 0, 'Kilogramo', InventoryType::SEMI_FINISHED);
        $this->botella = $this->createProduct(['name' => 'Botella 1 L'], 100, 'Pieza', InventoryType::PACKAGING_MATERIAL);
        $this->tapa = $this->createProduct(['name' => 'Tapa R-28'], 100, 'Pieza', InventoryType::PACKAGING_MATERIAL);

        $this->aguaLitro = $this->createProduct([
            'name' => 'Agua de coco 1 L',
            'requires_packaging' => true,
            'packaging_source_product_id' => $this->agua->id,
            'packaging_source_quantity' => 1000,
            'packaging_source_unit_id' => $this->unitId('Mililitro'),
        ], 0, 'Pieza', InventoryType::FINISHED_PRODUCT);
        foreach ([$this->botella, $this->tapa] as $material) {
            $this->aguaLitro->packagingMaterials()->create([
                'material_product_id' => $material->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 1,
            ]);
        }

        // Por coco: 0.6 L de agua y 0.15 kg de pulpa.
        $this->abrirCocos = Formula::create(['company_id' => $this->company->id, 'name' => 'Abrir cocos', 'version' => 1, 'is_active' => true]);
        FormulaItem::create(['formula_id' => $this->abrirCocos->id, 'product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'expected_quantity' => 1, 'sort_order' => 0]);
        FormulaOutput::create(['formula_id' => $this->abrirCocos->id, 'product_id' => $this->agua->id, 'unit_id' => $this->unitId('Litro'), 'expected_quantity' => 0.6, 'is_primary' => true, 'sort_order' => 0]);
        FormulaOutput::create(['formula_id' => $this->abrirCocos->id, 'product_id' => $this->pulpa->id, 'unit_id' => $this->unitId('Kilogramo'), 'expected_quantity' => 0.15, 'is_primary' => false, 'sort_order' => 1]);
    }

    public function test_initial_data_brings_processes_with_stock_and_bulks_with_their_presentations(): void
    {
        $data = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/production-orders/initial-data')
            ->assertOk()
            ->json('data');

        $process = collect($data['processes'])->firstWhere('name', 'Abrir cocos');
        $this->assertSame('Coco entero', $process['items'][0]['product_name']);
        $this->assertEquals(300, $process['items'][0]['stock']);
        $this->assertSame('pz', $process['items'][0]['unit_abbreviation']);
        $this->assertTrue($process['outputs'][0]['is_bulk']);
        $this->assertTrue($process['outputs'][0]['has_presentations']);
        $this->assertFalse($process['outputs'][1]['has_presentations'], 'la pulpa no se envasa');

        $bulk = collect($data['bulks'])->firstWhere('product_name', 'Agua de coco (granel)');
        $this->assertSame('L', $bulk['unit_abbreviation']);
        $presentation = $bulk['presentations'][0];
        $this->assertSame('Agua de coco 1 L', $presentation['product_name']);
        // 1000 ml de un granel en litros: la app recibe 1 L ya convertido.
        $this->assertEquals(1, $presentation['content_in_bulk_unit']);
        $this->assertEqualsCanonicalizing(['Botella 1 L', 'Tapa R-28'], array_column($presentation['materials'], 'product_name'));
        $this->assertEquals(100, $presentation['materials'][0]['stock']);

        $this->assertNotEmpty($data['responsibles']);
        $this->assertNotEmpty($data['waste_reasons']);
    }

    public function test_a_registered_production_reports_its_yield_against_the_process(): void
    {
        $order = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'formula_id' => $this->abrirCocos->id,
                'production_date' => now()->toDateString(),
                'consumptions' => [['product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 200]],
                'outputs' => [
                    ['product_id' => $this->agua->id, 'unit_id' => $this->unitId('Litro'), 'quantity' => 110],
                    ['product_id' => $this->pulpa->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 30],
                ],
                'packagings' => [['product_id' => $this->aguaLitro->id, 'quantity' => 100]],
                'affect_stock' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Abrir cocos', $order['title']);
        // Cocos con litros no se suman: no hay "merma" de −566 %. Los cocos
        // sí tienen total; litros con kilos, no.
        $this->assertNull($order['waste_percentage']);
        $this->assertEquals(200, $order['total_consumed_quantity']);
        $this->assertNull($order['total_produced_quantity']);

        [$water, $pulp] = $order['yields'];
        $this->assertSame('Agua de coco (granel)', $water['product_name']);
        $this->assertSame('Coco entero', $water['input_product_name']);
        $this->assertEquals(0.55, $water['per_input']);
        $this->assertEquals(0.6, $water['expected_per_input']);
        $this->assertEquals(-8.3, $water['variance_pct']);
        $this->assertEquals(0.15, $pulp['per_input']);
        $this->assertEquals(0.0, $pulp['variance_pct']);

        $this->assertSame('L', $order['outputs'][0]['unit_abbreviation']);
        $this->assertSame('Agua de coco 1 L', $order['packagings'][0]['product_name']);
        $this->assertEquals(100, $this->stockOf($this->aguaLitro));
        $this->assertEquals(10, $this->stockOf($this->agua), 'quedan 10 L de agua en granel');
        $this->assertEquals(0, $this->stockOf($this->botella));
        $this->assertEquals(100, $this->stockOf($this->coco));
    }

    public function test_the_list_says_what_went_in_and_what_came_out(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'formula_id' => $this->abrirCocos->id,
                'production_date' => now()->toDateString(),
                'consumptions' => [['product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 10]],
                'outputs' => [['product_id' => $this->agua->id, 'unit_id' => $this->unitId('Litro'), 'quantity' => 6]],
                'affect_stock' => true,
            ])->assertCreated();

        $row = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/production-orders')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Abrir cocos', $row['title']);
        $this->assertSame('process', $row['kind']);
        $this->assertSame('Registrada', $row['status_label']);
        $this->assertSame('Coco entero', $row['consumptions'][0]['product_name']);
        $this->assertSame('pz', $row['consumptions'][0]['unit_abbreviation']);
        $this->assertSame('Agua de coco (granel)', $row['outputs'][0]['product_name']);
        $this->assertEquals(0.6, $row['yields'][0]['per_input']);
    }

    public function test_same_kind_quantities_still_give_a_waste_percentage(): void
    {
        // Pulpa (kg) → rallado (kg): ahí sí se puede hablar de merma.
        $rallado = $this->createProduct(['name' => 'Coco rallado (granel)'], 0, 'Kilogramo', InventoryType::SEMI_FINISHED);
        $this->setStock($this->pulpa, $this->location, 20);

        $order = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'production_date' => now()->toDateString(),
                'consumptions' => [['product_id' => $this->pulpa->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 10]],
                'outputs' => [['product_id' => $rallado->id, 'unit_id' => $this->unitId('Gramo'), 'quantity' => 4500]],
                'affect_stock' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertEquals(55.0, $order['waste_percentage']);
        $this->assertEquals(10, $order['total_consumed_quantity']);
        $this->assertEquals(4.5, $order['total_produced_quantity']);
        $this->assertSame('Producción', $order['title'], 'sin proceso, se llama "Producción"');
    }
}
