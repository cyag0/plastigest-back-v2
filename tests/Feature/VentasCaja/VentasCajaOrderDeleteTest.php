<?php

namespace Tests\Feature\VentasCaja;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;

/**
 * Borrar un pedido ya no deja mercancía apartada para siempre ni ventas
 * apuntando a un pedido inexistente: solo se borran pedidos pendientes o
 * cancelados y sin venta.
 */
class VentasCajaOrderDeleteTest extends VentasCajaTestCase
{
    private function deleteOrder(SalesOrder $order, $user = null)
    {
        return $this->actingInBranch($user ?? $this->user)->deleteJson("/api/auth/admin/sales-orders/{$order->id}");
    }

    public function test_a_pending_order_can_be_deleted(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $order = $this->makeOrder($coco, 2, 30);

        $this->deleteOrder($order)->assertOk();

        $this->assertNull(SalesOrder::find($order->id));
        $this->assertSame(0, SalesOrderDetail::where('sales_order_id', $order->id)->count());
    }

    public function test_an_order_with_reserved_stock_must_be_cancelled_first(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $order = $this->makeOrder($coco, 6, 30, 'pending', 'delivery');
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/prepare")->assertOk();
        $this->assertEquals(6, $this->reservedStock($coco));

        $this->deleteOrder($order)
            ->assertStatus(422)
            ->assertJsonPath('code', 'order_not_deletable');
        $this->assertNotNull(SalesOrder::find($order->id));
        $this->assertEquals(6, $this->reservedStock($coco), 'antes el apartado se quedaba para siempre');

        // Cancelar libera lo apartado; después sí se puede borrar.
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/cancel")->assertOk();
        $this->assertEquals(0, $this->reservedStock($coco));
        $this->deleteOrder($order)->assertOk();
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_a_charged_order_is_not_deleted(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $order = $this->makeOrder($coco, 2, 30);
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'card'])
            ->assertOk();

        $this->deleteOrder($order)
            ->assertStatus(422)
            ->assertJsonPath('code', 'order_not_deletable');
        $this->assertNotNull(SalesOrder::find($order->id));
    }

    public function test_an_order_of_another_branch_is_not_found(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $otherBranch = $this->createBranch();
        $order = $this->makeOrder($coco, 2, 30, 'pending', 'counter', $otherBranch);
        $clerk = $this->createBranchUser($this->location, ['sales_orders_delete', 'sales_orders_list'])->fresh();

        $this->deleteOrder($order, $clerk)->assertNotFound();
        $this->assertNotNull(SalesOrder::find($order->id));
    }
}
