<?php

namespace Tests\Feature\Logic;

use App\Models\InventoryType;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderPackaging;
use App\Models\Product;
use App\Services\ProductionOrderService;
use App\Services\ProductionPackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Producción y empaque: las transiciones que mueven stock deciden con la orden
 * bloqueada, y el empaque no puede consumir más granel del que produjo la orden.
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
    private function draftOrder(): int
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/production-orders', [
                'production_date' => now()->toDateString(),
                'responsible_user_id' => $this->user->id,
                'consumptions' => [['product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 14]],
                'outputs' => [['product_id' => $this->granel->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 10]],
            ])->assertCreated()->json('data.id');
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

    private function pack(int $orderId, array $packagings)
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/production-orders/{$orderId}/packaging", ['packagings' => $packagings]);
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

    public function test_packaging_cannot_consume_more_bulk_than_the_order_produced(): void
    {
        $id = $this->completedOrder();
        $jar = $this->jar();

        // 41 frascos = 10.25 kg: la orden produjo 10 kg aunque la sucursal tenga 40.
        $this->pack($id, [['product_id' => $jar->id, 'quantity' => 41]])->assertStatus(422);
        $this->assertEquals(40, $this->stockOf($this->granel));
        $this->assertEquals(0, $this->stockOf($jar));

        // 40 frascos = 10 kg: justo lo producido.
        $this->pack($id, [['product_id' => $jar->id, 'quantity' => 40]])->assertOk();
        $this->assertEquals(30, $this->stockOf($this->granel));
        $this->assertEquals(40, $this->stockOf($jar));

        // Ya no queda granel de esta orden.
        $this->pack($id, [['product_id' => $jar->id, 'quantity' => 1]])->assertStatus(422);
        $this->assertEquals(30, $this->stockOf($this->granel));
    }

    public function test_packaging_scales_the_recipe_by_the_unit_packed(): void
    {
        $id = $this->completedOrder();
        $jar = $this->jar();

        // 2 docenas = 24 frascos = 6 kg de granel (antes descontaba 0.5 kg).
        $this->pack($id, [['product_id' => $jar->id, 'quantity' => 2, 'unit_id' => $this->unitId('Docena')]])->assertOk();

        $this->assertEquals(24, $this->stockOf($jar));
        $this->assertEqualsWithDelta(34, $this->stockOf($this->granel), 0.0001);
    }

    public function test_packaging_rejects_an_empty_recipe_or_a_bulk_this_order_did_not_produce(): void
    {
        $id = $this->completedOrder();

        // Receta en cero: daba de alta terminado sin consumir nada.
        $noBulk = $this->jar(['name' => 'Sin receta', 'packaging_source_quantity' => 0]);
        $this->pack($id, [['product_id' => $noBulk->id, 'quantity' => 10]])->assertStatus(422);
        $this->pack($id, [['product_id' => $this->jar()->id, 'quantity' => 10, 'source_quantity_per_unit' => 0]])->assertStatus(422);

        // Producto que se empaca de un granel que esta orden no produjo.
        $otherBulk = $this->jar(['name' => 'De otro granel', 'packaging_source_product_id' => $this->coco->id]);
        $this->pack($id, [['product_id' => $otherBulk->id, 'quantity' => 1]])->assertStatus(422);

        $this->assertEquals(0, $this->stockOf($noBulk));
        $this->assertEquals(0, $this->stockOf($otherBulk));
        $this->assertEquals(40, $this->stockOf($this->granel));
        $this->assertEquals(36, $this->stockOf($this->coco));
    }

    public function test_reverting_a_stale_packaging_does_not_return_stock_twice(): void
    {
        $id = $this->completedOrder();
        $jar = $this->jar();
        $this->pack($id, [['product_id' => $jar->id, 'quantity' => 8]])->assertOk();

        $packaging = ProductionOrderPackaging::where('production_order_id', $id)->firstOrFail();
        $stale = ProductionOrderPackaging::with('materials')->findOrFail($packaging->id);

        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/production-orders/{$id}/packaging/{$packaging->id}")
            ->assertOk();
        $this->assertEquals(0, $this->stockOf($jar));
        $this->assertEquals(40, $this->stockOf($this->granel));

        try {
            app(ProductionPackagingService::class)->revert(ProductionOrder::findOrFail($id), $stale);
            $this->fail('Revertir el mismo empaque dos veces debe rechazarse.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertEquals(0, $this->stockOf($jar));
        $this->assertEquals(40, $this->stockOf($this->granel), 'el granel se devuelve una sola vez');
    }

    public function test_today_stats_include_orders_being_packed(): void
    {
        $id = $this->completedOrder();
        $this->pack($id, [['product_id' => $this->jar()->id, 'quantity' => 4]])->assertOk();
        $this->assertSame('packaging', ProductionOrder::findOrFail($id)->status->value);

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/production-orders/today-stats')
            ->assertOk()
            ->assertJsonPath('data.productions_count_today', 1);
    }
}
