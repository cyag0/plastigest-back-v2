<?php

namespace Tests\Feature\Logic;

use App\Models\InventoryType;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderPackaging;
use App\Models\Product;
use App\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Producción y envasado: las transiciones que mueven stock deciden con la orden
 * bloqueada; el envasado de una producción no puede consumir más granel del
 * que produjo la orden, y el de después, más del que hay en la sucursal.
 *
 * Los casos "stale" reproducen la carrera de dos peticiones simultáneas: la
 * segunda trae una instancia cargada antes de que la primera terminara.
 */
class ProductionLockingTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private Product $coco;
    private Product $granel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // El usuario se relee para traer las columnas con default (is_active):
        // el middleware rechaza cuentas inactivas.
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);

        $this->coco = $this->createProduct([], 50);
        // Granel en kg; la sucursal ya tiene 30 kg de producciones anteriores.
        $this->granel = $this->createProduct(['name' => 'Dulce de coco granel'], 30, 'Kilogramo', InventoryType::FINISHED_PRODUCT);
    }

    /** Orden que consume 14 cocos y produce 10 kg de granel. */
    private function draftOrder(array $overrides = []): int
    {
        return $this->register($overrides + ['affect_stock' => false])->assertCreated()->json('data.id');
    }

    /** Registra (o guarda pendiente) la orden de 14 cocos → 10 kg. */
    private function register(array $overrides = [])
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', $overrides + [
                'production_date' => now()->toDateString(),
                'responsible_user_id' => $this->user->id,
                'consumptions' => [['product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 14]],
                'outputs' => [['product_id' => $this->granel->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 10]],
                'affect_stock' => true,
            ]);
    }

    /** Envasado posterior, con el granel del inventario. */
    private function packLater(array $packagings)
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'kind' => ProductionOrder::KIND_PACKAGING,
                'production_date' => now()->toDateString(),
                'packagings' => $packagings,
                'affect_stock' => true,
            ]);
    }

    private function completedOrder(): int
    {
        $id = $this->draftOrder();
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$id}/complete")
            ->assertOk();

        return $id;
    }

    /** Frasco de 250 g que se empaca del granel. */
    private function jar(array $attributes = []): Product
    {
        return $this->createProduct(array_merge([
            'name' => 'Dulce de coco 250 g',
            'requires_packaging' => true,
            'packaging_source_product_id' => $this->granel->id,
            'packaging_source_quantity' => 0.25,
            'packaging_source_unit_id' => $this->unitId('Kilogramo'),
        ], $attributes), 0, 'Pieza', InventoryType::FINISHED_PRODUCT);
    }


    public function test_completing_a_stale_order_does_not_move_stock_twice(): void
    {
        $id = $this->draftOrder();
        $stale = ProductionOrder::findOrFail($id); // la "segunda petición", cargada en borrador

        app(ProductionOrderService::class)->complete(ProductionOrder::findOrFail($id));
        $this->assertEquals(36, $this->stockOf($this->coco));
        $this->assertEquals(40, $this->stockOf($this->granel));

        try {
            app(ProductionOrderService::class)->complete($stale);
            $this->fail('Completar dos veces debe rechazarse.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertEquals(36, $this->stockOf($this->coco), 'los insumos se descuentan una sola vez');
        $this->assertEquals(40, $this->stockOf($this->granel), 'el granel se suma una sola vez');
    }

    public function test_cancelling_a_stale_order_does_not_revert_stock_twice(): void
    {
        $id = $this->completedOrder();
        $stale = ProductionOrder::findOrFail($id); // cargada como completada

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$id}/cancel")
            ->assertOk();
        $this->assertEquals(50, $this->stockOf($this->coco));
        $this->assertEquals(30, $this->stockOf($this->granel));

        try {
            app(ProductionOrderService::class)->cancel($stale);
            $this->fail('Cancelar dos veces debe rechazarse.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertEquals(50, $this->stockOf($this->coco));
        $this->assertEquals(30, $this->stockOf($this->granel));
    }

    public function test_packaging_with_the_production_cannot_use_more_bulk_than_it_produced(): void
    {
        $jar = $this->jar();

        // 41 frascos = 10.25 kg: la orden produce 10 kg aunque la sucursal tenga 30.
        $this->register(['packagings' => [['product_id' => $jar->id, 'quantity' => 41]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_bulk')
            ->assertJsonPath('message', 'No alcanza Dulce de coco granel: salieron 10 kg y se quieren envasar 10.25 kg.');
        $this->assertEquals(50, $this->stockOf($this->coco), 'no se movió nada');
        $this->assertEquals(30, $this->stockOf($this->granel));
        $this->assertSame(0, ProductionOrder::count());

        // 40 frascos = 10 kg: justo lo producido, en el mismo registro.
        $id = $this->register(['packagings' => [['product_id' => $jar->id, 'quantity' => 40]]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.packagings.0.quantity', 40)
            ->json('data.id');
        $this->assertEquals(36, $this->stockOf($this->coco));
        $this->assertEquals(30, $this->stockOf($this->granel), 'el granel producido se envasó completo');
        $this->assertEquals(40, $this->stockOf($jar));
        $this->assertSame('completed', ProductionOrder::findOrFail($id)->status->value);
    }

    public function test_packaging_later_uses_the_bulk_in_stock(): void
    {
        $jar = $this->jar();

        // La sucursal tiene 30 kg de antes: alcanza para 120 frascos, no 121.
        $this->packLater([['product_id' => $jar->id, 'quantity' => 121]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_bulk')
            ->assertJsonPath('message', 'No alcanza Dulce de coco granel: hay 30 kg y se quieren envasar 30.25 kg.');

        $order = $this->packLater([['product_id' => $jar->id, 'quantity' => 120]])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'packaging')
            ->assertJsonPath('data.title', 'Envasado')
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertNull($order['formula_id']);
        $this->assertSame([], $order['consumptions']);
        $this->assertSame([], $order['outputs']);
        $this->assertEquals(0, $this->stockOf($this->granel));
        $this->assertEquals(120, $this->stockOf($jar));
    }

    public function test_packaging_scales_the_recipe_by_the_unit_packed(): void
    {
        $jar = $this->jar();

        // 2 docenas = 24 frascos = 6 kg de granel (antes descontaba 0.5 kg).
        $this->register(['packagings' => [['product_id' => $jar->id, 'quantity' => 2, 'unit_id' => $this->unitId('Docena')]]])
            ->assertCreated();

        $this->assertEquals(24, $this->stockOf($jar));
        $this->assertEqualsWithDelta(34, $this->stockOf($this->granel), 0.0001);
    }

    public function test_packaging_rejects_an_empty_recipe_or_a_bulk_this_order_did_not_produce(): void
    {
        // Receta en cero: daba de alta terminado sin consumir nada.
        $noBulk = $this->jar(['name' => 'Sin receta', 'packaging_source_quantity' => 0]);
        $this->register(['packagings' => [['product_id' => $noBulk->id, 'quantity' => 10]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'packaging_recipe_missing');

        // Un producto que no se envasa.
        $this->register(['packagings' => [['product_id' => $this->coco->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'packaging_recipe_missing');

        // Se envasa de un granel que esta orden no produjo: eso va en «Envasar».
        $otherBulk = $this->jar(['name' => 'De otro granel', 'packaging_source_product_id' => $this->coco->id]);
        $this->register(['packagings' => [['product_id' => $otherBulk->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'packaging_source_invalid');

        $this->assertSame(0, ProductionOrder::count());
        $this->assertEquals(0, $this->stockOf($noBulk));
        $this->assertEquals(0, $this->stockOf($otherBulk));
        $this->assertEquals(30, $this->stockOf($this->granel));
        $this->assertEquals(50, $this->stockOf($this->coco));
    }

    public function test_packaging_material_shared_by_two_presentations_is_checked_together(): void
    {
        $cap = $this->createProduct(['name' => 'Tapa'], 10, 'Pieza', InventoryType::PACKAGING_MATERIAL);
        $big = $this->jar(['name' => 'Frasco 500 g', 'packaging_source_quantity' => 0.5]);
        $small = $this->jar(['name' => 'Frasco 250 g']);
        foreach ([$big, $small] as $presentation) {
            $presentation->packagingMaterials()->create([
                'material_product_id' => $cap->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 1,
            ]);
        }

        // 6 + 6 = 12 tapas y sólo hay 10, aunque cada línea sola alcanzaría.
        $this->register(['packagings' => [
            ['product_id' => $big->id, 'quantity' => 6],
            ['product_id' => $small->id, 'quantity' => 6],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_material')
            ->assertJsonPath('message', 'No alcanza Tapa: hay 10 y se necesitan 12.');

        $this->register(['packagings' => [
            ['product_id' => $big->id, 'quantity' => 6],
            ['product_id' => $small->id, 'quantity' => 4],
        ]])->assertCreated();

        $this->assertEquals(0, $this->stockOf($cap));
        $this->assertEquals(6, $this->stockOf($big));
        $this->assertEquals(4, $this->stockOf($small));
        $this->assertEqualsWithDelta(36, $this->stockOf($this->granel), 0.0001, '30 + 10 producidos − 3 − 1 envasados');
    }

    public function test_a_pending_order_keeps_its_packaging_until_it_is_registered(): void
    {
        $jar = $this->jar();
        $id = $this->draftOrder(['packagings' => [['product_id' => $jar->id, 'quantity' => 8]]]);

        $this->withHeaders($this->tenantHeaders())
            ->getJson("/api/auth/admin/production-orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_label', 'Pendiente')
            ->assertJsonPath('data.planned_packagings.0.product_name', 'Dulce de coco 250 g')
            ->assertJsonPath('data.planned_packagings.0.quantity', 8);
        $this->assertEquals(0, $this->stockOf($jar), 'un pendiente no mueve stock');

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$id}/complete")
            ->assertOk();

        $order = ProductionOrder::findOrFail($id);
        $this->assertNull($order->planned_packagings);
        $this->assertSame(1, $order->packagings()->count());
        $this->assertEquals(8, $this->stockOf($jar));
        $this->assertEquals(38, $this->stockOf($this->granel), '30 + 10 producidos − 2 envasados');
    }

    public function test_cancelling_returns_the_packaging_the_leftover_waste_and_the_production(): void
    {
        $jar = $this->jar();
        $id = $this->register([
            'packagings' => [['product_id' => $jar->id, 'quantity' => 36]],
            // Sobraron 1 kg y se tiró: es merma del granel que salió.
            'wastes' => [['product_id' => $this->granel->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 1, 'reason' => 'process_loss']],
        ])->assertCreated()->json('data.id');
        $this->setStock($this->granel, $this->location, 0); // ya no hay granel de antes
        $this->assertEquals(36, $this->stockOf($jar));

        $stale = ProductionOrder::findOrFail($id);
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$id}/cancel")
            ->assertOk();

        // La merma vuelve antes de retirar los 10 kg producidos: si no, faltaba
        // 1 kg y la cancelación fallaba.
        $this->assertEquals(0, $this->stockOf($jar));
        $this->assertEquals(0, $this->stockOf($this->granel));
        $this->assertEquals(50, $this->stockOf($this->coco));
        $this->assertSame(0, ProductionOrderPackaging::count());

        try {
            app(ProductionOrderService::class)->cancel($stale);
            $this->fail('Cancelar dos veces debe rechazarse.');
        } catch (RuntimeException) {
            // esperado
        }
        $this->assertEquals(50, $this->stockOf($this->coco), 'se revierte una sola vez');
    }

    public function test_an_order_without_lines_is_not_registered(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'production_date' => now()->toDateString(),
                'affect_stock' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'nothing_to_register');

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'kind' => ProductionOrder::KIND_PACKAGING,
                'production_date' => now()->toDateString(),
                'affect_stock' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('packagings');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_today_stats_include_registered_orders_with_packaging(): void
    {
        $this->register(['packagings' => [['product_id' => $this->jar()->id, 'quantity' => 4]]])->assertCreated();

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/production-orders/today-stats')
            ->assertOk()
            ->assertJsonPath('data.productions_count_today', 1);
    }
}
