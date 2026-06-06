<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderStockService
{
    public function __construct(
        private readonly MovementService $movementService = new MovementService(),
    ) {
    }

    public function validateDraftDetails(int $locationId, array $details): void
    {
        if ($details === []) {
            return;
        }

        $requestedByProduct = [];
        $errorKeysByProduct = [];

        foreach ($details as $index => $detail) {
            $productId = (int) $detail['product_id'];

            try {
                $requestedBaseQuantity = $this->movementService->quantityInProductUnit(
                    $productId,
                    (int) $detail['unit_id'],
                    (float) $detail['requested_quantity'],
                    isset($detail['package_id']) ? (int) $detail['package_id'] : null,
                );
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages([
                    "details.{$index}.requested_quantity" => [$exception->getMessage()],
                ]);
            }

            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $requestedBaseQuantity;
            $errorKeysByProduct[$productId] ??= "details.{$index}.requested_quantity";
        }

        $products = Product::with('unit')
            ->whereIn('id', array_keys($requestedByProduct))
            ->get()
            ->keyBy('id');

        $stockByProduct = DB::table('product_location')
            ->where('location_id', $locationId)
            ->whereIn('product_id', array_keys($requestedByProduct))
            ->get(['product_id', 'current_stock', 'reserved_stock'])
            ->keyBy('product_id');

        $errors = [];

        foreach ($requestedByProduct as $productId => $requestedQuantity) {
            $product = $products->get($productId);
            $stockRow = $stockByProduct->get($productId);
            $availableQuantity = max(
                0,
                (float) ($stockRow->current_stock ?? 0) - (float) ($stockRow->reserved_stock ?? 0),
            );

            if ($requestedQuantity <= $availableQuantity + 0.000001) {
                continue;
            }

            $unitLabel = $product?->unit?->abbreviation ?? 'u base';
            $productName = $product?->name ?? "producto {$productId}";

            $errors[$errorKeysByProduct[$productId]] = [
                sprintf(
                    'Stock insuficiente para %s. Disponible: %s %s, solicitado: %s %s.',
                    $productName,
                    number_format($availableQuantity, 3, '.', ''),
                    $unitLabel,
                    number_format($requestedQuantity, 3, '.', ''),
                    $unitLabel,
                ),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function reserveOrder(SalesOrder $order): void
    {
        $order->loadMissing('details');

        if ($order->details->isEmpty()) {
            throw ValidationException::withMessages([
                'details' => ['El pedido no tiene productos para reservar.'],
            ]);
        }

        if ((float) $order->details->sum('reserved_quantity_base') > 0.000001) {
            throw ValidationException::withMessages([
                'status' => ['El pedido ya tiene inventario reservado.'],
            ]);
        }

        $draftDetails = $order->details->map(fn ($detail) => [
            'product_id' => $detail->product_id,
            'package_id' => $detail->package_id,
            'unit_id' => $detail->unit_id,
            'requested_quantity' => $detail->requested_quantity,
        ])->all();

        DB::transaction(function () use ($order, $draftDetails): void {
            $this->validateDraftDetails($order->location_id, $draftDetails);

            $reservedByProduct = [];

            foreach ($order->details as $detail) {
                $baseQuantity = $this->movementService->quantityInProductUnit(
                    $detail->product_id,
                    $detail->unit_id,
                    (float) $detail->requested_quantity,
                    $detail->package_id,
                );

                $reservedByProduct[$detail->product_id] = ($reservedByProduct[$detail->product_id] ?? 0) + $baseQuantity;

                $detail->forceFill([
                    'prepared_quantity' => $detail->requested_quantity,
                    'reserved_quantity_base' => $baseQuantity,
                ])->save();
            }

            $productStocks = DB::table('product_location')
                ->where('location_id', $order->location_id)
                ->whereIn('product_id', array_keys($reservedByProduct))
                ->lockForUpdate()
                ->get(['product_id', 'reserved_stock'])
                ->keyBy('product_id');

            foreach ($reservedByProduct as $productId => $reservedQuantity) {
                $currentReservedStock = (float) ($productStocks->get($productId)->reserved_stock ?? 0);

                DB::table('product_location')
                    ->where('location_id', $order->location_id)
                    ->where('product_id', $productId)
                    ->update([
                        'reserved_stock' => $currentReservedStock + $reservedQuantity,
                        'updated_at' => now(),
                    ]);
            }

            $order->forceFill(['reserved_at' => now()])->save();
        });
    }

    public function releaseOrder(SalesOrder $order): void
    {
        $order->loadMissing('details');

        $reservedByProduct = [];
        foreach ($order->details as $detail) {
            $reservedQuantity = (float) $detail->reserved_quantity_base;
            if ($reservedQuantity <= 0) {
                continue;
            }

            $reservedByProduct[$detail->product_id] = ($reservedByProduct[$detail->product_id] ?? 0) + $reservedQuantity;
        }

        DB::transaction(function () use ($order, $reservedByProduct): void {
            if ($reservedByProduct !== []) {
                $productStocks = DB::table('product_location')
                    ->where('location_id', $order->location_id)
                    ->whereIn('product_id', array_keys($reservedByProduct))
                    ->lockForUpdate()
                    ->get(['product_id', 'reserved_stock'])
                    ->keyBy('product_id');

                foreach ($reservedByProduct as $productId => $reservedQuantity) {
                    $currentReservedStock = (float) ($productStocks->get($productId)->reserved_stock ?? 0);

                    DB::table('product_location')
                        ->where('location_id', $order->location_id)
                        ->where('product_id', $productId)
                        ->update([
                            'reserved_stock' => max(0, $currentReservedStock - $reservedQuantity),
                            'updated_at' => now(),
                        ]);
                }
            }

            foreach ($order->details as $detail) {
                $detail->forceFill([
                    'reserved_quantity_base' => 0,
                ])->save();
            }

            $order->forceFill(['reserved_at' => null])->save();
        });
    }

    /**
     * Convert a sales order into a closed sale, releasing the reservation
     * and decrementing real stock atomically.
     *
     * @param  array{payment_method?: string, paid_amount?: float|int, notes?: string|null}  $payment
     */
    public function checkoutOrder(SalesOrder $order, array $payment = []): Sale
    {
        if ($order->status === SalesOrderStatus::CANCELLED || $order->status === SalesOrderStatus::DELIVERED) {
            throw ValidationException::withMessages([
                'status' => ['El pedido ya no puede cobrarse en su estado actual.'],
            ]);
        }

        if ($order->sale_id) {
            throw ValidationException::withMessages([
                'status' => ['El pedido ya está vinculado a una venta.'],
            ]);
        }

        $order->loadMissing('details');

        if ($order->details->isEmpty()) {
            throw ValidationException::withMessages([
                'details' => ['El pedido no tiene productos para cobrar.'],
            ]);
        }

        return DB::transaction(function () use ($order, $payment): Sale {
            // 1. Release any pending reservation so the sale's decrement
            //    operates on the same baseline as the previously available stock.
            $this->releaseOrder($order);
            $order->refresh();
            $order->loadMissing('details');

            // 2. Build a fresh Sale linked to the order.
            $subtotal = (float) $order->details->sum('line_subtotal');
            $total = (float) ($order->total_amount ?? $subtotal);
            $paymentMethod = $payment['payment_method'] ?? 'cash';
            $paidAmount = isset($payment['paid_amount']) ? (float) $payment['paid_amount'] : $total;
            $paymentStatus = $paidAmount >= $total
                ? 'paid'
                : ($paidAmount > 0 ? 'partial' : 'pending');

            $sale = Sale::create([
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'user_id' => Auth::id() ?? $order->updated_by ?? $order->created_by,
                'customer_id' => $order->customer_id,
                'sale_date' => now()->toDateString(),
                'status' => SaleStatus::CLOSED,
                'subtotal' => $subtotal,
                'tax' => 0,
                'discount' => 0,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'payment_history' => $paidAmount > 0
                    ? [[
                        'amount' => $paidAmount,
                        'payment_method' => $paymentMethod,
                        'date' => now()->toDateTimeString(),
                        'notes' => $payment['notes'] ?? null,
                    ]]
                    : [],
                'content' => [
                    'customer_name' => $order->customer_name_snapshot,
                    'customer_phone' => $order->customer_phone_snapshot,
                    'customer_email' => $order->customer_email_snapshot,
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->order_number,
                    'notes' => $payment['notes'] ?? $order->notes,
                ],
                'notes' => $payment['notes'] ?? $order->notes,
            ]);

            foreach ($order->details as $detail) {
                $lineSubtotal = (float) $detail->line_subtotal;
                $lineTotal = (float) $detail->line_total;

                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $detail->product_id,
                    'package_id' => $detail->package_id,
                    'unit_id' => $detail->unit_id,
                    'quantity' => $detail->requested_quantity,
                    'unit_price' => $detail->unit_price,
                    'subtotal' => $lineSubtotal,
                    'tax' => 0,
                    'discount' => 0,
                    'total' => $lineTotal,
                ]);
            }

            // 3. Decrement real stock via MovementService.
            $sale->load('details');
            $sale->validateAndUpdateStock();

            // 4. Link the sale and mark the order as delivered.
            $order->forceFill([
                'sale_id' => $sale->id,
                'status' => SalesOrderStatus::DELIVERED,
                'delivered_at' => $order->delivered_at ?: now(),
            ])->save();

            foreach ($order->details as $detail) {
                $detail->forceFill([
                    'delivered_quantity' => $detail->requested_quantity,
                ])->save();
            }

            return $sale->fresh('details');
        });
    }
}