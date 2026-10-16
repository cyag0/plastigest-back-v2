<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\KioskOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Pedidos del kiosko en el punto de venta: la lista de los que esperan pago,
 * cobrarlos con pos/sales + kiosk_order_id (la venta normal del POS, ligada
 * al pedido en la misma transacción que libera lo apartado) y cancelarlos
 * cuando el cliente se fue sin pagar.
 */
class VentasCajaKioskPosTest extends KioskTestCase
{
    private function kioskOrders(User $user, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user, $location)->getJson('/api/auth/admin/pos/kiosk-orders');
    }

    private function cancelKioskOrder(User $user, int $id, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user, $location)->postJson("/api/auth/admin/pos/kiosk-orders/{$id}/cancel");
    }

    public function test_the_list_only_has_the_pending_kiosk_orders_of_the_branch(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 100);
        $branch = $this->createBranch();
        $this->setStock($coco, $branch, 100);

        $first = $this->placeKioskOrder([$this->item($coco, 2)], null, 'Juan');
        $this->travel(5)->minutes();
        $second = $this->placeKioskOrder([$this->item($coco, 1)]);
        $cancelled = $this->placeKioskOrder([$this->item($coco, 1)]);
        $charged = $this->placeKioskOrder([$this->item($coco, 1)]);
        $this->placeKioskOrder([$this->item($coco, 1)], $branch);
        // Un pedido normal pendiente y uno de canal kiosk sin número (el cobro
        // rápido anterior): no son pedidos del kiosko.
        $this->makeOrder($coco, 1, 30);
        $this->makeOrder($coco, 1, 30)->forceFill(['channel' => 'kiosk'])->save();

        $cashier = $this->cashier();
        $this->cancelKioskOrder($cashier, $cancelled)->assertOk();
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]], ['kiosk_order_id' => $charged])
            ->assertCreated();

        $response = $this->kioskOrders($cashier)->assertOk();

        $this->assertSame([$first, $second], collect($response->json('data'))->pluck('id')->all(), 'del más viejo al más nuevo');
        $order = SalesOrder::find($first);
        $this->assertSame(['id', 'ticket_number', 'order_number', 'customer_name', 'total', 'created_at', 'expires_at', 'items'], array_keys($response->json('data.0')));
        $this->assertEquals([
            'id' => $first,
            'ticket_number' => 1,
            'order_number' => $order->order_number,
            'customer_name' => 'Juan',
            'total' => 60,
            'created_at' => $order->created_at->toISOString(),
            'expires_at' => $order->created_at->copy()->addMinutes(120)->toISOString(),
            'items' => [[
                'product_id' => $coco->id, 'package_id' => null, 'name' => 'Coco entero', 'option' => 'Pieza',
                'quantity' => 2, 'unit_price' => 30, 'line_total' => 60,
            ]],
        ], $response->json('data.0'));

        // La otra sucursal ve solo el suyo.
        $this->assertCount(1, $this->kioskOrders($this->cashier([], false, $branch), $branch)->assertOk()->json('data'));
    }

    public function test_charging_a_kiosk_order_links_it_and_releases_its_reservation(): void
    {
        $water = $this->createProduct(['name' => 'Agua de coco', 'sale_price' => 65], 30, 'Litro');
        $twelve = $this->package($water, 12, 700);
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $orderId = $this->placeKioskOrder([$this->item($water, 2), $this->item($water, 1, $twelve)], null, 'Juan');
        $this->assertEquals(14, $this->reservedStock($water));
        $cashier = $this->cashier();
        $key = (string) Str::uuid();

        // El cajero cobra lo del pedido y agrega un coco en el mostrador.
        $cart = [
            ['product_id' => $water->id, 'quantity' => 2],
            ['product_id' => $water->id, 'package_id' => $twelve->id, 'quantity' => 1],
            ['product_id' => $coco->id, 'quantity' => 1],
        ];
        $cash = [['method' => 'cash', 'amount' => 860, 'tendered' => 1000]];
        $response = $this->posSale($cashier, $cart, $cash, ['kiosk_order_id' => $orderId, 'client_request_id' => $key])
            ->assertCreated()
            ->assertJsonPath('data.kiosk_order', ['id' => $orderId, 'ticket_number' => 1]);
        $this->assertEquals(860, $response->json('data.total'));
        $this->assertEquals(140, $response->json('data.change'));

        $sale = Sale::sole();
        $order = SalesOrder::find($orderId);
        $this->assertSame($sale->id, $order->sale_id);
        $this->assertSame('delivered', $order->status->value);
        $this->assertNotNull($order->delivered_at);
        $this->assertNull($order->reserved_at);
        $this->assertSame($cashier->id, $order->updated_by);
        $details = $order->details()->orderBy('id')->get();
        $this->assertEquals([2, 1], $details->map(fn($d) => (float) $d->delivered_quantity)->all());
        $this->assertEquals([0, 0], $details->map(fn($d) => (float) $d->reserved_quantity_base)->all());

        // Lo apartado se liberó y la existencia se descontó una sola vez.
        $this->assertEquals(0, $this->reservedStock($water));
        $this->assertEquals(16, $this->stockOf($water));
        $this->assertEquals(9, $this->stockOf($coco));

        // Una venta normal del punto de venta, con el pedido en content.
        $this->assertSame($cashier->id, $sale->user_id);
        $this->assertNotNull($sale->cash_register_session_id);
        $this->assertSame($key, $sale->client_request_id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(1, DB::table('cash_movements')->where('source_type', 'sale')->where('source_id', $sale->id)->count());
        $this->assertSame('kiosk', $sale->content['channel']);
        $this->assertSame($orderId, $sale->content['sales_order_id']);
        $this->assertSame($order->order_number, $sale->content['sales_order_number']);
        $this->assertSame(1, $sale->content['ticket_number']);
        $this->assertSame('Juan', $sale->content['customer_name']);
        $this->assertSame('cash', $sale->content['payment_method']);

        // El reintento con la misma llave devuelve la misma venta, igual que la consulta por llave.
        $replay = $this->posSale($cashier, $cart, $cash, ['kiosk_order_id' => $orderId, 'client_request_id' => $key])->assertOk();
        $this->assertSame($response->json(), $replay->json());
        $this->actingInBranch($cashier)->getJson("/api/auth/admin/pos/sales/request/{$key}")
            ->assertOk()
            ->assertJsonPath('data.kiosk_order', ['id' => $orderId, 'ticket_number' => 1]);

        // Dos envíos con la misma llave a la vez: el segundo espera al primero
        // con el pedido bloqueado y responde la venta que este dejó ligada.
        $shift = CashRegisterSession::where('user_id', $cashier->id)->sole();
        [$again, $created] = app(KioskOrderService::class)
            ->chargeAtPos($orderId, $shift, $key, fn() => $this->fail('No debe cobrar otra vez.'));
        $this->assertFalse($created);
        $this->assertSame($sale->id, $again->id);

        // Cobrarlo otra vez (otro cajero, otra llave): 422 y nada se mueve.
        $this->posSale($this->cashier(), [['product_id' => $water->id, 'quantity' => 2]], [['method' => 'card', 'amount' => 130]], [
            'kiosk_order_id' => $orderId,
            'client_request_id' => (string) Str::uuid(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'kiosk_order_unavailable')
            ->assertJsonPath('message', 'Ese pedido ya se cobró o se canceló.');
        $this->assertSame(1, Sale::count());
        $this->assertEquals(16, $this->stockOf($water));
        $this->kioskOrders($cashier)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_its_own_reservation_does_not_block_the_charge(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 3);
        $orderId = $this->placeKioskOrder([$this->item($coco, 3)]);
        $cashier = $this->cashier();

        // Sin ligar el pedido no se puede vender lo apartado.
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 3]], [['method' => 'card', 'amount' => 90]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');

        $response = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 3]], [['method' => 'card', 'amount' => 90]], ['kiosk_order_id' => $orderId])
            ->assertCreated();
        $this->assertEquals(0, $this->stockOf($coco));
        $this->assertEquals(0, $this->reservedStock($coco));

        // Una venta sin pedido no trae kiosk_order.
        $water = $this->createProduct(['sale_price' => 65], 5);
        $plain = $this->posSale($cashier, [['product_id' => $water->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 65]])->assertCreated();
        $this->assertArrayNotHasKey('kiosk_order', $plain->json('data'));
        $this->assertArrayHasKey('kiosk_order', $response->json('data'));
    }

    public function test_a_failed_charge_keeps_the_order_and_its_reservation(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 4);
        $orderId = $this->placeKioskOrder([$this->item($coco, 2)]);
        $this->placeKioskOrder([$this->item($coco, 1)]);
        $cashier = $this->cashier();

        // Lo del pedido (2) más lo libre (1) son 3: no alcanzan 4.
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 4]], [['method' => 'card', 'amount' => 120]], ['kiosk_order_id' => $orderId])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');

        $order = SalesOrder::find($orderId);
        $this->assertSame('pending', $order->status->value);
        $this->assertNull($order->sale_id);
        $this->assertNotNull($order->reserved_at);
        $this->assertEquals(2, $order->details()->sum('reserved_quantity_base'));
        $this->assertEquals(3, $this->reservedStock($coco), 'vuelve lo apartado');
        $this->assertEquals(4, $this->stockOf($coco));
        $this->assertSame(0, Sale::count());

        // Con 3 sí: el otro pedido conserva su apartado.
        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 3]], [['method' => 'card', 'amount' => 90]], ['kiosk_order_id' => $orderId])
            ->assertCreated();
        $this->assertEquals(1, $this->reservedStock($coco));
        $this->assertEquals(1, $this->stockOf($coco));
    }

    public function test_an_order_of_another_branch_or_not_from_the_kiosk_is_unavailable(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $branch = $this->createBranch();
        $this->setStock($coco, $branch, 10);
        $orderId = $this->placeKioskOrder([$this->item($coco, 2)], $branch);
        $cashier = $this->cashier();
        $charge = fn(int $id) => $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 2]], [['method' => 'card', 'amount' => 60]], ['kiosk_order_id' => $id]);

        $charge($orderId)
            ->assertStatus(422)
            ->assertJsonPath('code', 'kiosk_order_unavailable')
            ->assertJsonPath('message', 'Ese pedido es de otra sucursal.');
        $this->cancelKioskOrder($cashier, $orderId)
            ->assertStatus(422)
            ->assertJsonPath('code', 'kiosk_order_unavailable');

        $this->assertSame(0, Sale::count());
        $this->assertSame('pending', SalesOrder::find($orderId)->status->value);
        $this->assertEquals(2, $this->reservedStock($coco, $branch));
        $this->assertEquals(10, $this->stockOf($coco));

        // Un pedido que no es del kiosko, o que no existe, tampoco se cobra así.
        $phoneOrder = $this->makeOrder($coco, 2, 30);
        $charge($phoneOrder->id)->assertStatus(422)->assertJsonPath('code', 'kiosk_order_unavailable');
        $charge(999999)->assertStatus(422)->assertJsonPath('code', 'kiosk_order_unavailable');
        $this->cancelKioskOrder($cashier, $phoneOrder->id)->assertNotFound();
        $this->assertSame('pending', $phoneOrder->fresh()->status->value);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_cancelling_releases_the_reservation(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $orderId = $this->placeKioskOrder([$this->item($coco, 4)]);
        $this->assertEquals(4, $this->reservedStock($coco));
        // Cancelar no necesita turno abierto.
        $cashier = $this->cashier([], false);

        $this->cancelKioskOrder($cashier, $orderId)
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $orderId, 'status' => 'cancelled']]);

        $order = SalesOrder::find($orderId);
        $this->assertSame('cancelled', $order->status->value);
        $this->assertNotNull($order->cancelled_at);
        $this->assertNull($order->reserved_at);
        $this->assertSame('pos', $order->content['cancel_reason']);
        $this->assertSame($cashier->id, $order->updated_by);
        $this->assertEquals(0, $order->details()->sum('reserved_quantity_base'));
        $this->assertEquals(0, $this->reservedStock($coco));
        $this->assertEquals(10, $this->stockOf($coco));

        // Ya no se cancela ni se cobra otra vez.
        $this->cancelKioskOrder($cashier, $orderId)
            ->assertStatus(422)
            ->assertJsonPath('code', 'kiosk_order_unavailable')
            ->assertJsonPath('message', 'Ese pedido ya se cobró o se canceló.');
        $this->posSale($this->cashier(), [['product_id' => $coco->id, 'quantity' => 4]], [['method' => 'card', 'amount' => 120]], ['kiosk_order_id' => $orderId])
            ->assertStatus(422)
            ->assertJsonPath('code', 'kiosk_order_unavailable');
        $this->assertEquals(0, $this->reservedStock($coco));
        $this->assertSame(0, Sale::count());

        $this->cancelKioskOrder($cashier, 999999)->assertNotFound()->assertJsonPath('message', 'Pedido no encontrado');
    }

    public function test_it_needs_sales_pos(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $orderId = $this->placeKioskOrder([$this->item($coco, 2)]);
        $orderTaker = $this->createBranchUser($this->location, ['sales_orders_list', 'sales_orders_read', 'sales_orders_create', 'sales_orders_update'])->fresh();

        foreach ([$this->kioskUser(), $orderTaker] as $user) {
            $this->kioskOrders($user)->assertForbidden();
            $this->cancelKioskOrder($user, $orderId)->assertForbidden();
            $this->actingInBranch($user)->postJson('/api/auth/admin/pos/sales', [
                'details' => [['product_id' => $coco->id, 'quantity' => 2]],
                'payments' => [['method' => 'card', 'amount' => 60]],
                'kiosk_order_id' => $orderId,
            ])->assertForbidden();
        }

        $this->assertSame('pending', SalesOrder::find($orderId)->status->value);
        $this->assertEquals(2, $this->reservedStock($coco));
    }
}
