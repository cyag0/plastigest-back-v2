<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\Purchase;
use App\Models\InventoryCount;
use App\Models\Adjustment;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

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
        // Get users from the task's location
        $users = User::where('company_id', $task->company_id)
            ->where('location_id', $task->location_id)
            ->where('is_active', true)
            ->get();

        // Assign to user with fewest pending tasks
        $assignedUser = $users->sortBy(function ($user) {
            return $user->assignedTasks()->pending()->count();
        })->first();

        if ($assignedUser) {
            $task->update(['assigned_to' => $assignedUser->id]);

            // Send notification
            $this->notificationService->sendTaskAssigned($task, $assignedUser);
        }
    }

    /**
     * Auto-assign to location manager
     */
    private function autoAssignToManager(Task $task): void
    {
        $manager = User::where('company_id', $task->company_id)
            ->where('location_id', $task->location_id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->first();

        if ($manager) {
            $task->update(['assigned_to' => $manager->id]);

            // Send notification
            $this->notificationService->sendTaskAssigned($task, $manager);
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
                $this->notificationService->sendTaskReminder($task, $task->assignedTo);
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
            $title = "📋 Nueva Tarea: Recibir Compra";
            $message = "Se te ha asignado la tarea de recibir la compra de {$purchase->supplier->name}";

            $data = [
                'type' => 'task_assigned',
                'task_id' => $task->id,
                'task_type' => $task->type,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toISOString(),
                'purchase_id' => $purchase->id,
                'location_id' => $purchase->location_id,
            ];

            // Notificar a usuarios con permiso de compras
            NotificationService::notifyUsersWithPermission(
                $purchase->company_id,
                'purchases_manage',
                $title,
                $message,
                'task',
                $data
            );

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

            $title = "📋 Nueva Tarea: Revisar Discrepancias";
            $message = "Se te ha asignado revisar {$discrepanciesCount} discrepancia(s) del conteo '{$inventoryCount->name}'";

            $data = [
                'type' => 'task_assigned',
                'task_id' => $task->id,
                'task_type' => $task->type,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toISOString(),
                'inventory_count_id' => $inventoryCount->id,
                'discrepancies_count' => $discrepanciesCount,
                'location_id' => $inventoryCount->location_id,
                'user_id' => $inventoryCount->user_id,
            ];

            // Notificar solo al usuario asignado (assigned_to)
            if ($task->assigned_to) {
                NotificationService::create(
                    $task->assigned_to,
                    $inventoryCount->company_id,
                    $title,
                    $message,
                    'task',
                    $data
                );
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
