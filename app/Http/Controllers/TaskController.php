<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskService;
use App\Notifications\NotificationEngine;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(
        private TaskService $taskService
    ) {}

    /**
     * List users from the current location who can receive task assignments.
     */
    public function eligibleUsers(Request $request)
    {
        if (!CurrentWorker::hasPermission('tasks_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
            'type' => 'nullable|in:inventory_count,receive_purchase,approve_transfer,send_transfer,receive_transfer,sales_report,stock_check,adjustment_review,custom',
        ]);

        $company = CurrentCompany::get();
        $locationId = (int) $validated['location_id'];

        $users = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $company->id))
            ->whereHas('locationRoles', fn($q) => $q->where('locations.id', $locationId))
            ->get(['id', 'name', 'email']);

        $userIds = $users->pluck('id');

        $taskCountsQuery = Task::where('company_id', $company->id)
            ->where('location_id', $locationId)
            ->whereIn('assigned_to', $userIds)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if (!empty($validated['type'])) {
            $taskCountsQuery->where('type', $validated['type']);
        }

        $taskCounts = $taskCountsQuery
            ->select('assigned_to', DB::raw('count(*) as open_tasks_count'))
            ->groupBy('assigned_to')
            ->pluck('open_tasks_count', 'assigned_to');

        $users = $users
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'open_tasks_count' => (int) ($taskCounts[$user->id] ?? 0),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * List tasks with filters
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('tasks_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $user = $request->user();
        $company = CurrentCompany::get();

        $query = Task::with([
            'assignedTo:id,name,email',
            'assignedBy:id,name,email',
            'completedBy:id,name,email',
            'location:id,name',
            'related'
        ])->where('company_id', $company->id);

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'overdue') {
                $query->overdue();
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by assigned user
        if ($request->has('assigned_to')) {
            if ($request->assigned_to === 'me') {
                $query->where('assigned_to', $user->id);
            } else {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('due_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('due_date', '<=', $request->to_date);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'due_date');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        return $query->paginate($perPage);
    }

    /**
     * Get task by ID
     */
    public function show(Request $request, Task $task)
    {
        if (!CurrentWorker::hasPermission('tasks_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        if ($task->company_id !== $company->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $task->load([
            'assignedTo:id,name,email,avatar',
            'assignedBy:id,name,email,avatar',
            'completedBy:id,name,email,avatar',
            'location:id,name',
            'company:id,name',
            'related',
            'comments.user:id,name,avatar'
        ]);

        return response()->json([
            'data' => $task
        ]);
    }

    /**
     * Create new task
     */
    public function store(Request $request)
    {
        if (!CurrentWorker::hasPermission('tasks_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:inventory_count,receive_purchase,approve_transfer,send_transfer,receive_transfer,sales_report,stock_check,adjustment_review,custom',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'due_date' => 'nullable|date',
            'related_type' => 'nullable|string',
            'related_id' => 'nullable|integer',
            'is_recurring' => 'nullable|boolean',
            'recurrence_frequency' => 'nullable|in:daily,weekly,biweekly,monthly',
            'recurrence_day' => 'nullable|integer|min:1|max:31',
            'recurrence_time' => 'nullable|date_format:H:i',
            'metadata' => 'nullable|array',
        ]);

        $task = Task::create([
            ...$validated,
            'company_id' => $company->id,
            'assigned_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        $task->load(['assignedTo', 'assignedBy', 'location']);

        // Enviar notificación si se asignó a alguien
        if ($task->assigned_to) {
            NotificationEngine::dispatch('task_event', $company->id, [
                'task'       => $task,
                'sub_type'   => 'assigned',
                'actor_name' => $request->user()->name,
            ], userId: $task->assigned_to);
        }

        return response()->json($task, 201);
    }

    /**
     * Update task
     */
    public function update(Request $request, Task $task)
    {
        if (!CurrentWorker::hasPermission('tasks_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        if ($task->company_id !== $company->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:inventory_count,receive_purchase,approve_transfer,send_transfer,receive_transfer,sales_report,stock_check,adjustment_review,custom',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'due_date' => 'nullable|date',
            'metadata' => 'nullable|array',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled,overdue',
        ]);

        $task->update($validated);
        $task->load(['assignedTo', 'assignedBy', 'location']);

        return response()->json($task);
    }

    /**
     * Delete task
     */
    public function destroy(Request $request, Task $task)
    {
        if (!CurrentWorker::hasPermission('tasks_delete')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        if ($task->company_id !== $company->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Tarea eliminada']);
    }

    /**
     * Change task status (start, complete, cancel)
     */
    public function changeStatus(Request $request, Task $task)
    {
        if (!CurrentWorker::hasPermission('tasks_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        if ($task->company_id !== $company->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'action' => 'required|in:start,complete,cancel',
        ]);

        $user = $request->user();
        $success = false;

        switch ($validated['action']) {
            case 'start':
                $success = $task->start($user);
                break;
            case 'complete':
                $success = $task->complete($user);
                // Notificar a quien asignó la tarea
                if ($success && $task->assigned_by && $task->assigned_by !== $user->id) {
                    NotificationEngine::dispatch('task_event', $task->company_id, [
                        'task'       => $task,
                        'sub_type'   => 'completed',
                        'actor_name' => $user->name,
                    ], userId: $task->assigned_by);
                }
                break;
            case 'cancel':
                $success = $task->cancel();
                break;
        }

        if (!$success) {
            return response()->json([
                'message' => 'No se pudo cambiar el estado de la tarea'
            ], 400);
        }

        $task->load(['assignedTo', 'assignedBy', 'completedBy', 'location']);

        return response()->json([
            'message' => 'Estado actualizado',
            'task' => $task
        ]);
    }

    /**
     * Add comment to task
     */
    public function addComment(Request $request, Task $task)
    {
        if (!CurrentWorker::hasPermission('tasks_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        if ($task->company_id !== $company->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'comment' => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'comment' => $validated['comment'],
            'attachments' => $validated['attachments'] ?? null,
        ]);

        $comment->load('user:id,name,avatar');

        // Notificar al usuario asignado (si no es quien comentó)
        if ($task->assigned_to && $task->assigned_to !== $request->user()->id) {
            NotificationEngine::dispatch('task_event', $task->company_id, [
                'task'       => $task,
                'sub_type'   => 'comment',
                'actor_name' => $request->user()->name,
            ], userId: $task->assigned_to);
        }

        // Notificar a quien creó la tarea (si no es quien comentó y no es el asignado)
        if (
            $task->assigned_by &&
            $task->assigned_by !== $request->user()->id &&
            $task->assigned_by !== $task->assigned_to
        ) {
            NotificationEngine::dispatch('task_event', $task->company_id, [
                'task'       => $task,
                'sub_type'   => 'comment',
                'actor_name' => $request->user()->name,
            ], userId: $task->assigned_by);
        }

        return response()->json($comment, 201);
    }

    /**
     * Get task statistics
     */
    public function statistics(Request $request)
    {
        if (!CurrentWorker::hasPermission('tasks_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();
        $query = Task::where('company_id', $company->id);

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        $stats = [
            'total' => $query->count(),
            'pending' => (clone $query)->pending()->count(),
            'in_progress' => (clone $query)->inProgress()->count(),
            'completed' => (clone $query)->completed()->count(),
            'overdue' => (clone $query)->overdue()->count(),
            'due_today' => (clone $query)->dueToday()->count(),
            'by_priority' => [
                'urgent' => (clone $query)->where('priority', 'urgent')->count(),
                'high' => (clone $query)->where('priority', 'high')->count(),
                'medium' => (clone $query)->where('priority', 'medium')->count(),
                'low' => (clone $query)->where('priority', 'low')->count(),
            ],
            'by_type' => Task::select('type', DB::raw('count(*) as count'))
                ->where('company_id', $company->id)
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
        ];

        return response()->json($stats);
    }
}
