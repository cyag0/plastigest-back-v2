<?php

namespace App\Services;

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
            'expected_cash' => round((float) $session->opening_amount + $cashNet, 2),
        ];
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
