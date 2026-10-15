<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\InventoryTransfer;
use App\Enums\TransferStatus;
use App\Enums\StockLedgerReason;
use App\Support\StockContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notifications\NotificationEngine;

class TransferService
{
    protected MovementService $movementService;

    public function __construct(MovementService $movementService)
    {
        $this->movementService = $movementService;
    }
    /**
     * Relee la transferencia bloqueando su fila hasta el fin de la
     * transacción. Cada paso decide con esta copia, no con la que el
     * controlador cargó antes: dos pasos simultáneos sobre la misma
     * transferencia (recibir y cancelar, enviar y cancelar, dos
     * cancelaciones) se esperan y el segundo ve el estado que dejó el primero.
     * Antes cancelar y rechazar decidían con el estado viejo y podían devolver
     * al origen lo que ya se había recibido en el destino.
     */
    protected function lockForWorkflow(InventoryTransfer $transfer): InventoryTransfer
    {
        $locked = InventoryTransfer::whereKey($transfer->id)->lockForUpdate()->first();

        if (!$locked) {
            throw new BusinessRuleException('La transferencia ya no existe', 'not_found');
        }

        return $locked;
    }

    /**
     * Aprobar transferencia
     */
    public function approve(InventoryTransfer $transfer, array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transfer, $data) {
            $transfer = $this->lockForWorkflow($transfer);

            if ($transfer->status !== TransferStatus::PENDING) {
                throw new BusinessRuleException('Solo se pueden aprobar transferencias pendientes', 'invalid_status');
            }

            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            // Las cantidades que ajusta quien aprueba se aplican al detalle.
            // Antes solo quedaban en content.step_1.items y el paso de envío
            // seguía proponiendo la cantidad original solicitada.
            foreach ($data['items'] ?? [] as $item) {
                if (!isset($item['detail_id'], $item['quantity_requested'])) {
                    continue;
                }

                $detail = $transfer->details()->find((int) $item['detail_id']);
                if (!$detail) {
                    throw new BusinessRuleException('Detalle de transferencia no encontrado', 'invalid_detail');
                }

                $detail->quantity_requested = (float) $item['quantity_requested'];
                $detail->save();
            }

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

            return $transfer;
        });

        $this->notifyTransferUpdate($transfer, 'approved', [$transfer->requested_by]);
        return $transfer->load(['details.product.mainImage', 'fromLocation', 'toLocation']);
    }

    /**
     * Rechazar transferencia
     */
    public function reject(InventoryTransfer $transfer, string $reason): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transfer, $reason) {
            $transfer = $this->lockForWorkflow($transfer);

            if (in_array($transfer->status, [TransferStatus::COMPLETED, TransferStatus::CANCELLED, TransferStatus::REJECTED], true)) {
                throw new BusinessRuleException('Esta transferencia ya se encuentra en estado final y no puede rechazarse', 'invalid_status');
            }

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

            // Si la mercancía ya salió del origen, vuelve a su stock.
            if ($currentStatus === TransferStatus::IN_TRANSIT->value) {
                $this->returnShippedStock($transfer, 'Traspaso rechazado en tránsito');
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

            return $transfer;
        });

        $this->notifyTransferUpdate($transfer, 'rejected', [$transfer->requested_by], ['reason' => $reason]);
        return $transfer->load(['details.product.mainImage', 'fromLocation', 'toLocation']);
    }

    /**
     * Cancelar transferencia. Si ya estaba en tránsito, lo enviado vuelve al
     * stock del origen; antes se cancelaba sin devolverlo y esas existencias
     * desaparecían.
     */
    public function cancel(InventoryTransfer $transfer, string $reason): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = $this->lockForWorkflow($transfer);

            if (!$transfer->status->canCancel()) {
                throw new BusinessRuleException('Esta transferencia no puede ser cancelada', 'invalid_status');
            }

            if ($transfer->status === TransferStatus::IN_TRANSIT) {
                $this->returnShippedStock($transfer, 'Traspaso cancelado en tránsito');
            }

            $transfer->cancel($reason);

            // Se avisa a la otra parte (quien la pidió y quien actuó en origen).
            $this->notifyTransferUpdate($transfer, 'cancelled', [$transfer->requested_by, $this->originActor($transfer)], ['reason' => $reason]);

            return $transfer;
        });
    }

    /**
     * Devuelve al origen lo que se descontó al enviar.
     */
    protected function returnShippedStock(InventoryTransfer $transfer, string $notes): void
    {
        // Por producto, como ship() y receive(): las transacciones que tocan
        // los mismos productos los bloquean en el mismo orden.
        foreach ($transfer->details()->orderBy('product_id')->orderBy('id')->get() as $detail) {
            $quantity = (float) ($detail->quantity_shipped ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $this->movementService->increment(
                (int) $transfer->from_location_id,
                (int) $detail->product_id,
                (int) $detail->unit_id,
                $quantity,
                $detail->package_id,
                new StockContext(
                    reason: StockLedgerReason::TRANSFER_IN,
                    reference: $transfer,
                    notes: $notes,
                    counterpartLocationId: $transfer->to_location_id ? (int) $transfer->to_location_id : null,
                )
            );
        }
    }

    /**
     * Preparar y enviar transferencia
     * Registra productos realmente enviados y descuenta stock de origen
     */
    public function ship(InventoryTransfer $transfer, array $shipments): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transfer, $shipments) {
            // Bloqueo de fila + relectura del estado dentro de la transacción:
            // evita que un doble envío (doble clic/dos dispositivos) procese el
            // envío dos veces.
            $transfer = $this->lockForWorkflow($transfer);
            if ($transfer->status !== TransferStatus::APPROVED) {
                throw new BusinessRuleException('Solo se pueden enviar transferencias aprobadas', 'invalid_status');
            }

            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            $totalCost = 0;

            // Por producto: dos transacciones que mueven los mismos productos
            // bloquean sus existencias en el mismo orden y no se cruzan.
            usort($shipments, fn(array $a, array $b) => [(int) $a['product_id'], (int) $a['transfer_detail_id']]
                <=> [(int) $b['product_id'], (int) $b['transfer_detail_id']]);

            // Procesar cada envío directamente sobre el detalle
            foreach ($shipments as $shipmentData) {
                $detail = $transfer->details()->find($shipmentData['transfer_detail_id']);

                if (!$detail) {
                    throw new BusinessRuleException('Detalle de transferencia no encontrado', 'invalid_detail');
                }

                // Validar que no se envíe más de lo solicitado
                if ($shipmentData['quantity_shipped'] > $detail->quantity_requested) {
                    throw new BusinessRuleException(
                        "No se puede enviar más de lo solicitado. Solicitado: {$detail->quantity_requested}, Intentando enviar: {$shipmentData['quantity_shipped']}",
                        'quantity_exceeded'
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

                // Un renglón aprobado en 0 no mueve stock (y un decrement de 0
                // no afecta filas, lo que se leería como fallo).
                if ((float) $detail->quantity_shipped <= 0) {
                    continue;
                }

                // Usar MovementService para decrementar stock
                // MovementService maneja la conversión de paquetes automáticamente
                $this->movementService->decrement(
                    $transfer->from_location_id,
                    $detail->product_id,
                    $detail->unit_id,
                    (float) $detail->quantity_shipped,
                    $detail->package_id,
                    new StockContext(
                        reason: StockLedgerReason::TRANSFER_OUT,
                        reference: $transfer,
                        counterpartLocationId: $transfer->to_location_id ? (int) $transfer->to_location_id : null,
                    )
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

            return $transfer;
        });

        $this->notifyTransferUpdate($transfer, 'shipped', [$transfer->requested_by]);
        return $transfer->load([
            'details.product.mainImage',
            'fromLocation',
            'toLocation'
        ]);
    }

    /**
     * Recibir transferencia
     * Registra cantidades recibidas y aumenta stock en destino
     */
    public function receive(InventoryTransfer $transfer, array $receivedData): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transfer, $receivedData) {
            // Bloqueo de fila + relectura del estado: evita que un doble envío
            // reciba la transferencia dos veces (sumaría stock por duplicado).
            $transfer = $this->lockForWorkflow($transfer);
            if ($transfer->status !== TransferStatus::IN_TRANSIT) {
                throw new BusinessRuleException('Solo se pueden recibir transferencias en tránsito', 'invalid_status');
            }

            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }
            $content = $this->normalizeWorkflowProgress($content);

            // Por producto, igual que ship(): mismo orden de bloqueo.
            $details = $transfer->details()->get()->keyBy('id');
            usort($receivedData, fn(array $a, array $b) => [
                (int) ($details->get((int) ($a['detail_id'] ?? 0))?->product_id ?? 0),
                (int) ($a['detail_id'] ?? 0),
            ] <=> [
                (int) ($details->get((int) ($b['detail_id'] ?? 0))?->product_id ?? 0),
                (int) ($b['detail_id'] ?? 0),
            ]);

            // Procesar cada recepción directamente sobre el detalle
            foreach ($receivedData as $item) {
                $detailId = (int) ($item['detail_id'] ?? 0);
                $detail = $details->get($detailId);

                if (!$detail) {
                    throw new BusinessRuleException('Detalle de transferencia no encontrado', 'invalid_detail');
                }

                $quantityReceived = (float) $item['quantity_received'];
                $quantityShipped = (float) $detail->quantity_shipped;

                if ($quantityReceived > $quantityShipped) {
                    throw new BusinessRuleException('La cantidad recibida no puede superar la enviada', 'quantity_exceeded');
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

                if ($quantityReceived <= 0) {
                    continue;
                }

                // Usar MovementService para incrementar stock en destino
                // MovementService maneja la conversión de paquetes automáticamente
                $this->movementService->increment(
                    $transfer->to_location_id,
                    $detail->product_id,
                    $detail->unit_id,
                    $quantityReceived,
                    $detail->package_id,
                    new StockContext(
                        reason: StockLedgerReason::TRANSFER_IN,
                        reference: $transfer,
                        counterpartLocationId: $transfer->from_location_id ? (int) $transfer->from_location_id : null,
                    )
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

            return $transfer;
        });

        $this->notifyTransferUpdate($transfer, 'received', [$this->originActor($transfer)], [
            'with_differences' => $transfer->details()->get()->contains(
                fn($detail) => (float) $detail->quantity_received !== (float) $detail->quantity_shipped
            ),
        ]);
        return $transfer->load([
            'details.product.mainImage',
            'fromLocation',
            'toLocation'
        ]);
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

    /**
     * Aviso de avance de la transferencia. Las tareas (aprobar, enviar,
     * recibir) ya avisan a quien le toca actuar; esto avisa a la otra parte.
     * Quien hizo la acción no se avisa a sí mismo. Sale al confirmar la
     * transacción (NotificationEngine::afterCommit).
     *
     * @param array<int|null> $userIds
     */
    private function notifyTransferUpdate(InventoryTransfer $transfer, string $subType, array $userIds, array $extra = []): void
    {
        try {
            $transfer->loadMissing(['fromLocation', 'toLocation']);

            $recipients = collect($userIds)
                ->filter()
                ->map(fn($id) => (int) $id)
                ->unique()
                ->reject(fn($id) => $id === (int) Auth::id());

            foreach ($recipients as $userId) {
                NotificationEngine::dispatch('transfer_update', (int) $transfer->company_id, array_merge([
                    'transfer'   => $transfer,
                    'sub_type'   => $subType,
                    'actor_name' => Auth::user()?->name ?? 'Sistema',
                ], $extra), userId: $userId);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar el avance de la transferencia', [
                'transfer_id' => $transfer->id,
                'sub_type'    => $subType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** Quien la envió (o, si no llegó a enviarse, quien la aprobó) en origen. */
    private function originActor(InventoryTransfer $transfer): ?int
    {
        $content = (array) ($transfer->content ?? []);

        $id = data_get($content, 'step_2.shipped_by')
            ?? data_get($content, 'workflow.step_2.shipped_by')
            ?? data_get($content, 'step_1.approved_by')
            ?? data_get($content, 'workflow.step_1.approved_by');

        return $id ? (int) $id : null;
    }
}
