<?php

namespace Tests\Feature\VentasCaja;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\User;
use App\Services\CashRegisterSessionService;

/**
 * El efectivo que sale (o entra) de la caja fuera del punto de venta —un
 * gasto, un movimiento a mano— entra al corte del turno de la caja de donde
 * salió: el de quien lo registra o, si no tiene, el que elige de los abiertos
 * en la sucursal. Si nadie tiene turno, se rechaza.
 *
 * Antes quedaba fuera de turno: el encargado sacaba dinero del cajón del
 * cajero, lo registraba sin turno propio y el cajero cerraba con faltante.
 * (Las compras: ComprasProduccionPurchasesTest.)
 */
class CashSourceShiftTest extends VentasCajaTestCase
{
    private const MANAGER = ['expenses_create', 'expenses_update', 'expenses_read', 'expenses_list', 'cash_movements_create', 'cash_movements_update', 'cash_movements_list'];

    private User $manager;
    private User $juan;
    private CashRegisterSession $juanShift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createBranchUser($this->location, self::MANAGER)->fresh();
        $this->juan = $this->cashier();
        $this->juanShift = CashRegisterSession::where('user_id', $this->juan->id)->firstOrFail();
    }

    private function expense(User $user, array $extra = [])
    {
        return $this->actingInBranch($user)->postJson('/api/auth/admin/expenses', $extra + [
            'category' => 'suministros', 'amount' => 200, 'payment_method' => 'efectivo',
            'description' => 'Hielo', 'expense_date' => now()->toDateString(), 'paid_from_cash' => true,
        ]);
    }

    private function movement(User $user, array $extra = [])
    {
        return $this->actingInBranch($user)->postJson('/api/auth/admin/cash-movements', $extra + [
            'type' => 'expense', 'amount' => 300, 'concept' => 'Retiro para el banco', 'payment_method' => 'cash',
            'movement_date' => now()->toDateString(),
        ]);
    }

    private function expectedCash(CashRegisterSession $session): float
    {
        return app(CashRegisterSessionService::class)->summary($session->fresh())['expected_cash'];
    }

    public function test_the_open_shifts_of_the_branch_are_listed_mine_first(): void
    {
        $ana = $this->cashier();
        $this->cashier([], true, $this->createBranch(['name' => 'Otra']));

        $rows = $this->actingInBranch($ana)
            ->getJson('/api/auth/admin/cash-register-sessions/open-at-location')
            ->assertOk()
            ->json('data');

        // Solo los de esta sucursal; el de quien pregunta primero.
        $this->assertSame([$ana->id, $this->juan->id], array_column($rows, 'user_id'));
        $this->assertSame([true, false], array_column($rows, 'mine'));
        $this->assertSame($this->juan->name, $rows[1]['user_name']);
        $this->assertNotEmpty($rows[1]['cash_register_name']);

        // Quien no registra gastos, compras ni movimientos no los ve.
        $clerk = $this->createBranchUser($this->location, ['products_list'])->fresh();
        $this->actingInBranch($clerk)->getJson('/api/auth/admin/cash-register-sessions/open-at-location')->assertForbidden();
    }

    public function test_an_expense_without_a_shift_of_its_own_comes_out_of_the_chosen_register(): void
    {
        // Sin elegir: no queda fuera de turno.
        $this->expense($this->manager)
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_session_required');
        $this->assertSame(0, Expense::count());

        $this->expense($this->manager, ['cash_register_session_id' => $this->juanShift->id])
            ->assertCreated()
            ->assertJsonPath('data.cash_session.id', $this->juanShift->id)
            ->assertJsonPath('data.cash_session.user_name', $this->juan->name);

        $movement = CashMovement::where('source_type', 'expense')->firstOrFail();
        $this->assertSame($this->juanShift->id, (int) $movement->cash_register_session_id);
        $this->assertSame($this->manager->id, (int) $movement->user_id, 'a nombre de quien lo sacó');
        $this->assertEquals(500 - 200, $this->expectedCash($this->juanShift));
    }

    public function test_editing_an_expense_keeps_it_in_the_register_it_came_from(): void
    {
        $id = $this->expense($this->manager, ['cash_register_session_id' => $this->juanShift->id])->json('data.id');

        // Lo corrige otro, con su propio turno: el dinero salió del cajón de Juan.
        $ana = $this->cashier(self::MANAGER);
        $this->actingInBranch($ana)
            ->putJson("/api/auth/admin/expenses/{$id}", ['amount' => 250])
            ->assertOk()
            ->assertJsonPath('data.cash_session.id', $this->juanShift->id);

        $this->assertEquals(500 - 250, $this->expectedCash($this->juanShift));
        $this->actingInBranch($ana)->getJson("/api/auth/admin/expenses/{$id}")
            ->assertOk()
            ->assertJsonPath('data.cash_session.status', 'open');
    }

    public function test_with_nobody_on_shift_a_cash_expense_from_the_register_is_refused(): void
    {
        $this->juanShift->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->expense($this->manager)
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');

        // Pagado por el dueño, o con tarjeta, no sale del cajón.
        $this->expense($this->manager, ['paid_from_cash' => false])->assertCreated();
        $this->expense($this->manager, ['payment_method' => 'tarjeta'])->assertCreated();
    }

    public function test_a_closed_or_foreign_shift_cannot_be_chosen(): void
    {
        $other = $this->cashier([], true, $this->createBranch(['name' => 'Otra']));
        $foreign = CashRegisterSession::where('user_id', $other->id)->firstOrFail();

        $this->expense($this->manager, ['cash_register_session_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_session_closed');

        $this->juanShift->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);
        $this->expense($this->manager, ['cash_register_session_id' => $this->juanShift->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_session_closed');

        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_manual_movement_without_a_shift_of_its_own_goes_to_the_chosen_register(): void
    {
        $this->movement($this->manager)->assertStatus(422)->assertJsonPath('code', 'cash_session_required');

        $this->movement($this->manager, ['cash_register_session_id' => $this->juanShift->id])->assertCreated();
        $this->movement($this->manager, [
            'type' => 'income', 'amount' => 100, 'concept' => 'Cambio', 'cash_register_session_id' => $this->juanShift->id,
        ])->assertCreated();

        $this->assertEquals(500 - 300 + 100, $this->expectedCash($this->juanShift));

        // Con tarjeta no toca el cajón: no hace falta turno.
        $this->movement($this->manager, ['payment_method' => 'card', 'concept' => 'Comisión'])->assertCreated();
    }

    public function test_a_movement_out_of_any_shift_is_not_switched_to_cash(): void
    {
        $id = $this->movement($this->manager, ['payment_method' => 'card', 'concept' => 'Comisión'])->json('data.id');

        $this->actingInBranch($this->manager)
            ->putJson("/api/auth/admin/cash-movements/{$id}", ['payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_session_required');
        $this->assertSame('card', CashMovement::findOrFail($id)->payment_method);
    }

    public function test_the_cashier_sees_in_the_closing_who_took_cash_from_the_register(): void
    {
        $this->expense($this->manager, ['cash_register_session_id' => $this->juanShift->id])->assertCreated();
        $this->movement($this->manager, ['cash_register_session_id' => $this->juanShift->id])->assertCreated();

        $summary = $this->actingInBranch($this->juan)
            ->getJson("/api/auth/admin/cash-register-sessions/{$this->juanShift->id}")
            ->assertOk()
            ->json('summary');

        $this->assertSame(['Gasto: Hielo', 'Retiro para el banco'], array_column($summary['cash_movements'], 'concept'));
        $this->assertSame([$this->manager->name, $this->manager->name], array_column($summary['cash_movements'], 'user_name'));
        $this->assertSame(['expense', 'expense'], array_column($summary['cash_movements'], 'type'));

        // Se congela con el corte.
        $closed = $this->actingInBranch($this->juan)
            ->postJson("/api/auth/admin/cash-register-sessions/{$this->juanShift->id}/close", ['counted_cash' => 0])
            ->assertOk()
            ->json('summary');
        $this->assertCount(2, $closed['cash_movements']);
    }
}
