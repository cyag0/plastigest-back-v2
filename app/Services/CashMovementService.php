<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Purchase;
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
     * Crea un movimiento de caja de tipo egreso a partir de una compra recibida.
     *
     * @param Purchase $purchase Compra recibida
     */
    public static function fromPurchase(Purchase $purchase): void
    {
        $total = (float) ($purchase->total_cost ?? 0);

        Log::info('CashMovementService: creando movimiento de compra', [
            'purchase_id' => $purchase->id,
            'total_cost'  => $total,
        ]);

        if ($total <= 0) {
            return;
        }

        try {
            $concept = 'Compra #' . $purchase->id;
            $reference = $purchase->reference ?? ($purchase->content['document_number'] ?? null);
            if ($reference) {
                $concept .= " ({$reference})";
            }

            CashMovement::create([
                'company_id'     => $purchase->company_id,
                'location_id'    => $purchase->location_origin_id,
                'user_id'        => Auth::id() ?? $purchase->user_id,
                'type'           => 'expense',
                'amount'         => $total,
                'concept'        => $concept,
                'payment_method' => self::mapPaymentMethod($purchase->payment_method ?? null),
                'source_type'    => 'purchase',
                'source_id'      => $purchase->id,
                'source_url'     => '/(tabs)/home/purchases/' . $purchase->id,
                'notes'          => null,
                'movement_date'  => $purchase->movement_date ?? now()->toDateString(),
            ]);
        } catch (\Exception $e) {
            Log::error('CashMovementService: error al crear movimiento de compra', [
                'purchase_id' => $purchase->id,
                'total'       => $total,
                'error'       => $e->getMessage(),
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

        if ($total <= 0) {
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
}
