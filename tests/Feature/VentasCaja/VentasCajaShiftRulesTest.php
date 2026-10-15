<?php

namespace Tests\Feature\VentasCaja;

use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\CashRegisterSessionService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * El efectivo siempre entra y sale por un turno de caja abierto en la
 * sucursal (contrato C14): ventas de la pantalla anterior, cobro de pedidos,
 * abonos y devoluciones. El turno se relee bloqueado dentro de la
 * transacción, así que una venta no cae en un turno recién cerrado.
 */
class VentasCajaShiftRulesTest extends VentasCajaTestCase
{
    // ─── Cobros en efectivo fuera del punto de venta ─────────────────────────

    public function test_old_sales_screen_needs_an_open_shift_to_take_cash(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $seller = $this->cashier(['sales_create'], withShift: false);

        $this->legacySale($seller, ['payment_method' => 'cash', 'details' => [$this->legacyLine($coco, 1)]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session')
            ->assertJsonPath('message', 'Abre un turno de caja para cobrar en efectivo');
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));

        // Sin efectivo no hace falta turno (queda fuera de turno).
        $cardSale = $this->legacySale($seller, ['payment_method' => 'card', 'details' => [$this->legacyLine($coco, 1)]])
            ->assertCreated()->json('data.id');
        $this->assertNull(Sale::findOrFail($cardSale)->cash_register_session_id);

        // Con turno abierto el efectivo entra a ese turno.
        $shift = $this->openShiftFor($seller);
        $cashSale = $this->legacySale($seller, ['payment_method' => 'cash', 'details' => [$this->legacyLine($coco, 1)]])
            ->assertCreated()->json('data.id');
        $this->assertSame($shift->id, Sale::findOrFail($cashSale)->cash_register_session_id);
        $this->assertDatabaseHas('cash_movements', ['source_id' => $cashSale, 'cash_register_session_id' => $shift->id, 'amount' => 30]);
    }

