<?php

namespace App\Services;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\InventoryTransferShipment;
use App\Models\Movement;
use App\Models\MovementDetail;
use App\Models\ProductKardex;
use App\Enums\TransferStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class TransferService
{
    /**
     * Aprobar transferencia
     */
    public function approve(InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        if ($transfer->status !== TransferStatus::PENDING) {
            throw new Exception("Solo se pueden aprobar transferencias pendientes");
        }

        DB::beginTransaction();
        try {
            $transfer->status = TransferStatus::APPROVED;
            $transfer->approved_by = Auth::id();
            $transfer->approved_at = now();
            $transfer->save();

            DB::commit();
            return $transfer->load(['details.product', 'fromLocation', 'toLocation']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Rechazar transferencia
     */
    public function reject(InventoryTransfer $transfer, string $reason): InventoryTransfer
    {
        if ($transfer->status !== TransferStatus::PENDING) {
            throw new Exception("Solo se pueden rechazar transferencias pendientes");
        }

        DB::beginTransaction();
        try {
            $transfer->status = TransferStatus::REJECTED;
            $transfer->rejected_at = now();
            $transfer->rejection_reason = $reason;
            $transfer->save();

            DB::commit();
            return $transfer->load(['details.product', 'fromLocation', 'toLocation']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Preparar y enviar transferencia
     * Registra productos realmente enviados y descuenta stock de origen
     */
    public function ship(InventoryTransfer $transfer, array $shipments): InventoryTransfer
    {
        if ($transfer->status !== TransferStatus::APPROVED) {
            throw new Exception("Solo se pueden enviar transferencias aprobadas");
        }

        DB::beginTransaction();
        try {
            // Validar stock antes de procesar
            foreach ($shipments as $shipmentData) {
                $stockRecord = DB::table('product_location_stock')
                    ->where('product_id', $shipmentData['product_id'])
                    ->where('location_id', $transfer->from_location_id)
                    ->where('company_id', $transfer->company_id)
                    ->first();

                if (!$stockRecord) {
                    $product = DB::table('products')->find($shipmentData['product_id']);
                    $location = DB::table('locations')->find($transfer->from_location_id);
                    throw new Exception(
                        "El producto '{$product->name}' no tiene stock registrado en '{$location->name}'. No se puede enviar este producto."
                    );
                }

                if ($stockRecord->current_stock < $shipmentData['quantity_shipped']) {
                    $product = DB::table('products')->find($shipmentData['product_id']);
                    throw new Exception(
                        "Stock insuficiente del producto '{$product->name}'. Disponible: {$stockRecord->current_stock}, Solicitado: {$shipmentData['quantity_shipped']}"
                    );
                }
            }

            $totalCost = 0;

            // Crear movimiento de SALIDA en ubicación de origen
            $exitMovement = Movement::create([
                'company_id' => $transfer->company_id,
                'location_origin_id' => $transfer->from_location_id,
                'location_destination_id' => $transfer->to_location_id,
                'movement_type' => 'exit',
                'movement_reason' => 'transfer',
                'reference_type' => 'transfer',
                'reference_id' => $transfer->id,
                'user_id' => Auth::id(),
                'movement_date' => now(),
                'status' => 'closed',
            ]);

            // Procesar cada envío
            foreach ($shipments as $shipmentData) {
                $detail = $transfer->details()->find($shipmentData['transfer_detail_id']);
                
                if (!$detail) {
                    throw new Exception("Detalle de transferencia no encontrado");
                }

                // Crear registro de shipment
                $shipment = InventoryTransferShipment::create([
                    'transfer_detail_id' => $detail->id,
                    'product_id' => $shipmentData['product_id'],
                    'quantity_shipped' => $shipmentData['quantity_shipped'],
                    'unit_cost' => $shipmentData['unit_cost'] ?? $detail->unit_cost,
                    'batch_number' => $shipmentData['batch_number'] ?? null,
                    'expiry_date' => $shipmentData['expiry_date'] ?? null,
                    'notes' => $shipmentData['notes'] ?? null,
                ]);

                // Actualizar cantidad enviada en detail
                $detail->quantity_shipped = $shipmentData['quantity_shipped'];
                $detail->save();

                $totalCost += $shipment->total_cost;

                // Descuenta stock de la ubicación origen
                $this->decrementStock(
                    $transfer->from_location_id,
                    $shipmentData['product_id'],
                    $shipmentData['quantity_shipped']
                );

                // Registrar detalle del movimiento de salida
                $movementDetail = MovementDetail::create([
                    'movement_id' => $exitMovement->id,
                    'product_id' => $shipmentData['product_id'],
                    'quantity' => $shipmentData['quantity_shipped'],
                    'unit_cost' => $shipmentData['unit_cost'] ?? $detail->unit_cost,
                    'total_cost' => ($shipmentData['quantity_shipped'] * ($shipmentData['unit_cost'] ?? $detail->unit_cost)),
                    'batch_number' => $shipmentData['batch_number'] ?? null,
                ]);

                // Registrar en kardex (SALIDA)
                $this->registerKardex(
                    $transfer->company_id,
                    $transfer->from_location_id,
                    $shipmentData['product_id'],
                    $exitMovement->id,
                    $movementDetail->id,
                    'exit',
                    'transfer',
                    -$shipmentData['quantity_shipped'],
                    $shipmentData['unit_cost'] ?? $detail->unit_cost,
                    $transfer->transfer_number,
                    $shipmentData['batch_number'] ?? null,
                    $shipmentData['expiry_date'] ?? null
                );
            }

            // Actualizar transferencia
            $transfer->status = TransferStatus::IN_TRANSIT;
            $transfer->shipped_by = Auth::id();
            $transfer->shipped_at = now();
            $transfer->total_cost = $totalCost;
            $transfer->save();

            DB::commit();
            return $transfer->load([
                'details.product',
                'details.shipments',
                'fromLocation',
                'toLocation'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Recibir transferencia
     * Registra cantidades recibidas y aumenta stock en destino
     */
    public function receive(InventoryTransfer $transfer, array $receivedData): InventoryTransfer
    {
        if ($transfer->status !== TransferStatus::IN_TRANSIT) {
            throw new Exception("Solo se pueden recibir transferencias en tránsito");
        }

        DB::beginTransaction();
        try {
            // Crear movimiento de ENTRADA en ubicación de destino
            $entryMovement = Movement::create([
                'company_id' => $transfer->company_id,
                'location_origin_id' => $transfer->from_location_id,
                'location_destination_id' => $transfer->to_location_id,
                'movement_type' => 'entry',
                'movement_reason' => 'transfer',
                'reference_type' => 'transfer',
                'reference_id' => $transfer->id,
                'user_id' => Auth::id(),
                'movement_date' => now(),
                'status' => 'closed',
            ]);

            // Procesar cada recepción
            foreach ($receivedData as $item) {
                $shipment = InventoryTransferShipment::find($item['shipment_id']);
                
                if (!$shipment) {
                    throw new Exception("Envío no encontrado");
                }

                $detail = $shipment->transferDetail;
                $quantityReceived = $item['quantity_received'];

                // Actualizar cantidad recibida
                $detail->quantity_received = $quantityReceived;
                
                // Si hay diferencia, registrar reporte
                if ($quantityReceived < $shipment->quantity_shipped) {
                    $difference = $shipment->quantity_shipped - $quantityReceived;
                    $detail->damage_report = ($item['damage_report'] ?? "Faltante: {$difference} unidades");
                } elseif ($quantityReceived > $shipment->quantity_shipped) {
                    $difference = $quantityReceived - $shipment->quantity_shipped;
                    $detail->damage_report = "Sobrante: {$difference} unidades";
                }
                
                $detail->save();

                // Incrementar stock en ubicación destino
                $this->incrementStock(
                    $transfer->to_location_id,
                    $shipment->product_id,
                    $quantityReceived
                );

                // Registrar detalle del movimiento de entrada
                $movementDetail = MovementDetail::create([
                    'movement_id' => $entryMovement->id,
                    'product_id' => $shipment->product_id,
                    'quantity' => $quantityReceived,
                    'unit_cost' => $shipment->unit_cost,
                    'total_cost' => ($quantityReceived * $shipment->unit_cost),
                    'batch_number' => $shipment->batch_number,
                ]);

                // Registrar en kardex (ENTRADA)
                $this->registerKardex(
                    $transfer->company_id,
                    $transfer->to_location_id,
                    $shipment->product_id,
                    $entryMovement->id,
                    $movementDetail->id,
                    'entry',
                    'transfer',
                    $quantityReceived,
                    $shipment->unit_cost,
                    $transfer->transfer_number,
                    $shipment->batch_number,
                    $shipment->expiry_date
                );
            }

            // Actualizar transferencia
            $transfer->status = TransferStatus::COMPLETED;
            $transfer->received_by = Auth::id();
            $transfer->received_at = now();
            $transfer->save();

            DB::commit();
            return $transfer->load([
                'details.product',
                'details.shipments',
                'fromLocation',
                'toLocation'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Decrementar stock en ubicación
     */
    private function decrementStock(int $locationId, int $productId, float $quantity): void
    {
        $stockRecord = DB::table('product_location_stock')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        if (!$stockRecord) {
            throw new Exception(
                "El producto ID {$productId} no tiene stock registrado en la ubicación ID {$locationId}"
            );
        }

        if ($stockRecord->current_stock < $quantity) {
            throw new Exception(
                "Stock insuficiente. Disponible: {$stockRecord->current_stock}, Requerido: {$quantity}"
            );
        }

        DB::table('product_location_stock')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->decrement('current_stock', $quantity);
    }

    /**
     * Incrementar stock en ubicación
     */
    private function incrementStock(int $locationId, int $productId, float $quantity): void
    {
        $stockRecord = DB::table('product_location_stock')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        if ($stockRecord) {
            // Si existe, incrementar
            DB::table('product_location_stock')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->increment('current_stock', $quantity);
        } else {
            // Si no existe, crear registro con los datos mínimos necesarios
            $location = DB::table('locations')->find($locationId);
            if (!$location) {
                throw new Exception("Ubicación ID {$locationId} no encontrada");
            }
            
            DB::table('product_location_stock')->insert([
                'company_id' => $location->company_id,
                'location_id' => $locationId,
                'product_id' => $productId,
                'current_stock' => $quantity,
                'reserved_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => null,
                'average_cost' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Registrar movimiento en kardex
     */
    private function registerKardex(
        int $companyId,
        int $locationId,
        int $productId,
        int $movementId,
        int $movementDetailId,
        string $operationType,
        string $operationReason,
        float $quantity,
        float $unitCost,
        ?string $documentNumber = null,
        ?string $batchNumber = null,
        ?string $expiryDate = null
    ): void {
        // Obtener stock anterior
        $stockRecord = DB::table('product_location_stock')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $previousStock = $stockRecord ? $stockRecord->current_stock : 0;
        $previousAverageCost = $stockRecord ? $stockRecord->average_cost : 0;

        // Calcular nuevo stock basado en tipo de operación
        $newStock = $operationType === 'entry' 
            ? $previousStock + $quantity 
            : $previousStock - abs($quantity);

        // Calcular costo promedio ponderado
        $runningAverageCost = $previousAverageCost;
        if ($operationType === 'entry' && $newStock > 0) {
            $totalValue = ($previousStock * $previousAverageCost) + ($quantity * $unitCost);
            $runningAverageCost = $totalValue / $newStock;
        }

        ProductKardex::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'movement_id' => $movementId,
            'movement_detail_id' => $movementDetailId,
            'operation_type' => $operationType,
            'operation_reason' => $operationReason,
            'quantity' => $operationType === 'entry' ? $quantity : -abs($quantity),
            'unit_cost' => $unitCost,
            'total_cost' => ($quantity * $unitCost),
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'running_average_cost' => $runningAverageCost,
            'document_number' => $documentNumber,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'user_id' => Auth::id(),
            'operation_date' => now(),
        ]);

        // Actualizar el costo promedio en el stock si es una entrada
        if ($operationType === 'entry' && $stockRecord) {
            DB::table('product_location_stock')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->update(['average_cost' => $runningAverageCost]);
        }
    }
}
