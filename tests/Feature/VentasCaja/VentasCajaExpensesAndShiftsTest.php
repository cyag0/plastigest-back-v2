<?php

namespace Tests\Feature\VentasCaja;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\User;

/**
 * Gastos y turnos: un gasto cuyo egreso ya está en un turno cerrado conserva
 * su monto, método, fecha y "pagado con caja" (contrato C15), y gastos y
 * turnos de otra sucursal no se ven, editan, borran ni cierran.
 */
class VentasCajaExpensesAndShiftsTest extends VentasCajaTestCase
{
    private const EXPENSES = ['expenses_create', 'expenses_read', 'expenses_update', 'expenses_delete', 'expenses_list'];

    private function expenseClerk(): User
    {
        return $this->cashier(self::EXPENSES);
    }

    private function createExpense(User $user, array $overrides = [], ?\App\Models\Admin\Location $location = null): Expense
    {
        $id = $this->actingInBranch($user, $location)->postJson('/api/auth/admin/expenses', array_merge([
            'category' => 'suministros',
            'amount' => 100,
            'payment_method' => 'efectivo',
            'description' => 'Bolsas',
            'expense_date' => now()->toDateString(),
            'paid_from_cash' => true,
        ], $overrides))->assertCreated()->json('data.id');

        return Expense::findOrFail($id);
    }

    /** Lo que manda la app al editar: el formulario completo. */
    private function form(Expense $expense, array $changes = []): array
    {
        return array_merge([
            'category' => $expense->category,
            'amount' => (float) $expense->amount,
            'payment_method' => $expense->payment_method,
            'paid_from_cash' => (bool) $expense->paid_from_cash,
            'description' => $expense->description,
            'expense_date' => $expense->expense_date->toDateString(),
        ], $changes);
    }

    private function closeShiftOf(User $user): CashRegisterSession
    {
        $shift = CashRegisterSession::where('user_id', $user->id)->where('status', 'open')->sole();
        $this->actingInBranch($user)
            ->postJson("/api/auth/admin/cash-register-sessions/{$shift->id}/close", ['counted_cash' => 400])
            ->assertOk();

        return $shift;
    }