    public function test_a_shift_in_another_branch_does_not_count_for_cash(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $this->openShiftFor($this->user, $this->createBranch());

        $this->legacySale($this->user, ['payment_method' => 'cash', 'paid_amount' => 30, 'details' => [$this->legacyLine($coco, 1)]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_order_checkout_in_cash_needs_an_open_shift(): void
    {
        $coco = $this->createProduct([], 10);
        $order = $this->makeOrder($coco, 2, 50);

        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'cash', 'paid_amount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');
        $this->assertNull($order->fresh()->sale_id);
        $this->assertEquals(10, $this->stockOf($coco));

        // Pendiente de pago (sin monto en efectivo): no necesita turno.
        $pending = $this->makeOrder($coco, 1, 50);
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$pending->id}/checkout", ['payment_method' => 'cash', 'paid_amount' => 0])
            ->assertOk();

        // Con turno, el efectivo entra a él.
        $shift = $this->openShiftFor($this->user);
        $saleId = $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'cash', 'paid_amount' => 100])
            ->assertOk()->json('sale.id');
        $this->assertDatabaseHas('cash_movements', ['source_id' => $saleId, 'cash_register_session_id' => $shift->id, 'amount' => 100]);
    }

    // ─── Turno cerrado mientras se cobra ─────────────────────────────────────

    public function test_a_sale_never_lands_in_a_shift_closed_meanwhile(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $stale = CashRegisterSession::where('user_id', $cashier->id)->firstOrFail(); // leído "antes" del cierre

        // El supervisor cierra el turno justo antes de que la venta entre a su transacción.
        app(CashRegisterSessionService::class)->close($stale->fresh(), 500, null, $this->user);
        $this->actingAs($cashier);

        try {
            app(SaleService::class)->create([
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'user_id' => $cashier->id,
                'details' => [['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity' => 1, 'unit_price' => 30]],
                'payments' => [['method' => 'cash', 'amount' => 30]],
            ], $stale);
            $this->fail('La venta no debe entrar a un turno cerrado.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('no_open_session', $e->errorCode());
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CashMovement::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    // ─── Abonos ──────────────────────────────────────────────────────────────

    public function test_an_abono_is_dated_today_in_the_collectors_shift_with_its_payment_row(): void
    {
        $coco = $this->createProduct(['sale_price' => 100], 10);
        $cashier = $this->cashier(['sales_create', 'sales_update']);
        $saleId = $this->legacySale($cashier, ['payment_method' => 'credit', 'details' => [$this->legacyLine($coco, 3)]])
            ->assertCreated()->json('data.id');
        DB::table('sales')->where('id', $saleId)->update(['sale_date' => now()->subDays(10)->toDateString()]);
        $shift = CashRegisterSession::where('user_id', $cashier->id)->firstOrFail();

        $this->actingInBranch($cashier)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 120, 'payment_method' => 'cash', 'notes' => 'Primer abono'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial');

        $movement = CashMovement::where('source_id', $saleId)->where('source_type', 'sale')->firstOrFail();
        $this->assertSame(now()->toDateString(), $movement->movement_date->toDateString(), 'antes se fechaba el día de la venta');
        $this->assertSame($shift->id, $movement->cash_register_session_id);
        $this->assertStringStartsWith('Abono de venta', $movement->concept);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $saleId, 'method' => 'cash', 'amount' => 120, 'tendered' => 120, 'change' => 0]);

        // El turno lo espera en su efectivo.
        $summary = app(CashRegisterSessionService::class)->summary($shift->fresh());
        $this->assertEquals(620, $summary['expected_cash']);
    }

    public function test_a_cash_abono_needs_an_open_shift_but_a_card_abono_does_not(): void
    {
        $coco = $this->createProduct(['sale_price' => 100], 10);
        $manager = $this->cashier(['sales_create', 'sales_update'], withShift: false);
        $saleId = $this->legacySale($manager, ['payment_method' => 'credit', 'details' => [$this->legacyLine($coco, 2)]])
            ->assertCreated()->json('data.id');

        $this->actingInBranch($manager)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 50, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');
        $this->assertSame('0.00', Sale::findOrFail($saleId)->paid_amount);
        $this->assertSame(0, SalePayment::count());

        $this->actingInBranch($manager)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 50, 'payment_method' => 'card'])
            ->assertOk();
        $this->assertDatabaseHas('cash_movements', ['source_id' => $saleId, 'payment_method' => 'card', 'amount' => 50, 'cash_register_session_id' => null]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $saleId, 'method' => 'card', 'amount' => 50, 'tendered' => null]);
    }

    public function test_the_cash_cut_counts_an_abono_on_the_day_it_is_paid(): void
    {
        $coco = $this->createProduct(['sale_price' => 100], 10);
        $cashier = $this->cashier(['sales_create', 'sales_update']);
        $saleId = $this->legacySale($cashier, ['payment_method' => 'credit', 'details' => [$this->legacyLine($coco, 2)]])
            ->assertCreated()->json('data.id');
        $yesterday = now()->subDay()->toDateString();
        DB::table('sales')->where('id', $saleId)->update(['sale_date' => $yesterday]);

        $this->actingInBranch($cashier)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 80, 'payment_method' => 'cash'])
            ->assertOk();

        $cash = fn(string $date) => collect($this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/sales/cash-register?date=' . $date)->assertOk()->json('data.payment_methods'))
            ->firstWhere('method', 'Efectivo')['total'];

        $this->assertEquals(80, $cash(now()->toDateString()), 'el abono cuenta el día en que entró el dinero');
        $this->assertEquals(0, $cash($yesterday));
    }

    // ─── Devoluciones ────────────────────────────────────────────────────────

    public function test_a_cash_refund_needs_an_open_shift_in_the_branch(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $saleId = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 60]])
            ->assertCreated()->json('data.id');
        $shift = CashRegisterSession::where('user_id', $cashier->id)->firstOrFail();
        app(CashRegisterSessionService::class)->close($shift, 560, null, $cashier);

        // El encargado sin turno cancela la venta de un turno ya cerrado: el
        // efectivo saldría de la caja sin quedar en ningún turno.
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');
        $this->assertSame(SaleStatus::CLOSED, Sale::findOrFail($saleId)->status);
        $this->assertEquals(8, $this->stockOf($coco));

        // Con su turno abierto, la devolución sale de su caja y con fecha de hoy.
        $managerShift = $this->openShiftFor($this->user);
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/cancel")->assertOk();

        $this->assertDatabaseHas('cash_movements', [
            'source_type' => 'sale_cancellation', 'source_id' => $saleId, 'amount' => 60,
            'cash_register_session_id' => $managerShift->id, 'movement_date' => now()->toDateString(),
        ]);
        $this->assertEquals(10, $this->stockOf($coco));
        $this->assertSame(0, CashMovement::where('cash_register_session_id', $shift->id)->where('type', 'expense')->count(), 'el corte cerrado no cambia');
    }

    public function test_a_card_refund_does_not_need_a_shift(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $saleId = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]])
            ->assertCreated()->json('data.id');
        app(CashRegisterSessionService::class)->close(CashRegisterSession::where('user_id', $cashier->id)->firstOrFail(), 500, null, $cashier);

        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/cancel")->assertOk();
        $this->assertDatabaseHas('cash_movements', [
            'source_type' => 'sale_cancellation', 'source_id' => $saleId, 'payment_method' => 'card', 'cash_register_session_id' => null,
        ]);
    }
}
