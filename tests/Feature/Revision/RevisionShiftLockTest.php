<?php

namespace Tests\Feature\Revision;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\PurchaseV2;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * Los egresos de gastos y compras (y los movimientos manuales) buscaban el
 * turno de quien los registra con una lectura sin bloqueo: si el turno se
 * cerraba en ese momento, el movimiento caía en un turno ya cerrado y su
 * efectivo esperado quedaba mal (y C15 bloqueaba después editar el gasto).
 * Ahora, como en una venta, el turno se bloquea y se relee que siga abierto.
 *
 * Un cierre simultáneo no se puede intercalar en una prueba de un solo
 * proceso: se simula cerrando el turno justo después de la lectura sin
 * bloqueo, que es donde entraba.
 */
class RevisionShiftLockTest extends VentasCajaTestCase
{
    private User $cashier;
    private CashRegisterSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier(['expenses_create', 'expenses_list', 'purchases_list', 'purchases_read', 'purchases_update', 'cash_movements_create', 'cash_movements_list'], false);
        $this->shift = $this->openShiftFor($this->cashier);
    }

    /** Cierra el turno en cuanto se lee sin bloqueo cuál es el turno abierto. */
    private function closeTheShiftRightAfterTheUnlockedLookup(): void
    {
        $closed = false;
        DB::listen(function (QueryExecuted $query) use (&$closed) {
            $sql = strtolower(ltrim($query->sql));
            if ($closed || !str_starts_with($sql, 'select') || !str_contains($sql, 'cash_register_sessions')
                || str_contains($sql, 'for update') || !in_array(CashRegisterSession::STATUS_OPEN, $query->bindings, true)) {
                return;
            }

            $closed = true;
            DB::table('cash_register_sessions')->where('id', $this->shift->id)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);
        });
    }

    private function sessionOf(string $sourceType): ?int
    {
        $movement = CashMovement::where('source_type', $sourceType)->latest('id')->firstOrFail();

        return $movement->cash_register_session_id;
    }

    private function expense()
    {
        return $this->actingInBranch($this->cashier)->postJson('/api/auth/admin/expenses', [
            'category' => 'suministros', 'amount' => 120, 'payment_method' => 'efectivo',
            'description' => 'Hielo', 'expense_date' => now()->toDateString(), 'paid_from_cash' => true,
        ]);
    }

    private function inTransitPurchase(): PurchaseV2
    {
        $product = $this->createProduct(['name' => 'Coco'], 0);
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'purchase_number' => PurchaseV2::generatePurchaseNumber(), 'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_IN_TRANSIT, 'payment_method' => 'cash', 'paid_from_cash' => true,
            'user_id' => $this->user->id,
        ]);
        $purchase->details()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_id' => $this->unitId('Pieza'), 'unit_price' => 15]);

        return $purchase->fresh('details');
    }

    public function test_a_cash_expense_enters_the_open_shift_reading_it_with_a_lock(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->expense()->assertCreated();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame($this->shift->id, $this->sessionOf('expense'));
        $this->assertTrue(
            $queries->contains(fn(string $sql) => str_contains($sql, 'cash_register_sessions') && str_contains(strtolower($sql), 'for update')),
            'el turno no se bloqueó'
        );
    }

    public function test_a_cash_expense_does_not_land_in_a_shift_that_just_closed(): void
    {
        $this->closeTheShiftRightAfterTheUnlockedLookup();

        $this->expense()->assertCreated();

        $this->assertNull($this->sessionOf('expense'), 'el gasto cayó en un turno ya cerrado');
    }

    public function test_a_purchase_paid_from_the_register_does_not_land_in_a_shift_that_just_closed(): void
    {
        $purchase = $this->inTransitPurchase();
        $this->closeTheShiftRightAfterTheUnlockedLookup();

        $this->actingInBranch($this->cashier)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/receive", [
                'details' => [['id' => $purchase->details->first()->id, 'quantity_received' => 10]],
            ])
            ->assertOk();

        $this->assertNull($this->sessionOf('purchase_v2'), 'la compra cayó en un turno ya cerrado');
    }

    public function test_a_purchase_paid_from_the_register_enters_the_open_shift(): void
    {
        $purchase = $this->inTransitPurchase();

        $this->actingInBranch($this->cashier)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/receive", [
                'details' => [['id' => $purchase->details->first()->id, 'quantity_received' => 10]],
            ])
            ->assertOk();

        $this->assertSame($this->shift->id, $this->sessionOf('purchase_v2'));
    }

    public function test_a_manual_movement_does_not_land_in_a_shift_that_just_closed(): void
    {
        $this->closeTheShiftRightAfterTheUnlockedLookup();

        $this->actingInBranch($this->cashier)->postJson('/api/auth/admin/cash-movements', [
            'type' => 'income', 'amount' => 50, 'concept' => 'Fondo extra', 'payment_method' => 'cash',
            'movement_date' => now()->toDateString(),
        ])->assertCreated();

        $movement = CashMovement::where('concept', 'Fondo extra')->firstOrFail();
        $this->assertNull($movement->cash_register_session_id, 'el movimiento cayó en un turno ya cerrado');
    }

    public function test_a_manual_movement_enters_the_open_shift(): void
    {
        $this->actingInBranch($this->cashier)->postJson('/api/auth/admin/cash-movements', [
            'type' => 'income', 'amount' => 50, 'concept' => 'Fondo extra', 'payment_method' => 'cash',
            'movement_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame($this->shift->id, CashMovement::where('concept', 'Fondo extra')->value('cash_register_session_id'));
    }
}
