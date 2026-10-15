<?php

namespace Tests\Feature\VentasCaja;

use App\Models\ProductPackage;
use App\Models\Sale;
use App\Models\SalesOrder;

/**
 * Lo apartado para pedidos no se vende, y la falta de stock es un 422 con el
 * motivo (contrato C3), en el punto de venta y al cobrar un pedido.
 *
 * Un pedido aparta su mercancía al pasar a "preparando"
 * (product_location.reserved_stock). Antes el punto de venta validaba solo
 * contra la existencia y podía vender lo que ya estaba apartado: el pedido
 * después no se podía cobrar.
 */
class VentasCajaReservedStockTest extends VentasCajaTestCase
{
    public function test_pos_cannot_sell_stock_reserved_for_an_order(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $this->setReserved($coco, 8);
        $cashier = $this->cashier();

        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 3]], [['method' => 'card', 'amount' => 90]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 2 Pieza, solicitado: 3 Pieza.')
            ->assertJsonPath('details.available', 2)
            ->assertJsonPath('details.requested', 3);
        $this->assertEquals(10, $this->stockOf($coco));
        $this->assertSame(0, Sale::count());

        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 2]], [['method' => 'card', 'amount' => 60]])
            ->assertCreated();
        $this->assertEquals(8, $this->stockOf($coco));
        $this->assertEquals(8, $this->reservedStock($coco), 'lo apartado sigue apartado');
    }

    public function test_package_and_loose_units_of_a_product_add_up(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $pack = ProductPackage::create([
            'product_id' => $coco->id, 'company_id' => $this->company->id, 'unit_id' => $coco->unit_id,
            'package_name' => 'Red 4', 'barcode' => 'RED4', 'quantity_per_package' => 4, 'sale_price' => 110, 'is_active' => true,
        ]);
        $this->setReserved($coco, 5);
        $cashier = $this->cashier();

        // 4 del paquete + 2 sueltas = 6 > 5 disponibles.
        $this->posSale($cashier, [
            ['product_id' => $coco->id, 'package_id' => $pack->id, 'quantity' => 1],
            ['product_id' => $coco->id, 'quantity' => 2],
        ], [['method' => 'card', 'amount' => 170]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('details.available', 5)
            ->assertJsonPath('details.requested', 6);
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_pos_catalog_shows_what_is_left_after_reservations(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $pack = ProductPackage::create([
            'product_id' => $coco->id, 'company_id' => $this->company->id, 'unit_id' => $coco->unit_id,
            'package_name' => 'Par', 'barcode' => 'PAR', 'quantity_per_package' => 2, 'sale_price' => 55, 'is_active' => true,
        ]);
        $this->setReserved($coco, 4);
        $cashier = $this->cashier();

        $items = collect($this->actingInBranch($cashier)->getJson('/api/auth/admin/pos/catalog')->assertOk()->json('data'))->keyBy('key');

        $this->assertEquals(6, $items["p{$coco->id}"]['stock']);
        $this->assertEquals(3, $items["k{$pack->id}"]['stock']);
    }

    public function test_a_prepared_order_is_charged_from_its_own_reservation(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        // A domicilio: se prepara (y aparta) antes de salir.
        $order = $this->makeOrder($coco, 6, 30, 'pending', 'delivery');
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/prepare")->assertOk();
        $this->assertEquals(6, $this->reservedStock($coco));

        // El mostrador vende lo que no está apartado...
        $cashier = $this->cashier();
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 4]], [['method' => 'card', 'amount' => 120]])
            ->assertCreated();
        // ...y ni una pieza más.
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');

        // El pedido se cobra con lo que tenía apartado.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'card'])
            ->assertOk();

        $this->assertEquals(0, $this->stockOf($coco));
        $this->assertEquals(0, $this->reservedStock($coco));
        $this->assertNotNull($order->fresh()->sale_id);
    }

    public function test_order_checkout_without_stock_is_a_422_with_the_reason(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $order = $this->makeOrder($coco, 5, 30);
        $this->setStock($coco, $this->location, 2);

        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'card'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 2 Pieza, solicitado: 5 Pieza.');

        $this->assertNull($order->fresh()->sale_id);
        $this->assertSame(SalesOrder::find($order->id)->status->value, 'pending');
        $this->assertEquals(2, $this->stockOf($coco));
        $this->assertSame(0, Sale::count());
    }
}
