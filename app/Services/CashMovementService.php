<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\PurchaseV2;
use App\Models\Sale;
use App\Support\CurrentLocation;
use Illuminate\Support\Facades\Auth;

class CashMovementService
{
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
     * Revierte en caja lo cobrado de una venta que se cancela.
     *
     * Se registra un egreso por cada método de pago con el que entró dinero,
     * fechado el día de la cancelación: así el corte de ese día refleja la
     * devolución y los cortes ya cerrados no cambian. Antes la venta se
     * cancelaba y su ingreso seguía contando en caja.
     */
    public static function reverseSale(Sale $sale): void
    {
        $collected = CashMovement::query()
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('type', 'income')
            ->selectRaw('payment_method, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->get();

        $sessionId = self::sessionForSale($sale);

        $concept = 'Cancelación de venta ' . self::documentLabel($sale->sale_number, $sale->id);

        foreach ($collected as $row) {
            if ((float) $row->total <= 0) {
                continue;
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
            'cash_register_session_id' => self::openSessionIdFor(Auth::id(), $purchase->location_id),
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
        self::removeExpense($expense);

        if (!$expense->paid_from_cash || (float) $expense->amount <= 0) {
            return;
        }

        CashMovement::create([
            'company_id'     => $expense->company_id,
            'location_id'    => $expense->location_id,
            // Si quien lo registra tiene turno abierto en la sucursal del gasto,
            // el gasto sale de esa caja (nunca de la caja de otra sucursal).
            'cash_register_session_id' => self::openSessionIdFor(Auth::id(), $expense->location_id),
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
     * Id del turno abierto de un usuario en la sucursal indicada o, si no se
     * indica, en la sucursal actual (header X-Location-ID). Lo usan los
     * movimientos manuales, que se registran en la sucursal actual.
     */
    public static function openSessionIdOf(?int $userId, ?int $locationId = null): ?int
    {
        return self::openSessionIdFor($userId, $locationId ?? CurrentLocation::id());
    }
}