    public function test_an_expense_in_a_closed_shift_keeps_its_money_fields(): void
    {
        $clerk = $this->expenseClerk();
        $expense = $this->createExpense($clerk);
        $movement = CashMovement::where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $shift = $this->closeShiftOf($clerk);
        $this->assertSame($shift->id, $movement->cash_register_session_id);

        foreach ([
            ['amount' => 150],
            ['payment_method' => 'tarjeta'],
            ['expense_date' => now()->subDay()->toDateString()],
            ['paid_from_cash' => false],
        ] as $change) {
            $this->actingInBranch($clerk)->putJson("/api/auth/admin/expenses/{$expense->id}", $this->form($expense, $change))
                ->assertStatus(422)
                ->assertJsonPath('code', 'closed_session');
        }

        $this->assertEquals(100, $expense->fresh()->amount);
        $this->assertSame('efectivo', $expense->fresh()->payment_method);

        // Categoría y descripción sí (con el resto del formulario tal cual).
        $this->actingInBranch($clerk)
            ->putJson("/api/auth/admin/expenses/{$expense->id}", $this->form($expense, ['category' => 'servicios', 'description' => 'Bolsas grandes']))
            ->assertOk();
        $this->assertSame('servicios', $expense->fresh()->category);
        $this->assertSame('Bolsas grandes', $expense->fresh()->description);

        // El egreso del turno cerrado no se tocó (antes se borraba y recreaba).
        $this->assertSame($movement->id, CashMovement::where('source_type', 'expense')->where('source_id', $expense->id)->sole()->id);

        $this->actingInBranch($clerk)->deleteJson("/api/auth/admin/expenses/{$expense->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'closed_session');
        $this->assertNotNull(Expense::find($expense->id));
        $this->assertSame(1, CashMovement::where('source_type', 'expense')->count());
    }

    public function test_an_expense_in_an_open_shift_can_change_its_amount_or_be_deleted(): void
    {
        $clerk = $this->expenseClerk();
        $expense = $this->createExpense($clerk);

        $this->actingInBranch($clerk)->putJson("/api/auth/admin/expenses/{$expense->id}", $this->form($expense, ['amount' => 150]))
            ->assertOk();
        $this->assertEquals(150, CashMovement::where('source_type', 'expense')->where('source_id', $expense->id)->sole()->amount);

        $this->actingInBranch($clerk)->deleteJson("/api/auth/admin/expenses/{$expense->id}")->assertOk();
        $this->assertNull(Expense::find($expense->id));
        $this->assertSame(0, CashMovement::where('source_type', 'expense')->count());
    }

    public function test_an_expense_of_another_branch_is_not_found(): void
    {
        $otherBranch = $this->createBranch();
        $otherClerk = $this->cashier(self::EXPENSES, true, $otherBranch);
        $expense = $this->createExpense($otherClerk, [], $otherBranch);

        $clerk = $this->expenseClerk();
        $this->actingInBranch($clerk)->getJson("/api/auth/admin/expenses/{$expense->id}")->assertNotFound();
        $this->actingInBranch($clerk)->putJson("/api/auth/admin/expenses/{$expense->id}", $this->form($expense, ['amount' => 1]))->assertNotFound();
        $this->actingInBranch($clerk)->deleteJson("/api/auth/admin/expenses/{$expense->id}")->assertNotFound();

        $this->assertEquals(100, $expense->fresh()->amount);
        // Quien ve todas las sucursales sí lo encuentra.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/expenses/{$expense->id}")->assertOk();
    }

    public function test_the_all_option_of_the_list_filters_returns_every_expense(): void
    {
        $clerk = $this->expenseClerk();
        $this->createExpense($clerk, ['category' => 'suministros']);
        $this->createExpense($clerk, ['category' => 'servicios', 'payment_method' => 'tarjeta', 'paid_from_cash' => false]);

        // "Todas" llega vacío: antes filtraba por categoría NULL y no salía nada.
        $this->actingInBranch($clerk)->getJson('/api/auth/admin/expenses?category=&payment_method=&search=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->actingInBranch($clerk)->getJson('/api/auth/admin/expenses?category=servicios&payment_method=')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingInBranch($clerk)->getJson('/api/auth/admin/expenses?expense_date=' . now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_supervisor_only_closes_or_views_shifts_of_their_branch(): void
    {
        $otherBranch = $this->createBranch();
        $otherCashier = $this->cashier([], true, $otherBranch);
        $otherShift = CashRegisterSession::where('user_id', $otherCashier->id)->sole();

        $supervisor = $this->createBranchUser($this->location, ['cash_registers_update', 'cash_register_sessions_read', 'cash_register_sessions_list'])->fresh();

        $this->actingInBranch($supervisor)->getJson("/api/auth/admin/cash-register-sessions/{$otherShift->id}")->assertNotFound();
        $this->actingInBranch($supervisor)
            ->postJson("/api/auth/admin/cash-register-sessions/{$otherShift->id}/close", ['counted_cash' => 500])
            ->assertNotFound();
        $this->assertSame('open', $otherShift->fresh()->status);

        // En su sucursal sí puede ver y cerrar el turno que otro dejó abierto.
        $cashier = $this->cashier();
        $shift = CashRegisterSession::where('user_id', $cashier->id)->sole();
        $this->actingInBranch($supervisor)->getJson("/api/auth/admin/cash-register-sessions/{$shift->id}")->assertOk();
        $this->actingInBranch($supervisor)
            ->postJson("/api/auth/admin/cash-register-sessions/{$shift->id}/close", ['counted_cash' => 500])
            ->assertOk();
        $this->assertSame('closed', $shift->fresh()->status);
    }
}
