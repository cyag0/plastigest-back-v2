<?php

namespace Tests\Feature\VentasCaja;

use App\Models\CashMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * Crédito y caja: una venta o un pedido a crédito nunca se registra como
 * cobrado ni como ingreso, y si el movimiento de caja no se puede guardar la
 * venta completa se revierte (antes el error se tragaba y la venta quedaba
 * sin su ingreso).
 */
class VentasCajaCreditAndCashIntegrityTest extends VentasCajaTestCase
{
    // ─── Crédito ─────────────────────────────────────────────────────────────

    public function test_order_checkout_on_credit_is_pending_and_never_income(): void
    {
        $coco = $this->createProduct([], 10);
        $order = $this->makeOrder($coco, 2, 190); // 380

        $saleId = $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'credit'])
            ->assertOk()
            ->json('sale.id');

        $sale = Sale::findOrFail($saleId);
        $this->assertSame('pending', $sale->payment_status, 'antes quedaba "pagada" por el total');
        $this->assertSame('0.00', $sale->paid_amount);
        $this->assertSame('credit', $sale->payment_method);
        $this->assertSame(0, SalePayment::where('sale_id', $saleId)->count());
        $this->assertSame(0, CashMovement::where('source_type', 'sale')->where('source_id', $saleId)->count(), 'antes entraba un ingreso "otro"');
        $this->assertEquals(8, $this->stockOf($coco));
    }

    public function test_order_checkout_on_credit_with_an_amount_is_rejected(): void
    {
        $coco = $this->createProduct([], 10);
        $order = $this->makeOrder($coco, 2, 190);

        // La pantalla del pedido precargaba el total en el monto al elegir Crédito.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'credit', 'paid_amount' => 380])
            ->assertStatus(422)
            ->assertJsonPath('code', 'credit_with_payment');

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_old_sales_screen_rejects_a_down_payment_marked_as_credit(): void
    {
        $coco = $this->createProduct(['sale_price' => 65], 10);
        $cashier = $this->cashier(['sales_create']);

        $this->legacySale($cashier, [
            'payment_method' => 'credit',
            'paid_amount' => 50,
            'details' => [$this->legacyLine($coco, 2)],
        ])->assertStatus(422)->assertJsonPath('code', 'credit_with_payment');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CashMovement::count());
        $this->assertEquals(10, $this->stockOf($coco));

        // El anticipo se registra con su forma de pago real: la venta queda parcial.
        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 50,
            'details' => [$this->legacyLine($coco, 2)],
        ])->assertCreated()->json('data.id');

        $sale = Sale::findOrFail($saleId);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('50.00', $sale->paid_amount);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $saleId, 'method' => 'card', 'amount' => 50]);
        $this->assertDatabaseHas('cash_movements', ['source_id' => $saleId, 'payment_method' => 'card', 'amount' => 50]);
    }

    public function test_an_abono_cannot_be_registered_as_credit(): void
    {
        $coco = $this->createProduct(['sale_price' => 65], 10);
        $cashier = $this->cashier(['sales_create', 'sales_update']);
        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'credit',
            'details' => [$this->legacyLine($coco, 2)],
        ])->assertCreated()->json('data.id');

        $this->actingInBranch($cashier)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 100, 'payment_method' => 'credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $sale = Sale::findOrFail($saleId);
        $this->assertSame('0.00', $sale->paid_amount, 'antes se marcaba como cobrado un dinero que no entró');
        $this->assertSame(0, CashMovement::count());
    }

    // ─── El movimiento de caja es parte de la venta ──────────────────────────

    public function test_a_failed_cash_movement_rolls_back_the_pos_sale(): void
    {
        $coco = $this->createProduct(['sale_price' => 20], 10);
        $cashier = $this->cashier();
        CashMovement::creating(fn() => throw new \RuntimeException('falla simulada al guardar el movimiento'));

        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 40]])
            ->assertStatus(500);

        // Antes: venta, stock y pago guardados, sin el ingreso en caja.
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_a_failed_cash_movement_rolls_back_the_order_checkout_without_leaking_sql(): void
    {
        $coco = $this->createProduct([], 10);
        $order = $this->makeOrder($coco, 1, 50);
        CashMovement::creating(fn() => throw new \PDOException(
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded (SQL: insert into `cash_movements` ...)'
        ));

        $response = $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'card', 'paid_amount' => 50])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Error al cobrar el pedido')
            ->assertJsonMissingPath('error');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());

        $this->assertSame('pending', SalesOrder::findOrFail($order->id)->status->value);
        $this->assertNull(SalesOrder::findOrFail($order->id)->sale_id);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_order_errors_carry_the_reason_in_the_message(): void
    {
        $coco = $this->createProduct([], 10);
        $order = $this->makeOrder($coco, 1, 50);

        // Entregar sin cobrar: el motivo llega en `message` (la app solo mostraba
        // el genérico "Error al cambiar el estado del pedido").
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/deliver")
            ->assertStatus(422)
            ->assertJsonPath('code', 'order_not_paid')
            ->assertJsonPath('message', 'El pedido debe cobrarse antes de marcarse como entregado.');

        // Un pedido de mostrador no pasa a "preparando".
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/prepare")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_order_transition');

        // Apartar más de lo que hay: el motivo del stock en `message`.
        $big = $this->makeOrder($coco, 50, 10, 'pending', 'delivery');
        $message = $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$big->id}/prepare")
            ->assertStatus(422)
            ->json('message');
        $this->assertStringContainsString('Stock insuficiente', $message);
        $this->assertSame(0.0, $this->reservedStock($coco));
        $this->assertSame(0, DB::table('sales')->count());
    }
}
