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

    /** Qué hacer con un gasto o una compra si nadie tiene turno abierto. */
    public const NOT_FROM_REGISTER_HINT = 'si lo pagó el dueño u otra fuente, quita «Pagado con dinero de la caja»';

    /**
     * Turno de la caja de donde sale (o a la que entra) el efectivo de un
     * gasto, una compra o un movimiento capturado a mano, con su fila
     * bloqueada hasta el final de la transacción (como en una venta, antes
     * que el stock). En orden:
     *
     * 1. El que eligió quien lo registra (la app le muestra los turnos
     *    abiertos de la sucursal cuando no tiene el suyo).
     * 2. El turno donde ya estaba el movimiento (al editar un gasto), si sigue
     *    abierto: el dinero salió de esa caja aunque lo corrija otro.
     * 3. El turno abierto de quien lo registra.
     *
     * Sin ninguno se rechaza: cash_session_required si hay turnos abiertos que
     * elegir, no_open_session si nadie tiene turno. Antes el movimiento
     * quedaba fuera de turno: ningún corte lo descontaba y el cajero de cuyo
     * cajón salió el dinero cerraba con faltante.
     *
     * @param string|null $noShiftHint Qué más se puede hacer si nadie tiene
     *   turno (para un gasto: quitar «Pagado con dinero de la caja»).
     * @throws BusinessRuleException
     */
    public static function cashSessionFor(?int $chosenId, int $locationId, ?int $currentId = null, ?string $noShiftHint = null): int
    {
        if ($chosenId) {
            $chosen = CashRegisterSession::query()->with(['cashRegister:id,name', 'user:id,name'])->lockForUpdate()->find($chosenId);
            if (!$chosen || (int) $chosen->location_id !== $locationId) {
                throw new BusinessRuleException('Ese turno de caja no es de esta sucursal. Elige otra caja.', 'cash_session_closed');
            }
            if (!$chosen->isOpen()) {
                throw new BusinessRuleException(sprintf(
                    'El turno de %s ya se cerró. Elige otra caja.',
                    self::sessionLabel($chosen),
                ), 'cash_session_closed');
            }

            return $chosen->id;
        }

        if ($currentId) {
            $current = CashRegisterSession::query()->lockForUpdate()->find($currentId);
            if ($current && $current->isOpen() && (int) $current->location_id === $locationId) {
                return $current->id;
            }
        }

        if ($own = self::lockedOpenSessionIdFor(Auth::id(), $locationId)) {
            return $own;
        }

        if (CashRegisterSession::where('location_id', $locationId)->where('status', CashRegisterSession::STATUS_OPEN)->exists()) {
            throw new BusinessRuleException(
                'No tienes turno abierto: elige de qué caja sale el efectivo.',
                'cash_session_required',
            );
        }

        throw new BusinessRuleException(
            'Nadie tiene turno abierto en esta sucursal: el efectivo no puede salir de una caja. Pide que alguien abra su turno'
                . ($noShiftHint ? ", o {$noShiftHint}" : '') . '.',
            'no_open_session',
        );
    }

    /**
     * Turnos abiertos de la sucursal, el de quien pregunta primero: de los que
     * puede salir el efectivo de un gasto, una compra o un movimiento.
     *
     * @return array<int, array{id: int, cash_register_name: ?string, user_id: int, user_name: ?string, opened_at: ?string, mine: bool}>
     */
    public static function openSessionsAt(int $locationId): array
    {
        return CashRegisterSession::with(['cashRegister:id,name', 'user:id,name'])
            ->where('location_id', $locationId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->get()
            ->sortBy(fn(CashRegisterSession $session) => [(int) $session->user_id !== (int) Auth::id(), (string) $session->cashRegister?->name])
            ->map(fn(CashRegisterSession $session) => [
                'id' => $session->id,
                'cash_register_name' => $session->cashRegister?->name,
                'user_id' => (int) $session->user_id,
                'user_name' => $session->user?->name,
                'opened_at' => $session->opened_at?->toISOString(),
                'mine' => (int) $session->user_id === (int) Auth::id(),
            ])
            ->values()
            ->all();
    }

    /** ¿El método mueve el efectivo del cajón? (los de gastos van en español). */
    public static function isCash(?string $method): bool
    {
        return self::mapPaymentMethod($method) === 'cash';
    }

    /** «Caja 1 (Juan)» */
    private static function sessionLabel(CashRegisterSession $session): string
    {
        $register = $session->cashRegister?->name ?? 'esa caja';

        return $session->user?->name ? "{$register} ({$session->user->name})" : $register;
    }

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
     * @param int|null $chosenSessionId El turno de la caja de donde salió el
     *                             efectivo, si quien recibe no tiene el suyo.
     */
    public static function fromPurchaseV2(PurchaseV2 $purchase, ?float $amount = null, ?int $chosenSessionId = null): void
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
            'cash_register_session_id' => self::purchaseSessionId($purchase, $chosenSessionId),
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
     * Turno del egreso de una compra pagada con la caja, bloqueado. En
     * efectivo, el de la caja de donde salió (cashSessionFor: el elegido o el
     * de quien recibe; sin ninguno se rechaza). Con tarjeta u otro medio no
     * sale del cajón: el turno de quien recibe, si tiene, o ninguno.
     *
     * @throws BusinessRuleException
     */
    public static function purchaseSessionId(PurchaseV2 $purchase, ?int $chosenSessionId = null): ?int
    {
        if ($purchase->paid_from_cash === false) {
            return null;
        }

        if (self::isCash($purchase->payment_method)) {
            return self::cashSessionFor($chosenSessionId, (int) $purchase->location_id, null, self::NOT_FROM_REGISTER_HINT);
        }

        return self::lockedOpenSessionIdFor(Auth::id(), $purchase->location_id);
    }

    /**
     * Deja la caja alineada con un gasto: borra su egreso anterior y, si el
     * gasto se pagó con dinero de la caja, registra uno nuevo con el monto,
     * método y fecha actuales. Se llama al crear y al editar.
     *
     * Antes los gastos no tocaban la caja y el corte no los reflejaba.
     *
     * @param int|null $chosenSessionId El turno de la caja de donde salió el
     *   efectivo, si quien lo registra no tiene el suyo (cashSessionFor).
     * @throws BusinessRuleException Efectivo sin turno de donde salga.
     */
    public static function syncExpense(Expense $expense, ?int $chosenSessionId = null): void
    {
        $paidFromCash = $expense->paid_from_cash && (float) $expense->amount > 0;

        // El efectivo sale del turno de una caja de la sucursal del gasto
        // (nunca de la de otra sucursal): el elegido, el que ya tenía o el de
        // quien lo registra. Con tarjeta o transferencia no sale del cajón y
        // va al turno de quien lo registra, si tiene. El turno se bloquea
        // antes de escribir, como en una venta.
        $sessionId = null;
        if ($paidFromCash) {
            $sessionId = self::isCash($expense->payment_method)
                ? self::cashSessionFor(
                    $chosenSessionId,
                    (int) $expense->location_id,
                    self::expenseSessionId($expense),
                    self::NOT_FROM_REGISTER_HINT,
                )
                : self::lockedOpenSessionIdFor(Auth::id(), $expense->location_id);
        }

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

    /** Turno donde está hoy el egreso del gasto, si tiene. */
    public static function expenseSessionId(Expense $expense): ?int
    {
        $sessionId = CashMovement::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->whereNotNull('cash_register_session_id')
            ->value('cash_register_session_id');

        return $sessionId ? (int) $sessionId : null;
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
