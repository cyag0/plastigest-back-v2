<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\PurchaseV2;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;

class CashMovementService
{
    /**
     * Devolver efectivo exige un turno abierto en la sucursal de la venta
     * (decisión conservadora: el efectivo que sale de la caja tiene que
     * quedar en algún corte). El mensaje dice qué hacer: quien cancela abre
     * su turno o se lo pide al cajero en turno.
     */
    public const CASH_REFUND_NEEDS_SHIFT = 'Para devolver efectivo abre un turno de caja en esta sucursal o pide al cajero en turno que cancele la venta.';

    /**
     * Mapea métodos de pago a los valores válidos de cash_movements.
     */
    private static function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash', 'efectivo'          => 'cash',
            'card', 'tarjeta'           => 'card',
            'transfer', 'transferencia' => 'transfer',
            default                     => 'other',
        };
    }

    /**
     * Cómo se nombra el documento en el concepto: por su folio, que es el que
     * conoce la gente ("SAL-20261015-0003"); el id interno solo si no tiene.
     * Antes iban los dos ("Venta #4668 (SAL-20261015-0003)").
     */
    private static function documentLabel(?string $number, int|string $id): string
    {
        return !empty($number) ? $number : "#{$id}";
    }

    /**
     * Crea un movimiento de caja de tipo ingreso a partir de una venta.
     *
     * Sin try/catch, igual que reverseSale(): se llama dentro de la
     * transacción de la venta, del cobro del pedido o del abono, y si el
     * movimiento no se puede registrar la operación completa se revierte.
     * Antes el error solo se registraba en el log: la venta, su stock y sus
     * pagos quedaban sin el ingreso y el turno esperaba menos efectivo.
     *
     * @param Sale   $sale          Venta de la cual proviene el ingreso
     * @param float  $amount        Monto del pago
     * @param string $paymentMethod Método de pago usado
     * @param string|null $notes    Notas adicionales
     */
    public static function fromSale(Sale $sale, float $amount, string $paymentMethod, ?string $notes = null, ?int $sessionId = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $concept = 'Venta ' . self::documentLabel($sale->sale_number, $sale->id);

        // El turno indicado solo vale si está abierto y es de la sucursal de
        // la venta. Sin turno indicado (p. ej. un abono posterior) entra al
        // turno abierto de quien cobra en esa sucursal; nunca al de otra
        // sucursal ni a un turno ya cerrado.
        $sessionId = $sessionId !== null
            ? self::openSessionAtLocation($sessionId, $sale->location_id)
            : self::sessionForSale($sale);

        CashMovement::create([
            'company_id'     => $sale->company_id,
            'location_id'    => $sale->location_id,
            'cash_register_session_id' => $sessionId,
            'user_id'        => Auth::id() ?? $sale->user_id,
            'type'           => 'income',
            'amount'         => $amount,
            'concept'        => $concept,
            'payment_method' => self::mapPaymentMethod($paymentMethod),
            'source_type'    => 'sale',
            'source_id'      => $sale->id,
            'source_url'     => '/(tabs)/home/sales/' . $sale->id,
            'notes'          => $notes,
            'movement_date'  => $sale->sale_date
                ? (is_string($sale->sale_date) ? $sale->sale_date : $sale->sale_date->toDateString())
                : now()->toDateString(),
        ]);
    }

    /**
     * Ingreso de un abono (pago posterior de una venta a crédito o parcial).
     *
     * Va fechado el día en que entra el dinero y en el turno de quien lo
     * cobra (el que le pasa quien llama, ya bloqueado); sin turno queda fuera
     * de turno, y solo se permite así cuando no es efectivo. Antes el abono se
     * fechaba con el día de la venta: lo cobrado hoy aparecía en el día de la
     * venta y no en el de hoy.
     */
    public static function fromSalePayment(Sale $sale, float $amount, string $paymentMethod, ?string $notes, ?int $sessionId): void
    {
        if ($amount <= 0) {
            return;
        }

        CashMovement::create([
            'company_id'     => $sale->company_id,
            'location_id'    => $sale->location_id,
            'cash_register_session_id' => self::openSessionAtLocation($sessionId, $sale->location_id),
            'user_id'        => Auth::id() ?? $sale->user_id,
            'type'           => 'income',
            'amount'         => $amount,
            'concept'        => 'Abono de venta ' . self::documentLabel($sale->sale_number, $sale->id),
            'payment_method' => self::mapPaymentMethod($paymentMethod),
            // Mismo origen que el cobro inicial: al cancelar la venta,
            // reverseSale() devuelve también los abonos.
            'source_type'    => 'sale',
            'source_id'      => $sale->id,
            'source_url'     => '/(tabs)/home/sales/' . $sale->id,
            'notes'          => $notes,
            'movement_date'  => now()->toDateString(),
        ]);
    }

    /**
     * Turno que recibe la devolución de una venta que se cancela, con su fila
     * bloqueada: el turno abierto de quien cancela en la sucursal de la venta
     * o, si no tiene, el turno de la venta si sigue abierto.
     *
     * Si la venta cobró efectivo, la devolución exige uno de esos turnos
     * (no_open_session): antes, si ninguno estaba abierto, el efectivo salía
     * de la caja sin quedar en ningún turno y el cajero aparecía con faltante.
     * Tarjeta y transferencia no salen de la caja y pueden quedar fuera de
     * turno.
     *
     * Se llama antes de devolver el stock (orden de bloqueos: turno → stock,
     * como en una venta).
     *
     * @throws BusinessRuleException
     */
    public static function refundSessionFor(Sale $sale): ?int
    {
        $sessions = app(CashRegisterSessionService::class);
        $locationId = (int) $sale->location_id;

        $session = $sessions->lockOpenForUser(Auth::id(), $locationId);

        if (!$session && $sale->cash_register_session_id) {
            $candidate = CashRegisterSession::query()->lockForUpdate()->find($sale->cash_register_session_id);
            $session = $candidate && $candidate->isOpen() && (int) $candidate->location_id === $locationId ? $candidate : null;
        }

        if (!$session && self::collectedCash($sale) > 0) {
            throw new BusinessRuleException(
                self::CASH_REFUND_NEEDS_SHIFT,
                'no_open_session',
            );
        }

        return $session?->id;
    }

    /**
     * Revierte en caja lo cobrado de una venta que se cancela.
     *
     * Se registra un egreso por cada método de pago con el que entró dinero,
     * fechado el día de la cancelación: así el corte de ese día refleja la
     * devolución y los cortes ya cerrados no cambian. Antes la venta se
     * cancelaba y su ingreso seguía contando en caja.
     *
     * @param int|null $sessionId El turno que resolvió refundSessionFor(). Sin
     *   turno no se devuelve efectivo (no_open_session).
     */
    public static function reverseSale(Sale $sale, ?int $sessionId = null): void
    {
        $collected = CashMovement::query()
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('type', 'income')
            ->selectRaw('payment_method, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->get();

        $sessionId = self::openSessionAtLocation($sessionId, $sale->location_id);

        $concept = 'Cancelación de venta ' . self::documentLabel($sale->sale_number, $sale->id);

        foreach ($collected as $row) {
            if ((float) $row->total <= 0) {
                continue;
            }

            if ($row->payment_method === 'cash' && !$sessionId) {
                throw new BusinessRuleException(
                    self::CASH_REFUND_NEEDS_SHIFT,
                    'no_open_session',
                );
            }

            CashMovement::create([
                'company_id'     => $sale->company_id,
                'location_id'    => $sale->location_id,
                'cash_register_session_id' => $sessionId,
                'user_id'        => Auth::id() ?? $sale->user_id,
                'type'           => 'expense',
                'amount'         => (float) $row->total,
                'concept'        => $concept,
                'payment_method' => $row->payment_method,
                'source_type'    => 'sale_cancellation',
                'source_id'      => $sale->id,
                'source_url'     => '/(tabs)/home/sales/' . $sale->id,
                'notes'          => null,
                'movement_date'  => now()->toDateString(),
            ]);
        }
    }

    /** Efectivo que entró a caja por una venta (cobro inicial y abonos). */
    private static function collectedCash(Sale $sale): float
    {
        return (float) CashMovement::query()
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('type', 'income')
            ->where('payment_method', 'cash')
            ->sum('amount');
    }

    /**
     * Crea un movimiento de caja de tipo egreso a partir de una compra V2 recibida.
     *
     * Sin try/catch, como fromSale(): si el egreso no se puede registrar, la
     * recepción se revierte en lugar de quedar sin su salida de caja.
     *
     * @param PurchaseV2 $purchase Compra recibida
     * @param float|null $amount   Lo que salió de la caja; por defecto el total de
     *                             la compra (con recepción parcial, quien llama
     *                             manda lo recibido × costo).
     */
    public static function fromPurchaseV2(PurchaseV2 $purchase, ?float $amount = null): void
    {
        $total = $amount ?? (float) ($purchase->total ?? 0);

        // Si la compra no se pagó con dinero de la caja (la pagó el dueño o se
        // hizo desde el banco) no debe salir del corte.
        if ($total <= 0 || $purchase->paid_from_cash === false) {
            return;
        }

        $concept = 'Compra ' . self::documentLabel($purchase->purchase_number, $purchase->id);

        CashMovement::create([
            'company_id'     => $purchase->company_id,
            'location_id'    => $purchase->location_id,
            // El dinero sale de la caja de quien recibe, si tiene turno
            // abierto en esa sucursal. Antes la compra no quedaba en ningún
            // turno y el efectivo esperado del cajero no la descontaba.
            'cash_register_session_id' => self::lockedOpenSessionIdFor(Auth::id(), $purchase->location_id),
            'user_id'        => Auth::id() ?? $purchase->user_id,
            'type'           => 'expense',
            'amount'         => $total,
            'concept'        => $concept,
            'payment_method' => self::mapPaymentMethod($purchase->payment_method ?? null),
            'source_type'    => 'purchase_v2',
            'source_id'      => $purchase->id,
            'source_url'     => '/(tabs)/home/purchases/' . $purchase->id,
            'notes'          => null,
            'movement_date'  => $purchase->purchase_date
                ? (is_string($purchase->purchase_date) ? $purchase->purchase_date : $purchase->purchase_date->toDateString())
                : now()->toDateString(),
        ]);
    }

    /**
     * Deja la caja alineada con un gasto: borra su egreso anterior y, si el
     * gasto se pagó con dinero de la caja, registra uno nuevo con el monto,
     * método y fecha actuales. Se llama al crear y al editar.
     *
     * Antes los gastos no tocaban la caja y el corte no los reflejaba.
     */
    public static function syncExpense(Expense $expense): void
    {
        $paidFromCash = $expense->paid_from_cash && (float) $expense->amount > 0;

        // Si quien lo registra tiene turno abierto en la sucursal del gasto, el
        // gasto sale de esa caja (nunca de la caja de otra sucursal). El turno
        // se bloquea antes de escribir, como en una venta.
        $sessionId = $paidFromCash ? self::lockedOpenSessionIdFor(Auth::id(), $expense->location_id) : null;

        self::removeExpense($expense);

        if (!$paidFromCash) {
            return;
        }

        CashMovement::create([
            'company_id'     => $expense->company_id,
            'location_id'    => $expense->location_id,
            'cash_register_session_id' => $sessionId,
            'user_id'        => Auth::id() ?? $expense->user_id,
            'type'           => 'expense',
            'amount'         => (float) $expense->amount,
            'concept'        => 'Gasto: ' . \Illuminate\Support\Str::limit((string) $expense->description, 200),
            'payment_method' => self::mapPaymentMethod($expense->payment_method),
            'source_type'    => 'expense',
            'source_id'      => $expense->id,
            // Los gastos se abren en su formulario (no hay pantalla de detalle).
            'source_url'     => '/(tabs)/home/expenses/form?id=' . $expense->id,
            'notes'          => null,
            'movement_date'  => $expense->expense_date
                ? $expense->expense_date->toDateString()
                : now()->toDateString(),
        ]);
    }

    /**
     * ¿El egreso de caja del gasto pertenece a un turno ya cerrado? Ese corte
     * ya se contó: el monto, el método, la fecha y "pagado con caja" del gasto
     * no se cambian ni el gasto se borra (C15). Con $lock la fila del turno
     * queda bloqueada hasta el final de la transacción: un cierre simultáneo
     * espera, o el cambio ve el turno ya cerrado.
     */
    public static function expenseInClosedShift(Expense $expense, bool $lock = false): bool
    {
        $sessionIds = CashMovement::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->whereNotNull('cash_register_session_id')
            ->pluck('cash_register_session_id')
            ->unique()
            ->all();

        if ($sessionIds === []) {
            return false;
        }

        return CashRegisterSession::whereKey($sessionIds)
            ->when($lock, fn($query) => $query->lockForUpdate())
            ->get(['id', 'status'])
            ->contains(fn(CashRegisterSession $session) => $session->status !== CashRegisterSession::STATUS_OPEN);
    }

    /**
     * Quita de la caja el egreso de un gasto (al eliminarlo o antes de
     * volver a registrarlo).
     */
    public static function removeExpense(Expense $expense): void
    {
        CashMovement::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->delete();
    }

    /**
     * Turno al que entra el dinero de una venta que no trae turno propio (un
     * abono posterior) o su devolución al cancelarla: el turno abierto de quien
     * lo registra en la sucursal de la venta o, si no tiene, el de la venta si
     * sigue abierto. Si ninguno aplica queda fuera de turno: no se altera un
     * corte cerrado ni el de otra sucursal.
     */
    private static function sessionForSale(Sale $sale): ?int
    {
        if ($own = self::openSessionIdFor(Auth::id(), $sale->location_id)) {
            return $own;
        }

        return self::openSessionAtLocation($sale->cash_register_session_id, $sale->location_id);
    }

    /**
     * El turno dado, solo si sigue abierto y es de esa sucursal.
     */
    private static function openSessionAtLocation(?int $sessionId, ?int $locationId): ?int
    {
        if (!$sessionId || !$locationId) {
            return null;
        }

        $isUsable = CashRegisterSession::whereKey($sessionId)
            ->where('location_id', $locationId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();

        return $isUsable ? $sessionId : null;
    }

    /**
     * Id del turno abierto de un usuario en una sucursal. Un turno abierto en
     * otra sucursal no cuenta: el efectivo de una sucursal no puede entrar al
     * corte de la caja de otra.
     */
    public static function openSessionIdFor(?int $userId, ?int $locationId): ?int
    {
        if (!$userId || !$locationId) {
            return null;
        }

        return CashRegisterSession::where('user_id', $userId)
            ->where('location_id', $locationId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->value('id');
    }

    /**
     * Como openSessionIdFor(), pero con la fila del turno bloqueada hasta el
     * final de la transacción y releído que siga abierto
     * (CashRegisterSessionService::lockOpenForUser). Para los egresos e
     * ingresos que entran a un turno (gastos, compras, movimientos manuales):
     * un cierre simultáneo espera a que el movimiento termine y lo cuenta en
     * su corte, o el movimiento ve el turno ya cerrado y queda fuera de turno.
     * Antes se leía sin bloqueo y el movimiento podía caer en un turno recién
     * cerrado sin aparecer en su efectivo esperado. Llamar dentro de una
     * transacción.
     */
    public static function lockedOpenSessionIdFor(?int $userId, ?int $locationId): ?int
    {
        if (!$userId || !$locationId) {
            return null;
        }

        return app(CashRegisterSessionService::class)->lockOpenForUser($userId, (int) $locationId)?->id;
    }
}
