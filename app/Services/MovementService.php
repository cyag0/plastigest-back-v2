<?php

namespace App\Services;

use App\Models\Movement;
use App\Models\MovementDetail;
use App\Models\ProductKardex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class MovementService
{
    /**
     * Aprobar una transferencia (draft/ordered → ordered)
     */
    public function approve(Movement $movement, ?int $userId = null): Movement
    {
        if (!in_array($movement->status, ['draft', 'ordered'])) {
            throw new Exception('Solo se pueden aprobar transferencias en estado borrador u ordenado');
        }

        DB::beginTransaction();
        try {
            $content = $movement->content ?? [];
            $content['approved_by'] = $userId ?? Auth::id();
            $content['approved_at'] = now()->toISOString();

            $movement->status = 'ordered';
            $movement->content = $content;
            $movement->save();

            DB::commit();
            return $movement->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Rechazar una transferencia (ordered → rejected)
     */
    public function reject(Movement $movement, string $reason, ?int $userId = null): Movement
    {
        if ($movement->status !== 'ordered') {
            throw new Exception('Solo se pueden rechazar transferencias en estado ordenado');
        }

        DB::beginTransaction();
        try {
            $content = $movement->content ?? [];
            $content['rejected_by'] = $userId ?? Auth::id();
            $content['rejected_at'] = now()->toISOString();
            $content['rejection_reason'] = $reason;

            $movement->status = 'rejected';
            $movement->content = $content;
            $movement->save();

            DB::commit();
            return $movement->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Enviar una transferencia (ordered → in_transit)
     * Decrementa stock en ubicación origen y crea registros kardex
     */
    public function ship(Movement $movement, array $shipmentData, ?int $userId = null): Movement
    {
        if ($movement->status !== 'ordered') {
            throw new Exception('Solo se pueden enviar transferencias en estado ordenado');
        }

        DB::beginTransaction();
        try {
            // Obtener detalles directamente de la base de datos
            $details = $movement->details;

            if ($details->isEmpty()) {
                throw new Exception('No hay productos en esta transferencia');
            }

            foreach ($details as $detail) {
                $quantityToShip = $detail->quantity;

                // Validar que hay suficiente stock
                $this->validateStock($movement->location_origin_id, $detail->product_id, $quantityToShip);

                // Decrementar stock en origen
                $this->decrementStock($movement->location_origin_id, $detail->product_id, $quantityToShip);

                // Actualizar detalle con cantidad enviada
                $detailContent = $detail->content ?? [];
                $detailContent['quantity_shipped'] = $quantityToShip;
                $detailContent['shipped_at'] = now()->toISOString();

                $detail->content = $detailContent;
                $detail->save();

                // Registrar en kardex (salida)
                /* $this->registerKardex(
                    $movement->company_id,
                    $movement->location_origin_id,
                    $detail->product_id,
                    $movement->id,
                    $detail->id,
                    'exit',
                    'transfer_out',
                    $quantityToShip,
                    $detail->unit_cost,
                    $movement->content['transfer_number'] ?? 'MOV-' . $movement->id,
                    $detail->batch_number ?? null,
                    $detail->expiry_date ?? null
                ); */
            }

            // Actualizar estado del movimiento
            $content = $movement->content ?? [];
            $content['shipped_by'] = $userId ?? Auth::id();
            $content['shipped_at'] = now()->toISOString();
            $content['shipping_notes'] = $shipmentData['shipping_notes'] ?? null;

            $movement->status = 'in_transit';
            $movement->content = $content;
            $movement->save();

            DB::commit();
            return $movement->fresh(['details.product']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Recibir una transferencia (in_transit → received/closed)
     * Incrementa stock en ubicación destino y crea registros kardex
     */
    public function receive(Movement $movement, array $receiptData, ?int $userId = null): Movement
    {
        if ($movement->status !== 'in_transit') {
            throw new Exception('Solo se pueden recibir transferencias en estado en tránsito');
        }

        DB::beginTransaction();
        try {
            // Obtener detalles directamente de la base de datos
            $details = $movement->details;

            if ($details->isEmpty()) {
                throw new Exception('No hay productos en esta transferencia');
            }

            foreach ($details as $detail) {
                $detailContent = $detail->content ?? [];
                $quantityShipped = $detailContent['quantity_shipped'] ?? $detail->quantity;

                // Incrementar stock en destino con la cantidad enviada
                $this->incrementStock($movement->location_destination_id, $detail->product_id, $quantityShipped);

                // Actualizar detalle con cantidad recibida
                $detailContent['quantity_received'] = $quantityShipped;
                $detailContent['received_at'] = now()->toISOString();

                $detail->content = $detailContent;
                $detail->save();

                // Registrar en kardex (entrada)
                /* $this->registerKardex(
                    $movement->company_id,
                    $movement->location_destination_id,
                    $detail->product_id,
                    $movement->id,
                    $detail->id,
                    'entry',
                    'transfer_in',
                    $quantityShipped,
                    $detail->unit_cost,
                    $movement->content['transfer_number'] ?? 'MOV-' . $movement->id,
                    $detail->batch_number ?? null,
                    $detail->expiry_date ?? null
                ); */
            }

            // Actualizar estado del movimiento
            $content = $movement->content ?? [];
            $content['received_by'] = $userId ?? Auth::id();
            $content['received_at'] = now()->toISOString();
            $content['receiving_notes'] = $receiptData['receiving_notes'] ?? null;
            $content['received_complete'] = true;

            $movement->status = 'closed';
            $movement->content = $content;
            $movement->save();

            DB::commit();
            return $movement->fresh(['details.product']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validar que hay suficiente stock para enviar
     */
    protected function validateStock(int $locationId, int $productId, float $quantity): void
    {
        $stock = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('current_stock');

        if ($stock === null || $stock < $quantity) {
            throw new Exception("Stock insuficiente para el producto ID {$productId}. Disponible: {$stock}, Solicitado: {$quantity}");
        }
    }

    /**
     * Decrementar stock en una ubicación
     */
    protected function decrementStock(int $locationId, int $productId, float $quantity): void
    {
        $updated = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where('current_stock', '>=', $quantity)
            ->decrement('current_stock', $quantity);

        if (!$updated) {
            throw new Exception("No se pudo decrementar el stock para el producto ID {$productId}");
        }
    }

    /**
     * Incrementar stock en una ubicación
     */
    protected function incrementStock(int $locationId, int $productId, float $quantity): void
    {
        $exists = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            DB::table('product_location')
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->increment('current_stock', $quantity);
        } else {
            DB::table('product_location')->insert([
                'location_id' => $locationId,
                'product_id' => $productId,
                'current_stock' => $quantity,
                'minimum_stock' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Registrar operación en kardex
     */
    protected function registerKardex(
        int $companyId,
        int $locationId,
        int $productId,
        int $movementId,
        int $detailId,
        string $operationType,
        string $operationReason,
        float $quantity,
        ?float $unitCost,
        ?string $documentNumber = null,
        ?string $batch = null,
        ?string $expiryDate = null
    ): void {
        // Obtener el último registro del kardex para calcular el saldo
        $lastKardex = ProductKardex::where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->orderBy('operation_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $previousBalance = $lastKardex ? $lastKardex->balance : 0;
        $previousValue = $lastKardex ? $lastKardex->balance_value : 0;
        $previousAverage = $previousBalance > 0 ? ($previousValue / $previousBalance) : 0;

        // Calcular nuevos valores según el tipo de operación
        if ($operationType === 'entry') {
            $entryQty = $quantity;
            $exitQty = 0;
            $newBalance = $previousBalance + $quantity;
            $entryValue = $quantity * ($unitCost ?? $previousAverage);
            $newValue = $previousValue + $entryValue;
        } else {
            $entryQty = 0;
            $exitQty = $quantity;
            $newBalance = $previousBalance - $quantity;
            $exitValue = $quantity * ($unitCost ?? $previousAverage);
            $newValue = $previousValue - $exitValue;
        }

        $newAverage = $newBalance > 0 ? ($newValue / $newBalance) : 0;

        ProductKardex::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'movement_id' => $movementId,
            'movement_detail_id' => $detailId,
            'operation_type' => $operationType,
            'operation_reason' => $operationReason,
            'operation_date' => now(),
            'document_number' => $documentNumber,
            'batch_number' => $batch,
            'expiry_date' => $expiryDate,
            'entry_quantity' => $entryQty,
            'exit_quantity' => $exitQty,
            'balance' => $newBalance,
            'unit_cost' => $unitCost ?? $previousAverage,
            'entry_value' => $entryValue ?? 0,
            'exit_value' => $exitValue ?? 0,
            'balance_value' => $newValue,
            'average_cost' => $newAverage,
        ]);
    }
}
