<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesOrder;
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
}