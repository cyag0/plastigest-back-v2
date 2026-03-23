<?php

namespace App\Services;

use App\Models\InventoryTransfer;
use App\Enums\TransferStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class TransferService
{
    protected MovementService $movementService;

    public function __construct(MovementService $movementService)
    {
        $this->movementService = $movementService;
    }
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
            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            $transfer->status = TransferStatus::APPROVED;
            $content['current_step'] = 2;
            $content['flow_state'] = 'in_progress';
            $content['ended_at_step'] = null;
            $content['step_1'] = [
                'status' => 'approved',
                'approved_at' => now()->toISOString(),
                'approved_by' => Auth::id(),
                'items' => $data['items'] ?? [],
            ];
            $content['step_2'] = [
                'status' => 'pending',
            ];
            $content['step_3'] = [
                'status' => 'pending',
            ];
            $content['step_4'] = [
                'status' => 'pending',
            ];
            $this->markProgress($content, 'step_1', true, 'completed', false);
            $this->markProgress($content, 'step_2', false, 'pending', false);
            $this->markProgress($content, 'step_3', false, 'pending', false);
            $this->markProgress($content, 'step_4', false, 'pending', false);
            $transfer->content = $content;
            $transfer->save();

            DB::commit();
            return $transfer->load(['details.product.mainImage', 'fromLocation', 'toLocation']);
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
        if (in_array($transfer->status, [TransferStatus::COMPLETED, TransferStatus::CANCELLED, TransferStatus::REJECTED], true)) {
            throw new Exception("Esta transferencia ya se encuentra en estado final y no puede rechazarse");
        }

        DB::beginTransaction();
        try {
            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            $currentStatus = $transfer->status instanceof TransferStatus
                ? $transfer->status->value
                : (string) $transfer->status;
            $endedStep = 1;
            if ($currentStatus === TransferStatus::APPROVED->value) {
                $endedStep = 2;
            }
            if ($currentStatus === TransferStatus::IN_TRANSIT->value) {
                $endedStep = 3;
            }

            $transfer->status = TransferStatus::REJECTED;
            $content['current_step'] = 4;
            $content['flow_state'] = 'failed';
            $content['ended_at_step'] = $endedStep;
            $content['step_1'] = [
                'status' => 'rejected',
                'rejected_at' => now()->toISOString(),
                'rejected_by' => Auth::id(),
                'reason' => $reason,
            ];
            $content['step_2'] = [
                'status' => $endedStep >= 2 ? ($endedStep === 2 ? 'failed' : 'completed') : 'skipped',
            ];
            $content['step_3'] = [
                'status' => $endedStep >= 3 ? 'failed' : 'skipped',
            ];
            $content['step_4'] = [
                'status' => 'failed',
                'closed_at' => now()->toISOString(),
                'closed_by' => Auth::id(),
                'closed_reason' => $reason,
            ];
            $this->markProgress($content, 'step_1', true, $endedStep === 1 ? 'failed' : 'completed', $endedStep === 1);
            $this->markProgress($content, 'step_2', $endedStep >= 2, $endedStep >= 2 ? ($endedStep === 2 ? 'failed' : 'completed') : 'skipped', $endedStep === 2);
            $this->markProgress($content, 'step_3', $endedStep >= 3, $endedStep >= 3 ? 'failed' : 'skipped', $endedStep === 3);
            $this->markProgress($content, 'step_4', true, 'failed', true);
            $transfer->content = $content;
            $transfer->save();

            DB::commit();
            return $transfer->load(['details.product.mainImage', 'fromLocation', 'toLocation']);
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
            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            $totalCost = 0;

            // Procesar cada envío directamente sobre el detalle
            foreach ($shipments as $shipmentData) {
                $detail = $transfer->details()->find($shipmentData['transfer_detail_id']);

                if (!$detail) {
                    throw new Exception("Detalle de transferencia no encontrado");
                }

                // Validar que no se envíe más de lo solicitado
                if ($shipmentData['quantity_shipped'] > $detail->quantity_requested) {
                    throw new Exception(
                        "No se puede enviar más de lo solicitado. Solicitado: {$detail->quantity_requested}, Intentando enviar: {$shipmentData['quantity_shipped']}"
                    );
                }

                // Actualizar cantidad enviada y campos operativos en detalle
                $detail->quantity_shipped = $shipmentData['quantity_shipped'];
                $detail->unit_cost = $shipmentData['unit_cost'] ?? $detail->unit_cost;
                $detail->batch_number = $shipmentData['batch_number'] ?? $detail->batch_number;
                $detail->expiry_date = $shipmentData['expiry_date'] ?? $detail->expiry_date;
                $detail->notes = $shipmentData['notes'] ?? $detail->notes;
                $detail->save();

                $totalCost += (float) $detail->quantity_shipped * (float) ($detail->unit_cost ?? 0);

                // Usar MovementService para decrementar stock
                // MovementService maneja la conversión de paquetes automáticamente
                $this->movementService->decrement(
                    $transfer->from_location_id,
                    $detail->product_id,
                    $detail->unit_id,
                    (float) $detail->quantity_shipped,
                    $detail->package_id
                );
            }

            // Actualizar transferencia
            $transfer->status = TransferStatus::IN_TRANSIT;
            $transfer->total_cost = $totalCost;
            $content['current_step'] = 3;
            $content['flow_state'] = 'in_progress';
            $content['ended_at_step'] = null;
            $content['step_2'] = [
                'status' => 'shipped',
                'shipped_at' => now()->toISOString(),
                'shipped_by' => Auth::id(),
                'items_count' => count($shipments),
            ];
            $content['step_3'] = [
                'status' => 'pending',
            ];
            $content['step_4'] = [
                'status' => 'pending',
            ];
            $this->markProgress($content, 'step_1', true, 'completed', false);
            $this->markProgress($content, 'step_2', true, 'completed', false);
            $this->markProgress($content, 'step_3', false, 'pending', false);
            $this->markProgress($content, 'step_4', false, 'pending', false);
            $transfer->content = $content;
            $transfer->save();

            DB::commit();
            return $transfer->load([
                'details.product.mainImage',
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
            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            // Procesar cada recepción directamente sobre el detalle
            foreach ($receivedData as $item) {
                $detailId = (int) ($item['detail_id'] ?? 0);
                $detail = $transfer->details()->find($detailId);

                if (!$detail) {
                    throw new Exception("Detalle de transferencia no encontrado");
                }

                $quantityReceived = (float) $item['quantity_received'];
                $quantityShipped = (float) $detail->quantity_shipped;

                if ($quantityReceived > $quantityShipped) {
                    throw new Exception("La cantidad recibida no puede superar la enviada");
                }

                // Actualizar cantidad recibida
                $detail->quantity_received = $quantityReceived;

                // Si hay diferencia, registrar reporte
                if ($quantityReceived < $quantityShipped) {
                    $difference = $quantityShipped - $quantityReceived;
                    $detail->damage_report = ($item['damage_report'] ?? "Faltante: {$difference} unidades");
                } else {
                    $detail->damage_report = null;
                }

                $detail->save();

                // Usar MovementService para incrementar stock en destino
                // MovementService maneja la conversión de paquetes automáticamente
                $this->movementService->increment(
                    $transfer->to_location_id,
                    $detail->product_id,
                    $detail->unit_id,
                    $quantityReceived,
                    $detail->package_id
                );
            }

            // Actualizar transferencia
            $transfer->status = TransferStatus::COMPLETED;
            $content['current_step'] = 4;
            $content['flow_state'] = 'completed';
            $content['ended_at_step'] = 3;
            $content['step_3'] = [
                'status' => 'received',
                'received_at' => now()->toISOString(),
                'received_by' => Auth::id(),
                'items_count' => count($receivedData),
            ];
            $content['step_4'] = [
                'status' => 'completed',
                'closed_at' => now()->toISOString(),
                'closed_by' => Auth::id(),
            ];
            $this->markProgress($content, 'step_1', true, 'completed', false);
            $this->markProgress($content, 'step_2', true, 'completed', false);
            $this->markProgress($content, 'step_3', true, 'completed', true);
            $this->markProgress($content, 'step_4', true, 'completed', true);
            $transfer->content = $content;
            $transfer->save();

            DB::commit();
            return $transfer->load([
                'details.product.mainImage',
                'fromLocation',
                'toLocation'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function normalizeWorkflowProgress(array $content): array
    {
        $default = InventoryTransfer::defaultWorkflowContent((int) ($content['step_1']['requested_by'] ?? 0));

        if (!isset($content['flow_state'])) {
            $content['flow_state'] = $default['flow_state'];
        }

        if (!array_key_exists('ended_at_step', $content)) {
            $content['ended_at_step'] = $default['ended_at_step'];
        }

        if (!isset($content['step_4']) || !is_array($content['step_4'])) {
            $content['step_4'] = $default['step_4'];
        }

        if (!isset($content['progress']) || !is_array($content['progress'])) {
            $content['progress'] = $default['progress'];
        }

        foreach (['step_1', 'step_2', 'step_3', 'step_4'] as $stepKey) {
            if (!isset($content['progress'][$stepKey]) || !is_array($content['progress'][$stepKey])) {
                $content['progress'][$stepKey] = $default['progress'][$stepKey];
            }
        }

        return $content;
    }

    private function markProgress(
        array &$content,
        string $stepKey,
        bool $visited,
        string $result,
        bool $endedHere
    ): void {
        if (!isset($content['progress']) || !is_array($content['progress'])) {
            $content['progress'] = [];
        }

        $content['progress'][$stepKey] = [
            'visited' => $visited,
            'result' => $result,
            'ended_here' => $endedHere,
            'updated_at' => now()->toISOString(),
        ];
    }
}
