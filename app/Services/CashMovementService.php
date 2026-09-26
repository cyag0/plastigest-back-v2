<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\PurchaseV2;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
     * Crea un movimiento de caja de tipo ingreso a partir de una venta.
     *
     * @param Sale   $sale          Venta de la cual proviene el ingreso
     * @param float  $amount        Monto del pago
     * @param string $paymentMethod Método de pago usado
     * @param string|null $notes    Notas adicionales
     */
    public static function fromSale(Sale $sale, float $amount, string $paymentMethod, ?string $notes = null): void
    {
        if ($amount <= 0) {
            return;
        }

        try {
            $concept = 'Venta #' . $sale->id;
            if (!empty($sale->sale_number)) {
                $concept .= " ({$sale->sale_number})";
            }

            CashMovement::create([
                'company_id'     => $sale->company_id,
                'location_id'    => $sale->location_id,
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
        } catch (\Exception $e) {
            Log::error('CashMovementService: error al crear movimiento de venta', [
                'sale_id' => $sale->id,
                'amount'  => $amount,
                'error'   => $e->getMessage(),
            ]);
        }
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

        $concept = 'Cancelación de venta #' . $sale->id;
        if (!empty($sale->sale_number)) {
            $concept .= " ({$sale->sale_number})";
        }

        foreach ($collected as $row) {
            if ((float) $row->total <= 0) {
                continue;
            }

            CashMovement::create([
                'company_id'     => $sale->company_id,
                'location_id'    => $sale->location_id,
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
     * @param PurchaseV2 $purchase Compra recibida
     */
    public static function fromPurchaseV2(PurchaseV2 $purchase): void
    {
        $total = (float) ($purchase->total ?? 0);

        // Si la compra no se pagó con dinero de la caja (la pagó el dueño o se
        // hizo desde el banco) no debe salir del corte.
        if ($total <= 0 || $purchase->paid_from_cash === false) {
            return;
        }

        try {
            $concept = 'Compra #' . $purchase->id;
            if (!empty($purchase->purchase_number)) {
                $concept .= " ({$purchase->purchase_number})";
            }

            CashMovement::create([
                'company_id'     => $purchase->company_id,
                'location_id'    => $purchase->location_id,
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
        } catch (\Exception $e) {
            Log::error('CashMovementService: error al crear movimiento de compra V2', [
                'purchase_id' => $purchase->id,
                'total'       => $total,
                'error'       => $e->getMessage(),
            ]);
        }
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
            'user_id'        => Auth::id() ?? $expense->user_id,
            'type'           => 'expense',
            'amount'         => (float) $expense->amount,
            'concept'        => 'Gasto: ' . \Illuminate\Support\Str::limit((string) $expense->description, 200),
            'payment_method' => self::mapPaymentMethod($expense->payment_method),
            'source_type'    => 'expense',
            'source_id'      => $expense->id,
            'source_url'     => '/(tabs)/home/expenses/' . $expense->id,
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
}
