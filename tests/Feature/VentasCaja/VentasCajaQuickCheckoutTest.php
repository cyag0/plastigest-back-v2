<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Sale;
use App\Models\SalesOrder;
use App\Services\SalesOrderStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cobro rápido del kiosco (contrato C13): POST sales-orders/quick-checkout
 * crea el pedido y lo cobra en una sola transacción. Antes eran dos llamadas:
 * si el cobro fallaba quedaba un pedido pendiente y el reintento creaba otro.
 */
class VentasCajaQuickCheckoutTest extends VentasCajaTestCase
{
    /** Quien atiende el kiosco: crea y cobra pedidos. */
    private const KIOSK = ['sales_orders_create', 'sales_orders_update', 'sales_orders_read', 'sales_orders_list', 'products_list'];

    private function quickCheckout($user, array $payload)
    {
        return $this->actingInBranch($user)->postJson('/api/auth/admin/sales-orders/quick-checkout', $payload);
    }

    private function kioskPayload($product, float $quantity, array $extra = []): array
    {
        return array_merge([
            'channel' => 'kiosk',
            'service_mode' => 'counter',
            'customer_id' => null,
            'details' => [[
                'product_id' => $product->id,
                'package_id' => null,
                'unit_id' => $product->unit_id,
                'requested_quantity' => $quantity,
                'unit_price' => (float) $product->sale_price,
            ]],
            'payment_method' => 'card',
            'paid_amount' => round($quantity * (float) $product->sale_price, 2),
            'client_request_id' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_it_creates_and_charges_the_order_in_one_call(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();
        $payload = $this->kioskPayload($coco, 2);

        $response = $this->quickCheckout($kiosk, $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('sale.total', '60.00');

        $order = SalesOrder::sole();
        $sale = Sale::sole();
        $response->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.sale_id', $sale->id)
            ->assertJsonPath('sale.id', $sale->id)
            ->assertJsonPath('sale.sale_number', $sale->sale_number);

        $this->assertSame($payload['client_request_id'], $order->client_request_id);
        $this->assertSame($payload['client_request_id'], $sale->client_request_id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertEquals(8, $this->stockOf($coco));
    }

    public function test_the_same_key_returns_the_charged_order_without_charging_again(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();
        $this->openShiftFor($kiosk);
        $payload = $this->kioskPayload($coco, 2, ['payment_method' => 'cash', 'paid_amount' => 100]);

        $first = $this->quickCheckout($kiosk, $payload)->assertCreated();
        $second = $this->quickCheckout($kiosk, $payload)->assertOk();

        $this->assertSame($first->json('sale'), $second->json('sale'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, DB::table('cash_movements')->where('source_type', 'sale')->count());
        $this->assertEquals(8, $this->stockOf($coco));
        // El cambio de un pago en efectivo queda registrado (100 recibidos, 40 de cambio).
        $this->assertEquals(40, Sale::sole()->payments()->sole()->change);
    }

    public function test_a_failed_charge_leaves_no_pending_order(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();
        $key = (string) Str::uuid();

        // Efectivo sin turno abierto: se rechaza y no queda pedido pendiente.
        $this->quickCheckout($kiosk, $this->kioskPayload($coco, 2, ['payment_method' => 'cash', 'paid_amount' => 60, 'client_request_id' => $key]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');
        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));

        // El reintento con la misma llave (ya con tarjeta) sí se cobra.
        $this->quickCheckout($kiosk, $this->kioskPayload($coco, 2, ['client_request_id' => $key]))->assertCreated();
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_insufficient_stock_is_a_422_with_its_code_and_creates_nothing(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 1);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();

        $this->quickCheckout($kiosk, $this->kioskPayload($coco, 3))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', fn($message) => str_starts_with($message, SalesOrderStockService::INSUFFICIENT_STOCK_PREFIX . 'Coco entero'));

        $this->assertSame(0, SalesOrder::count());
        $this->assertEquals(1, $this->stockOf($coco));
    }

    public function test_catalog_price_is_enforced_without_sales_discount(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();
        $payload = $this->kioskPayload($coco, 2);
        $payload['details'][0]['unit_price'] = 1;
        $payload['paid_amount'] = 2;

        $this->quickCheckout($kiosk, $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn($errors) => array_key_exists('details.0.unit_price', $errors));
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_it_needs_a_key_and_both_order_permissions(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();

        $payload = $this->kioskPayload($coco, 1);
        unset($payload['client_request_id']);
        $this->quickCheckout($kiosk, $payload)->assertStatus(422)->assertJsonValidationErrors('client_request_id');

        $creatorOnly = $this->createBranchUser($this->location, ['sales_orders_create', 'sales_orders_list'])->fresh();
        $this->quickCheckout($creatorOnly, $this->kioskPayload($coco, 1))->assertForbidden();

        $this->assertSame(0, SalesOrder::count());
    }
}
