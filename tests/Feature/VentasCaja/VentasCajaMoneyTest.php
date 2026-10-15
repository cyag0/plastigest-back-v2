<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Sale;
use App\Models\SalesOrder;
use App\Services\SalesOrderStockService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Importes en centavos enteros (contrato C2): el servidor calcula los
 * renglones y el total igual que la app (utils/money.ts), así que un cobro de
 * producto pesado ya no se rechaza por un centavo, en PHP 8.2 o en 8.4.
 */
class VentasCajaMoneyTest extends VentasCajaTestCase
{
    public function test_the_integer_algorithm_rounds_each_line_half_up(): void
    {
        $this->assertSame(6223, Money::line('2.489', '25')['total']);
        $this->assertSame(1828, Money::line('0.215', '85')['total']);
        $this->assertSame(683, Money::line('0.105', '65')['total']);
        // Mitades que en binario quedan a un pelo de .5: se toma el decimal escrito.
        $this->assertSame(101, Money::toCents(1.005));
        $this->assertSame(2489, Money::toMilli(2.489));
        $this->assertSame(['subtotal' => 3000, 'discount' => 500, 'total' => 2500], Money::line(2, 15, 5));
        $this->assertSame(62.23, Money::fromCents(6223));
    }

    public function test_pos_charges_weighed_products_with_the_same_total_as_the_app(): void
    {
        $pulpa = $this->createProduct(['name' => 'Pulpa de coco', 'sale_price' => 25], 10, 'Kilogramo');
        $agua = $this->createProduct(['name' => 'Agua de coco', 'sale_price' => 85], 10, 'Litro');
        $cashier = $this->cashier();

        // 2.489 kg × $25 = 62.2250 → 62.23 (antes: la app cobraba 62.22 y el servidor pedía 62.23).
        $this->posSale($cashier, [['product_id' => $pulpa->id, 'quantity' => 2.489]], [['method' => 'cash', 'amount' => 62.23, 'tendered' => 100]])
            ->assertCreated()
            ->assertJsonPath('data.total', 62.23)
            ->assertJsonPath('data.change', 37.77);

        // 0.215 L × $85 = 18.275 → 18.28.
        $this->posSale($cashier, [['product_id' => $agua->id, 'quantity' => '0.215']], [['method' => 'card', 'amount' => '18.28']])
            ->assertCreated()
            ->assertJsonPath('data.total', 18.28);

        // Un centavo de menos sigue sin cuadrar.
        $this->posSale($cashier, [['product_id' => $agua->id, 'quantity' => '0.215']], [['method' => 'card', 'amount' => '18.27']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments');
    }

    public function test_pos_mixed_cart_total_is_the_sum_of_rounded_lines(): void
    {
        $agua = $this->createProduct(['sale_price' => 85], 10, 'Litro');
        $leche = $this->createProduct(['sale_price' => 65], 10, 'Litro');
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();

        // 18.28 + 6.83 + 60 = 85.11 (sin redondear por renglón daba 85.10).
        $saleId = $this->posSale($cashier, [
            ['product_id' => $agua->id, 'quantity' => 0.215],
            ['product_id' => $leche->id, 'quantity' => 0.105],
            ['product_id' => $coco->id, 'quantity' => 2],
        ], [['method' => 'cash', 'amount' => 50, 'tendered' => 50], ['method' => 'card', 'amount' => 35.11]])
            ->assertCreated()
            ->assertJsonPath('data.total', 85.11)
            ->json('data.id');

        $sale = Sale::with('details')->findOrFail($saleId);
        $this->assertEqualsCanonicalizing(['18.28', '6.83', '60.00'], $sale->details->pluck('total')->all());
        $this->assertSame('85.11', $sale->total);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_pos_discount_is_subtracted_in_cents(): void
    {
        $pulpa = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');
        $cashier = $this->cashier(['sales_discount']);

        $this->posSale($cashier, [['product_id' => $pulpa->id, 'quantity' => 2.489, 'discount' => 2.23]], [['method' => 'card', 'amount' => 60]])
            ->assertCreated()
            ->assertJsonPath('data.total', 60)
            ->assertJsonPath('data.discount', 2.23);
    }

    public function test_old_sales_screen_uses_the_same_rounding(): void
    {
        $pulpa = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');
        $cashier = $this->cashier(['sales_create']);

        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'details' => [$this->legacyLine($pulpa, '2.489', 25)],
        ])->assertCreated()->json('data.id');

        $sale = Sale::findOrFail($saleId);
        $this->assertSame('62.23', $sale->total);
        $this->assertSame('62.23', $sale->paid_amount, 'sin monto se cobra el total exacto');
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_sales_order_total_matches_its_rounded_lines_and_checkout_charges_it(): void
    {
        $pulpa = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');
        $agua = $this->createProduct(['sale_price' => 85], 10, 'Litro');

        $orderId = $this->actingInBranch($this->user)->postJson('/api/auth/admin/sales-orders', [
            'channel' => 'kiosk',
            'service_mode' => 'counter',
            'details' => [$this->orderLine($pulpa, '2.489'), $this->orderLine($agua, '0.215')],
        ])->assertSuccessful()->json('data.id');

        $order = SalesOrder::with('details')->findOrFail($orderId);
        $this->assertEqualsCanonicalizing(['62.23', '18.28'], $order->details->pluck('line_total')->all());
        $this->assertSame('80.51', $order->total_amount, 'antes: 80.50 (suma de flotantes sin redondear)');

        $sale = app(SalesOrderStockService::class)->checkoutOrder($order, ['payment_method' => 'card', 'paid_amount' => 80.51]);

        $this->assertSame('80.51', $sale->total);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertEquals(80.51, (float) DB::table('sale_payments')->where('sale_id', $sale->id)->value('amount'));
    }
}
