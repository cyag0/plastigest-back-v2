<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReminderResource;
use App\Models\Reminder;
use App\Services\NotificationService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReminderController extends CrudController
{
    protected string $resource = ReminderResource::class;
    protected string $model = Reminder::class;

    protected function indexRelations(): array
    {
        return ['company', 'location', 'user', 'supplier', 'product'];
    }

    protected function getShowRelations(): array
    {
        return ['company', 'location', 'user', 'supplier', 'product'];
    }

    protected function applyBasicFilters($query, array $params)
    {
        if (empty($params['search'])) {
            return;
        }

        $search = $params['search'];
        $query->where(function ($query) use ($search) {
            $query->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    protected function handleQuery($query, array $params)
    {
        // Apply company filter
        $company = CurrentCompany::get();
        if ($company) {
            $query->where('company_id', $company->id);
        }

        // Filter by location_id
        $location = CurrentLocation::get();
        if ($location) {
            $query->where(function ($q) use ($location) {
                $q->where('location_id', $location->id)
                    ->orWhereNull('location_id');
            });
        }

        // Filter by assigned user (default: current user)
        if (isset($params['assigned_to'])) {
            if ($params['assigned_to'] === 'me') {
                $query->where('user_id', auth()->id());
            } elseif ($params['assigned_to'] === 'all') {
                // Show all reminders (no filter)
            } else {
                $query->where('user_id', $params['assigned_to']);
            }
        } else {
            // Por defecto, solo mostrar recordatorios asignados al usuario actual
            $query->where('user_id', auth()->id());
        }

        // Filter by status
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        // Filter by type
        if (isset($params['type'])) {
            $query->where('type', $params['type']);
        }

        // Filter by date range
        if (isset($params['date_from'])) {
            $query->whereDate('reminder_date', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('reminder_date', '<=', $params['date_to']);
        }

        // Filter overdue
        if (isset($params['overdue']) && $params['overdue']) {
            $query->overdue();
        }

        // Filter upcoming
        if (isset($params['upcoming'])) {
            $query->upcoming((int)$params['upcoming']);
        }

        // Default order
        $query->orderBy('reminder_date', 'asc')
            ->orderBy('created_at', 'desc');
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:payment,renewal,expiration,other',
            'reminder_date' => 'required|date',
            'reminder_time' => 'nullable|date_format:H:i',
            'is_recurring' => 'sometimes|boolean',
            'recurrence_type' => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_end_date' => 'nullable|date|after:reminder_date',
            'notify_enabled' => 'sometimes|boolean',
            'notify_days_before' => 'sometimes|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'product_id' => 'nullable|exists:products,id',
            'amount' => 'nullable|numeric|min:0',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:payment,renewal,expiration,other',
            'reminder_date' => 'sometimes|date',
            'reminder_time' => 'nullable|date_format:H:i',
            'status' => 'sometimes|in:pending,completed,overdue',
            'is_recurring' => 'boolean',
            'recurrence_type' => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_end_date' => 'nullable|date',
            'notify_enabled' => 'boolean',
            'notify_days_before' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'product_id' => 'nullable|exists:products,id',
            'amount' => 'nullable|numeric|min:0',
        ]);
    }

    protected function process($callback, array $data, $method): Model
    {
        // Add company_id from current context
        $company = CurrentCompany::get();
        if ($company && !isset($data['company_id'])) {
            $data['company_id'] = $company->id;
        }

        // Add location_id from current context
        $location = CurrentLocation::get();
        if ($location && !isset($data['location_id'])) {
            $data['location_id'] = $location->id;
        }

        // Add user_id: use provided or default to current user
        if (!isset($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        // Set default values for booleans if not provided
        if (!isset($data['is_recurring'])) {
            $data['is_recurring'] = false;
        }
        
        if (!isset($data['notify_enabled'])) {
            $data['notify_enabled'] = true;
        }
        
        if (!isset($data['notify_days_before'])) {
            $data['notify_days_before'] = 1;
        }

        return $callback($data);
    }

    /**
     * Hook después de crear un recordatorio - enviar notificación
     */
    protected function afterStore(Model $model, Request $request): void
    {
        $reminder = $model;
        
        Log::info('=== REMINDER CREATED ===', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'assigned_to_user_id' => $reminder->user_id,
            'created_by_user_id' => Auth::id(),
        ]);
        
        // Solo notificar si el recordatorio se asignó a otro usuario
        if ($reminder->user_id !== Auth::id()) {
            Log::info('Enviando notificación push a usuario diferente', [
                'to_user_id' => $reminder->user_id,
                'from_user_id' => Auth::id(),
            ]);
            
            $title = "Nuevo Recordatorio Asignado";
            $body = $reminder->title;
            $data = [
                'type' => 'reminder_assigned',
                'reminder_id' => $reminder->id,
                'reminder_type' => $reminder->type,
                'reminder_date' => $reminder->reminder_date->toDateString(),
                'created_by' => Auth::user()->name,
            ];

            NotificationService::sendPushNotification(
                $reminder->user_id,
                $title,
                $body,
                $data
            );
        } else {
            Log::info('No se envía notificación: el usuario creador es el mismo que el asignado');
        }
    }

    /**
     * Marcar recordatorio como completado
     */
    public function markAsCompleted($id)
    {
        try {
            $reminder = Reminder::findOrFail($id);
            $reminder->markAsCompleted();

            return response()->json([
                'success' => true,
                'message' => 'Recordatorio marcado como completado',
                'data' => new ReminderResource($reminder->fresh($this->getShowRelations())),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al completar recordatorio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tipos de recordatorios
     */
    public function getTypes()
    {
        return response()->json([
            'success' => true,
            'data' => Reminder::getTypes(),
        ]);
    }

    /**
     * Obtener tipos de recurrencia
     */
    public function getRecurrenceTypes()
    {
        return response()->json([
            'success' => true,
            'data' => Reminder::getRecurrenceTypes(),
        ]);
    }

    /**
     * Obtener estadísticas de recordatorios
     */
    public function statistics(Request $request)
    {
        try {
            $company = CurrentCompany::get();
            $location = CurrentLocation::get();

            $query = Reminder::where('company_id', $company->id);

            if ($location) {
                $query->where(function ($q) use ($location) {
                    $q->where('location_id', $location->id)
                        ->orWhereNull('location_id');
                });
            }

            $pending = (clone $query)->pending()->count();
            $overdue = (clone $query)->overdue()->count();
            $upcoming = (clone $query)->upcoming(7)->count();
            $completed = (clone $query)->where('status', 'completed')->count();

            $byType = (clone $query)->pending()
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->type => $item->count];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'pending' => $pending,
                    'overdue' => $overdue,
                    'upcoming' => $upcoming,
                    'completed' => $completed,
                    'by_type' => $byType,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios de la empresa para asignar recordatorios
     */
    public function getUsers()
    {
        try {
            $company = CurrentCompany::get();
            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró la empresa actual'
                ], 400);
            }

            $users = $company->users()
                ->select('users.id', 'users.name', 'users.email')
                ->orderBy('users.name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios: ' . $e->getMessage()
            ], 500);
        }
    }
}
