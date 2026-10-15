<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\PurchaseV2;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\NotificationEngine;
use InvalidArgumentException;

class TaskService
{
    /** Permiso que hace falta para resolver cada tipo de tarea automática. */
    private const TASK_PERMISSIONS = [
        'receive_purchase' => 'purchases_update',
        'approve_transfer' => 'transfers_update',
        'send_transfer'    => 'transfers_update',
        'receive_transfer' => 'transfers_update',
        'stock_check'      => 'inventory_manage',
    ];

    public function __construct() {}

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
                'count_date' => $count->count_date?->toDateString(),
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
            // Fin del día esperado: medianoche se ve como "el día anterior" en
            // sucursales al oeste del servidor (Guayabitos, UTC-7).
            'due_date' => $purchase->expected_delivery_date?->copy()->endOfDay() ?? now()->addDays(3),
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
            'title' => "Revisar diferencias de recepción compra {$purchase->purchase_number}",
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
            $newTask = $task->generateNextOccurrence();
            if (!$newTask) {
                continue;
            }
            $generated++;

            if ($newTask->assigned_to) {
                NotificationEngine::dispatch('task_event', $newTask->company_id, [
                    'task'       => $newTask,
                    'sub_type'   => 'assigned',
                    'actor_name' => 'Sistema',
                ], userId: $newTask->assigned_to);
            } else {
                $this->autoAssignTask($newTask);
            }
        }

        return $generated;
    }

    /**
     * Asigna la tarea a quien puede resolverla y le avisa.
     *
     * Antes se elegía al usuario con menos pendientes entre todos los de la
     * sucursal, sin mirar permisos: "Aprobar transferencia" o "Recibir compra"
     * caían en cajeros que ni siquiera ven la pantalla de tareas, y el
     * encargado nunca se enteraba.
     */
    /** Asigna (por permiso) y avisa una tarea creada fuera de este servicio. */
    public function assignAndNotify(Task $task): void
    {
        $this->autoAssignTask($task);
    }

    private function autoAssignTask(Task $task): void
    {
        $assignee = $this->resolveAssignee($task);

        if (!$assignee) {
            Log::warning('Tarea sin responsable: nadie con permiso en la sucursal', [
                'task_id'     => $task->id,
                'type'        => $task->type,
                'location_id' => $task->location_id,
            ]);
            return;
        }

        $task->update(['assigned_to' => $assignee->id]);

        NotificationEngine::dispatch('task_event', $task->company_id, [
            'task'       => $task,
            'sub_type'   => 'assigned',
            'actor_name' => 'Sistema',
        ], userId: $assignee->id);
    }

    /**
     * Quien puede resolver la tarea: primero el personal de la sucursal con el
     * permiso (encargados); si no hay, cualquiera con el permiso en esa
     * sucursal (administradores); si tampoco, cualquiera de la empresa con el
     * permiso. Entre ellos, el que tenga menos tareas abiertas.
     */
    private function resolveAssignee(Task $task): ?User
    {
        $permission = self::TASK_PERMISSIONS[$task->type] ?? 'tasks_update';

        $candidates = $this->usersWithPermission($task, $permission, $task->location_id, branchRolesOnly: true);

        if ($candidates->isEmpty() && $task->location_id) {
            $candidates = $this->usersWithPermission($task, $permission, $task->location_id, branchRolesOnly: false);
        }

        if ($candidates->isEmpty()) {
            $candidates = $this->usersWithPermission($task, $permission, null, branchRolesOnly: false);
        }

        return $candidates
            ->sortBy(fn(User $user) => Task::where('assigned_to', $user->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->count())
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function usersWithPermission(Task $task, string $permission, ?int $locationId, bool $branchRolesOnly)
    {
        return User::query()
            ->where('users.is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $task->company_id))
            ->whereExists(function ($sub) use ($permission, $locationId, $branchRolesOnly) {
                $sub->selectRaw('1')
                    ->from('user_location_roles')
                    ->join('roles', 'roles.id', '=', 'user_location_roles.role_id')
                    ->join('rol_permission', 'rol_permission.role_id', '=', 'roles.id')
                    ->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
                    ->whereColumn('user_location_roles.user_id', 'users.id')
                    ->where('permissions.name', $permission);

                if ($locationId) {
                    $sub->where('user_location_roles.location_id', $locationId);
                }

                if ($branchRolesOnly) {
                    $sub->where(fn($q) => $q->whereNull('roles.is_system')->orWhere('roles.is_system', false));
                }
            })
            ->get();
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
     * Avisos programados (cada hora): "vence pronto" cuando falta menos de un
     * día y "vencida" cuando ya pasó la fecha. Cada aviso sale una sola vez por
     * tarea. El status no se toca: una tarea vencida sigue pendiente o en
     * proceso (is_overdue lo indica), así no desaparece de esas listas.
     *
     * @return array{due_soon: int, overdue: int}
     */
    public function notifyDueTasks(): array
    {
        $open = fn() => Task::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_date');

        $overdue = 0;
        $open()->where('due_date', '<', now())
            ->whereNull('overdue_notified_at')
            ->chunkById(100, function ($tasks) use (&$overdue) {
                foreach ($tasks as $task) {
                    NotificationEngine::dispatch('task_event', $task->company_id, [
                        'task'       => $task,
                        'sub_type'   => 'overdue',
                        'actor_name' => 'Sistema',
                    ], userId: $task->assigned_to);
                    // Si ya venció, el "vence pronto" ya no tiene caso.
                    $task->forceFill([
                        'overdue_notified_at'  => now(),
                        'due_soon_notified_at' => $task->due_soon_notified_at ?? now(),
                    ])->save();
                    $overdue++;
                }
            });

        $dueSoon = 0;
        $open()->whereBetween('due_date', [now(), now()->addDay()])
            ->whereNull('due_soon_notified_at')
            ->chunkById(100, function ($tasks) use (&$dueSoon) {
                foreach ($tasks as $task) {
                    NotificationEngine::dispatch('task_event', $task->company_id, [
                        'task'       => $task,
                        'sub_type'   => 'due_soon',
                        'actor_name' => 'Sistema',
                    ], userId: $task->assigned_to);
                    $task->forceFill(['due_soon_notified_at' => now()])->save();
                    $dueSoon++;
                }
            });

        return ['due_soon' => $dueSoon, 'overdue' => $overdue];
    }
}
