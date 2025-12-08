<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = NotificationResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Notification::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return ['user', 'company'];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        $user = Auth::user();

        // Filtrar por usuario actual
        $query->where('user_id', $user->id);

        // Filtrar por empresa actual
        $company = CurrentCompany::get();
        if ($company) {
            $query->where('company_id', $company->id);
        }

        // Filtrar por tipo
        if (isset($params['type'])) {
            $query->ofType($params['type']);
        }

        // Filtrar por estado de lectura
        if (isset($params['read'])) {
            if ($params['read'] === 'true' || $params['read'] === '1') {
                $query->read();
            } elseif ($params['read'] === 'false' || $params['read'] === '0') {
                $query->unread();
            }
        }

        // Ordenar por más recientes
        $query->latest();
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,success,warning,error,alert',
            'data' => 'nullable|array',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'message' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:info,success,warning,error,alert',
            'data' => 'nullable|array',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        $user = Auth::user();
        $company = CurrentCompany::get();

        // Solo agregar user_id y company_id en creación
        if ($method === 'create') {
            $data['user_id'] = $user->id;
            $data['company_id'] = $company->id;
        }

        /** @var Notification $notification */
        $notification = $callback($data);

        // Recargar relaciones
        $notification->load($this->getShowRelations());

        return $notification;
    }

    /**
     * Marcar una notificación como leída
     */
    public function markAsRead(Request $request, int $id)
    {
        try {
            $user = Auth::user();

            $notification = Notification::where('user_id', $user->id)
                ->findOrFail($id);

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notificación marcada como leída',
                'data' => new $this->resource($notification),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Marcar una notificación como no leída
     */
    public function markAsUnread(Request $request, int $id)
    {
        try {
            $user = Auth::user();

            $notification = Notification::where('user_id', $user->id)
                ->findOrFail($id);

            $notification->markAsUnread();

            return response()->json([
                'success' => true,
                'message' => 'Notificación marcada como no leída',
                'data' => new $this->resource($notification),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Marcar todas las notificaciones como leídas
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = Auth::user();
            $company = CurrentCompany::get();

            $count = Notification::where('user_id', $user->id)
                ->where('company_id', $company->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "Se marcaron {$count} notificaciones como leídas",
                'data' => ['count' => $count],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Obtener el conteo de notificaciones no leídas
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            $company = CurrentCompany::get();

            $count = Notification::where('user_id', $user->id)
                ->where('company_id', $company->id)
                ->unread()
                ->count();

            return response()->json([
                'success' => true,
                'data' => ['count' => $count],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        $user = Auth::user();

        // Solo el usuario dueño puede eliminar la notificación
        if ($model->user_id !== $user->id) {
            return [
                'can_delete' => false,
                'message' => 'No tienes permiso para eliminar esta notificación'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
