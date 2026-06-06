<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\Purchase;
use App\Models\PurchaseV2;
use App\Models\InventoryCount;
use App\Models\Adjustment;
use App\Models\Transfer;
use App\Models\InventoryTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\NotificationEngine;
use InvalidArgumentException;

class TaskService
{
    public function __construct() {}

    /**
     * Create task from purchase confirmation
     */
    public function createFromPurchase(Purchase $purchase): Task
    {
        $task = Task::create([
            'company_id' => $purchase->company_id,
            'location_id' => $purchase->location_id,
            'title' => "Recibir compra #{$purchase->id}",
            'description' => "Recibir y verificar productos de la compra del proveedor {$purchase->supplier->name}",
            'type' => 'receive_purchase',
            'priority' => 'high',
            'assigned_by' => $purchase->created_by,
            'due_date' => $purchase->expected_delivery_date ?? now()->addDays(3),
            'related_type' => Purchase::class,
            'related_id' => $purchase->id,
            'metadata' => [
                'purchase_id' => $purchase->id,
                'supplier_name' => $purchase->supplier->name,
                'total' => $purchase->total,
            ],
        ]);

        // Assign to location manager or warehouse workers
        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Create task from inventory count discrepancies
     */
    public function createFromInventoryCount(InventoryCount $count): ?Task
    {
        $discrepancies = $count->items()->where('variance', '!=', 0)->count();

        if ($discrepancies === 0) {
            return null;
        }

        $task = Task::create([
            'company_id' => $count->company_id,
            'location_id' => $count->location_id,
            'title' => "Revisar diferencias en conteo #{$count->id}",
            'description' => "Se encontraron {$discrepancies} productos con diferencias en el conteo de inventario",
            'type' => 'stock_check',
            'priority' => $discrepancies > 10 ? 'urgent' : 'high',
            'assigned_by' => $count->performed_by,
            'due_date' => now()->addDay(),
            'related_type' => InventoryCount::class,
            'related_id' => $count->id,
            'metadata' => [
                'inventory_count_id' => $count->id,
                'discrepancies' => $discrepancies,
                'count_date' => $count->count_date,
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Create task from adjustment (if requires review)
     */
    public function createFromAdjustment(Adjustment $adjustment): ?Task
    {
        // Only create task if adjustment is significant
        $totalImpact = abs($adjustment->items()->sum('quantity'));

        if ($totalImpact < 10) {
            return null;
        }

        $task = Task::create([
            'company_id' => $adjustment->company_id,
            'location_id' => $adjustment->location_id,
            'title' => "Revisar ajuste #{$adjustment->id}",
            'description' => "Revisar y aprobar ajuste de inventario: {$adjustment->reason}",
            'type' => 'adjustment_review',
            'priority' => $totalImpact > 50 ? 'urgent' : 'medium',
            'assigned_by' => $adjustment->created_by,
            'due_date' => now()->addHours(24),
            'related_type' => Adjustment::class,
            'related_id' => $adjustment->id,
            'metadata' => [
                'adjustment_id' => $adjustment->id,
                'reason' => $adjustment->reason,
                'total_items' => $totalImpact,
            ],
        ]);

        // Assign to manager for approval
        $this->autoAssignToManager($task);

        return $task;
    }

    /**
     * Create task from transfer request
     */
    public function createFromTransfer(Transfer $transfer, string $action = 'approve'): Task
    {
        $titles = [
            'approve' => "Aprobar transferencia #{$transfer->id}",
            'send' => "Enviar transferencia #{$transfer->id}",
            'receive' => "Recibir transferencia #{$transfer->id}",
        ];

        $descriptions = [
            'approve' => "Revisar y aprobar transferencia de {$transfer->originLocation->name} a {$transfer->destinationLocation->name}",
            'send' => "Preparar y enviar productos a {$transfer->destinationLocation->name}",
            'receive' => "Recibir y verificar productos de {$transfer->originLocation->name}",
        ];

        $types = [
            'approve' => 'approve_transfer',
            'send' => 'send_transfer',
            'receive' => 'receive_transfer',
        ];

        $task = Task::create([
            'company_id' => $transfer->company_id,
            'location_id' => $action === 'receive' ? $transfer->destination_location_id : $transfer->origin_location_id,
            'title' => $titles[$action],
            'description' => $descriptions[$action],
            'type' => $types[$action],
            'priority' => 'high',
            'assigned_by' => $transfer->created_by,
            'due_date' => now()->addDays($action === 'approve' ? 1 : 3),
            'related_type' => Transfer::class,
            'related_id' => $transfer->id,
            'metadata' => [
                'transfer_id' => $transfer->id,
                'origin' => $transfer->originLocation->name,
                'destination' => $transfer->destinationLocation->name,
                'total_items' => $transfer->items()->count(),
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Create task when a modern purchase (PurchaseV2) enters in_transit.
     */
    public function createFromPurchaseV2(PurchaseV2 $purchase): Task
    {
        $purchase->loadMissing(['supplier', 'details.product']);

        $existingTask = $this->findOpenRelatedTask(
            PurchaseV2::class,
            (int) $purchase->id,
            'receive_purchase'
        );

        if ($existingTask) {
            return $existingTask;
        }

        $supplierName = $purchase->supplier?->name ?? 'Proveedor';

        $task = Task::create([
            'company_id' => $purchase->company_id,
            'location_id' => $purchase->location_id,
            'title' => "Recibir compra {$purchase->purchase_number}",
            'description' => "Recibir y verificar productos de la compra a {$supplierName}",
            'type' => 'receive_purchase',
            'priority' => 'high',
            'assigned_by' => $purchase->user_id,
            'due_date' => $purchase->expected_delivery_date ?? now()->addDays(3),
            'related_type' => PurchaseV2::class,
            'related_id' => $purchase->id,
            'metadata' => [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'supplier_name' => $supplierName,
                'total' => $purchase->total,
                'items_count' => $purchase->details->count(),
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Create stock check task for receiving discrepancies on PurchaseV2.
     */
    public function createPurchaseDiscrepancyTask(PurchaseV2 $purchase, array $discrepancies): ?Task
    {
        if ($discrepancies === []) {
            return null;
        }

        $supplierName = $purchase->supplier?->name ?? 'Proveedor';
        $discrepanciesCount = count($discrepancies);

        $lines = collect($discrepancies)
            ->take(10)
            ->map(function (array $item): string {
                $name = $item['product_name'] ?? 'Producto';
                $ordered = (float) ($item['ordered_quantity'] ?? 0);
                $received = (float) ($item['received_quantity'] ?? 0);
                $difference = max(0, $ordered - $received);

                return "- {$name}: faltante {$difference} (pedido {$ordered}, recibido {$received})";
            })
            ->implode("\n");

        $task = Task::create([
            'company_id' => $purchase->company_id,
            'location_id' => $purchase->location_id,
            'title' => "Revisar diferencias de recepcion compra {$purchase->purchase_number}",
            'description' => "Se detectaron {$discrepanciesCount} diferencia(s) al recibir la compra de {$supplierName}.\n\n{$lines}",
            'type' => 'stock_check',
            'priority' => $discrepanciesCount > 5 ? 'urgent' : 'high',
            'assigned_by' => $purchase->received_by ?? $purchase->user_id,
            'due_date' => now()->addDay(),
            'related_type' => PurchaseV2::class,
            'related_id' => $purchase->id,
            'metadata' => [
                'source' => 'purchase_receive_discrepancy',
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'supplier_name' => $supplierName,
                'discrepancies_count' => $discrepanciesCount,
                'discrepancies' => $discrepancies,
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Encuentra la tarea de discrepancia de recepción abierta para una compra.
     * Filtra por metadata->>source porque el type 'stock_check' se reutiliza
     * para otros módulos (conteos, etc.).
     */
    public function findOpenPurchaseDiscrepancyTask(PurchaseV2 $purchase): ?Task
    {
        return Task::where('related_type', PurchaseV2::class)
            ->where('related_id', $purchase->id)
            ->where('type', 'stock_check')
            ->where('metadata->source', 'purchase_receive_discrepancy')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Devuelve los datos de la última resolución de discrepancia para una compra,
     * o null si nunca se resolvió una. La fuente de verdad es la tarea: si fue
     * completada, leemos su metadata.resolution.
     */
    public function getLatestPurchaseDiscrepancyResolution(PurchaseV2 $purchase): ?array
    {
        $task = Task::withTrashed()
            ->where('related_type', PurchaseV2::class)
            ->where('related_id', $purchase->id)
            ->where('type', 'stock_check')
            ->where('metadata->source', 'purchase_receive_discrepancy')
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (!$task) {
            return null;
        }

        $metadata = $task->metadata ?? [];
        $resolution = $metadata['resolution'] ?? null;

        if (!$resolution) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'resolution' => $resolution,
            'resolved_by_user_id' => $task->completed_by,
            'resolved_at' => optional($task->completed_at)->toIso8601String(),
            'discrepancies_count' => $metadata['discrepancies_count'] ?? null,
        ];
    }

    /**
     * Create transfer workflow task for modern InventoryTransfer flow.
     */
    public function createFromInventoryTransfer(InventoryTransfer $transfer, string $action = 'approve'): Task
    {
        $types = [
            'approve' => 'approve_transfer',
            'send' => 'send_transfer',
            'receive' => 'receive_transfer',
        ];

        if (!isset($types[$action])) {
            throw new InvalidArgumentException("Unsupported transfer task action: {$action}");
        }

        $transfer->loadMissing(['fromLocation', 'toLocation', 'details']);

        $taskType = $types[$action];

        $existingTask = $this->findOpenRelatedTask(
            InventoryTransfer::class,
            (int) $transfer->id,
            $taskType
        );

        if ($existingTask) {
            return $existingTask;
        }

        $originName = $transfer->fromLocation?->name ?? 'Origen';
        $destinationName = $transfer->toLocation?->name ?? 'Destino';

        $titles = [
            'approve' => "Aprobar transferencia {$transfer->transfer_number}",
            'send' => "Enviar transferencia {$transfer->transfer_number}",
            'receive' => "Recibir transferencia {$transfer->transfer_number}",
        ];

        $descriptions = [
            'approve' => "Revisar y aprobar transferencia de {$originName} hacia {$destinationName}",
            'send' => "Preparar y enviar productos de {$originName} hacia {$destinationName}",
            'receive' => "Recibir y verificar productos enviados desde {$originName}",
        ];

        $task = Task::create([
            'company_id' => $transfer->company_id,
            'location_id' => $action === 'receive' ? $transfer->to_location_id : $transfer->from_location_id,
            'title' => $titles[$action],
            'description' => $descriptions[$action],
            'type' => $taskType,
            'priority' => 'high',
            'assigned_by' => $transfer->requested_by,
            'due_date' => now()->addDays($action === 'approve' ? 1 : 2),
            'related_type' => InventoryTransfer::class,
            'related_id' => $transfer->id,
            'metadata' => [
                'transfer_id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'origin' => $originName,
                'destination' => $destinationName,
                'items_count' => $transfer->details->count(),
                'action' => $action,
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Create stock check task when a transfer is received with differences.
     */
    public function createTransferDiscrepancyTask(InventoryTransfer $transfer, array $differences): ?Task
    {
        if ($differences === []) {
            return null;
        }

        $transfer->loadMissing(['fromLocation', 'toLocation']);

        $originName = $transfer->fromLocation?->name ?? 'Origen';
        $destinationName = $transfer->toLocation?->name ?? 'Destino';

        $lines = collect($differences)
            ->take(10)
            ->map(function (array $item): string {
                $detailId = $item['detail_id'] ?? null;
                $shipped = (float) ($item['quantity_shipped'] ?? 0);
                $received = (float) ($item['quantity_received'] ?? 0);
                $difference = (float) ($item['difference'] ?? 0);

                return "- Detalle #{$detailId}: faltante {$difference} (enviado {$shipped}, recibido {$received})";
            })
            ->implode("\n");

        $task = Task::create([
            'company_id' => $transfer->company_id,
            'location_id' => $transfer->to_location_id,
            'title' => "Revisar diferencias de transferencia {$transfer->transfer_number}",
            'description' => "La transferencia de {$originName} hacia {$destinationName} se recibio con diferencias.\n\n{$lines}",
            'type' => 'stock_check',
            'priority' => 'urgent',
            'assigned_by' => $transfer->requested_by,
            'due_date' => now()->addDay(),
            'related_type' => InventoryTransfer::class,
            'related_id' => $transfer->id,
            'metadata' => [
                'source' => 'transfer_receive_discrepancy',
                'transfer_id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'differences_count' => count($differences),
                'differences' => $differences,
            ],
        ]);

        $this->autoAssignTask($task);

        return $task;
    }

    /**
     * Complete the latest open task of a specific type related to a model.
     */
    public function completeRelatedTask(string $relatedType, int $relatedId, string $taskType, ?int $completedBy = null): ?Task
    {
        $task = $this->findOpenRelatedTask($relatedType, $relatedId, $taskType);

        if (!$task) {
            return null;
        }

        $payload = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        if ($completedBy) {
            $payload['completed_by'] = $completedBy;
        }

        if ($task->status === 'pending' && !$task->started_at) {
            $payload['started_at'] = now();
        }

        $task->update($payload);

        return $task;
    }

    /**
     * Cancel open tasks for a related model.
     */
    public function cancelRelatedTasks(string $relatedType, int $relatedId, array $taskTypes = []): int
    {
        $query = Task::where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if ($taskTypes !== []) {
            $query->whereIn('type', $taskTypes);
        }

        return $query->update([
            'status' => 'cancelled',
        ]);
    }


    /**
     * Create recurring task (inventory count, reports, etc.)
     */
    public function createRecurringTask(array $data): Task
    {
        return Task::create([
            'company_id' => $data['company_id'],
            'location_id' => $data['location_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? null,
            'assigned_by' => $data['assigned_by'],
            'due_date' => $data['due_date'],
            'is_recurring' => true,
            'recurrence_frequency' => $data['recurrence_frequency'],
            'recurrence_day' => $data['recurrence_day'] ?? null,
            'recurrence_time' => $data['recurrence_time'] ?? '09:00',
            'next_occurrence' => $data['due_date'],
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * Generate recurring tasks that are due
     */
    public function generateDueRecurringTasks(): int
    {
        $recurringTasks = Task::recurring()
            ->where(function ($query) {
                $query->whereNull('next_occurrence')
                    ->orWhere('next_occurrence', '<=', now());
            })
            ->get();

        $generated = 0;

        foreach ($recurringTasks as $task) {
            $task->generateNextOccurrence();
            $generated++;
        }

        return $generated;
    }

    /**
     * Auto-assign task to appropriate user
     */
    private function autoAssignTask(Task $task): void
    {
        $users = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $task->company_id))
            ->when($task->location_id, fn($q) => $q->whereHas('locationRoles', fn($locationQuery) => $locationQuery->where('locations.id', $task->location_id)))
            ->get();

        // Assign to user with fewest pending tasks
        $assignedUser = $users->sortBy(function ($user) {
            return $user->assignedTasks()->pending()->count();
        })->first();

        if ($assignedUser) {
            $task->update(['assigned_to' => $assignedUser->id]);

            // Send notification
            NotificationEngine::dispatch('task_event', $task->company_id, [
                'task'       => $task,
                'sub_type'   => 'assigned',
                'actor_name' => 'Sistema',
            ], userId: $assignedUser->id);
        }
    }

    /**
     * Find latest open task by related model and type.
     */
    private function findOpenRelatedTask(string $relatedType, int $relatedId, string $taskType): ?Task
    {
        return Task::where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->where('type', $taskType)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Auto-assign to location manager
     */
    private function autoAssignToManager(Task $task): void
    {
        $manager = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $task->company_id))
            ->when($task->location_id, fn($q) => $q->whereHas('locationRoles', fn($locationQuery) => $locationQuery->where('locations.id', $task->location_id)))
            ->where(function ($q) use ($task) {
                $q->whereHas('roles', fn($roleQuery) => $roleQuery->where('name', 'manager'))
                    ->orWhereExists(function ($subquery) use ($task) {
                        $subquery->selectRaw('1')
                            ->from('user_location_roles')
                            ->join('roles', 'user_location_roles.role_id', '=', 'roles.id')
                            ->whereColumn('user_location_roles.user_id', 'users.id')
                            ->where('roles.name', 'manager');

                        if ($task->location_id) {
                            $subquery->where('user_location_roles.location_id', $task->location_id);
                        }
                    });
            })
            ->first();

        if ($manager) {
            $task->update(['assigned_to' => $manager->id]);

            // Send notification
            NotificationEngine::dispatch('task_event', $task->company_id, [
                'task'       => $task,
                'sub_type'   => 'assigned',
                'actor_name' => 'Sistema',
            ], userId: $manager->id);
        } else {
            // Fallback to any admin
            $this->autoAssignTask($task);
        }
    }

    /**
     * Mark task as overdue if past due date
     */
    public function markOverdueTasks(): int
    {
        return Task::overdue()
            ->update(['status' => 'overdue']);
    }

    /**
     * Send reminders for tasks due soon
     */
    public function sendDueTaskReminders(): int
    {
        $tasks = Task::pending()
            ->whereBetween('due_date', [now(), now()->addDay()])
            ->with('assignedTo')
            ->get();

        $sent = 0;

        foreach ($tasks as $task) {
            if ($task->assignedTo) {
                NotificationEngine::dispatch('task_event', $task->company_id, [
                    'task'       => $task,
                    'sub_type'   => 'overdue',
                    'actor_name' => 'Sistema',
                ], userId: $task->assignedTo->id);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Notificar asignación de tarea de recepción de compra
     */
    public function notifyPurchaseTaskCreated(Task $task, Purchase $purchase): void
    {
        try {
            // Notificar a usuarios con permiso de compras
            NotificationEngine::dispatch('purchase_update', $purchase->company_id, [
                'purchase'      => $purchase,
                'supplier_name' => $purchase->supplier->name,
                'sub_type'      => 'in_transit',
                'products'      => $purchase->details->map(fn($d) => [
                    'name'     => $d->product->name,
                    'quantity' => $d->quantity,
                ])->toArray(),
            ]);

            Log::info('Purchase task assignment notification sent', [
                'task_id' => $task->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying purchase task assignment', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notificar asignación de tarea de revisión de discrepancias
     */
    public function notifyDiscrepanciesTaskCreated(Task $task, InventoryCount $inventoryCount, array $discrepancies): void
    {
        try {
            $discrepanciesCount = count($discrepancies);

            // Notificar solo al usuario asignado (assigned_to)
            if ($task->assigned_to) {
                NotificationEngine::dispatch('task_event', $inventoryCount->company_id, [
                    'task'       => $task,
                    'sub_type'   => 'assigned',
                    'actor_name' => 'Sistema',
                ], userId: $task->assigned_to);
            }

            Log::info('Discrepancies task assignment notification sent', [
                'task_id' => $task->id,
                'assigned_to' => $task->assigned_to,
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying discrepancies task assignment', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
