<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turnos de caja: abrir con fondo inicial, consultar totales y cerrar
 * contando el efectivo.
 */
class CashRegisterSessionService
{
    /** Turno abierto del usuario, si lo tiene. */
    public function current(User $user): ?CashRegisterSession
    {
        return CashRegisterSession::with('cashRegister')
            ->where('user_id', $user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->first();
    }

    /**
     * Relee el turno bloqueando su fila hasta el final de la transacción y
     * confirma que sigue abierto y es de esa sucursal. Se llama dentro de la
     * transacción de la venta, el cobro de un pedido o el abono: un cierre
     * simultáneo espera a que el cobro termine (y lo cuenta en sus totales),
     * o el cobro ve el turno ya cerrado y se rechaza. Antes el turno se leía
     * fuera de la transacción y una venta podía caer en un turno recién
     * cerrado sin aparecer en su corte.
     *
     * Orden de bloqueos: turno → folio → stock (cerrar solo bloquea el turno).
     *
     * @throws BusinessRuleException no_open_session si ya no está abierto.
     */
    public function lockOpen(int $sessionId, int $locationId): CashRegisterSession
    {
        $session = CashRegisterSession::query()->lockForUpdate()->find($sessionId);

        if (!$session || !$session->isOpen() || (int) $session->location_id !== $locationId) {
            throw new BusinessRuleException(
                'Tu turno de caja ya está cerrado. Abre un turno para seguir cobrando.',
                'no_open_session',
            );
        }

        return $session;
    }

    /**
     * Turno abierto del usuario en la sucursal, bloqueado como en lockOpen().
     * null si no tiene (o si se cerró mientras tanto): quien llama decide si
     * puede seguir sin turno (pagos que no son en efectivo).
     */
    public function lockOpenForUser(?int $userId, int $locationId): ?CashRegisterSession
    {
        $sessionId = CashMovementService::openSessionIdFor($userId, $locationId);
        if (!$sessionId) {
            return null;
        }

        $session = CashRegisterSession::query()->lockForUpdate()->find($sessionId);

        return $session && $session->isOpen() && (int) $session->location_id === $locationId ? $session : null;
    }

    /**
     * Error de cobro en efectivo sin turno abierto (contrato C14): el efectivo
     * fuera de un turno no lo espera ninguna caja y podía perderse sin que
     * ningún corte mostrara faltante.
     */
    public static function cashNeedsOpenShift(): BusinessRuleException
    {
        return new BusinessRuleException('Abre un turno de caja para cobrar en efectivo', 'no_open_session');
    }

    public function open(CashRegister $register, float $openingAmount, User $user): CashRegisterSession
    {
        if (!$register->is_active) {
            throw ValidationException::withMessages(['cash_register_id' => ['La caja está desactivada.']]);
        }

        if ($mine = $this->current($user)) {
            throw ValidationException::withMessages([
                'cash_register_id' => ["Ya tienes un turno abierto en {$mine->cashRegister->name}."],
            ]);
        }

        if ($taken = $register->openSession()->with('user')->first()) {
            throw ValidationException::withMessages([
                'cash_register_id' => ["La caja ya tiene un turno abierto de {$taken->user?->name}."],
            ]);
        }

        try {
            return CashRegisterSession::create([
                'company_id' => $register->company_id,
                'location_id' => $register->location_id,
                'cash_register_id' => $register->id,
                'user_id' => $user->id,
                'status' => CashRegisterSession::STATUS_OPEN,
                'opened_at' => now(),
                'opening_amount' => round($openingAmount, 2),
            ])->load('cashRegister');
        } catch (QueryException $e) {
            // Carrera (doble clic, dos dispositivos): el índice único la frena.
            if (str_contains($e->getMessage(), 'one_open_session_per')) {
                throw ValidationException::withMessages([
                    'cash_register_id' => ['La caja o el cajero ya tienen un turno abierto.'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Totales del turno: ventas, movimientos por método y efectivo esperado
     * (fondo + entradas en efectivo − salidas en efectivo).
     */
    public function summary(CashRegisterSession $session): array
    {
        $byMethod = CashMovement::where('cash_register_session_id', $session->id)
            ->selectRaw("payment_method,
                SUM(CASE WHEN type IN ('income', 'adjustment') THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense")
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn($row) => [$row->payment_method => [
                'income' => round((float) $row->income, 2),
                'expense' => round((float) $row->expense, 2),
                'net' => round((float) $row->income - (float) $row->expense, 2),
            ]])
            ->all();

        $sales = Sale::where('cash_register_session_id', $session->id);
        $cashNet = $byMethod['cash']['net'] ?? 0.0;

        return [
            'opening_amount' => (float) $session->opening_amount,
            'sales_count' => (clone $sales)->where('status', '!=', 'cancelled')->count(),
            'sales_total' => round((float) (clone $sales)->where('status', '!=', 'cancelled')->sum('total'), 2),
            'cancelled_count' => (clone $sales)->where('status', 'cancelled')->count(),
            'by_method' => $byMethod,
            'cash_movements' => $this->otherCashMovements($session),
            'expected_cash' => round((float) $session->opening_amount + $cashNet, 2),
        ];
    }

    /**
     * El efectivo que entró o salió del turno sin ser un cobro: gastos,
     * compras, devoluciones y movimientos a mano, con quién lo registró. Así
     * el cajero ve en su corte el dinero que otro sacó de su cajón (o metió),
     * en lugar de solo un efectivo esperado más bajo. Se congela con el corte.
     *
     * @return array<int, array{type: string, amount: float, concept: string, user_id: ?int, user_name: ?string, created_at: ?string}>
     */
    private function otherCashMovements(CashRegisterSession $session): array
    {
        return CashMovement::with('user:id,name')
            ->where('cash_register_session_id', $session->id)
            ->where('payment_method', 'cash')
            ->where(fn($query) => $query->whereNull('source_type')->orWhere('source_type', '!=', 'sale'))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn(CashMovement $movement) => [
                'type' => $movement->type,
                'amount' => round((float) $movement->amount, 2),
                'concept' => (string) $movement->concept,
                'user_id' => $movement->user_id ? (int) $movement->user_id : null,
                'user_name' => $movement->user?->name,
                'created_at' => $movement->created_at?->toISOString(),
            ])
            ->all();
    }

    public function close(CashRegisterSession $session, float $countedCash, ?string $notes, User $closer): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $countedCash, $notes, $closer) {
            // Bloquea el turno: dos cierres simultáneos no pueden cruzarse.
            $session = CashRegisterSession::lockForUpdate()->findOrFail($session->id);

            if (!$session->isOpen()) {
                throw ValidationException::withMessages(['session' => ['El turno ya está cerrado.']]);
            }

            $summary = $this->summary($session);

            $session->update([
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $closer->id,
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => round($countedCash, 2),
                'difference' => round($countedCash - $summary['expected_cash'], 2),
                'totals' => $summary,
                'notes' => $notes,
            ]);

            return $session->fresh(['cashRegister', 'user', 'closedBy']);
        });
    }
}
