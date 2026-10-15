<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Activity\ActivitySubjects;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora: "Mi actividad" (lo que hizo el usuario), la de otro usuario o la
 * de toda la empresa.
 *
 *  - user_id ausente o "me": la propia. Cualquiera puede verla.
 *  - user_id = id / "all": requiere users_read. Quien no ve todas las
 *    sucursales sólo ve lo ocurrido en la suya.
 *  - scope = "location": solo lo ocurrido en la sucursal actual.
 */
class ActivityLogController extends Controller
{
    public const MODULES = [
        'ventas' => 'Ventas',
        'caja' => 'Caja',
        'compras' => 'Compras',
        'inventario' => 'Inventario',
        'transferencias' => 'Transferencias',
        'produccion' => 'Producción',
        'catalogo' => 'Catálogo',
        'administracion' => 'Administración',
        'tareas' => 'Tareas',
        'sesion' => 'Sesión',
    ];

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $target = $request->input('user_id', 'me');
        $companyId = CurrentCompany::id();

        $query = ActivityLog::query()->with(['location:id,name']);

        if ($target === 'me' || (int) $target === (int) $me->id) {
            // Lo propio: también los registros sin empresa (inicio de sesión).
            $query->where('user_id', $me->id)
                ->where(fn($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
        } else {
            if (!CurrentWorker::hasPermission('users_read')) {
                return response()->json(['message' => 'No tienes permiso para ver la actividad de otros usuarios.'], 403);
            }

            if ($target !== 'all') {
                $userId = (int) $target;
                $belongs = DB::table('user_company')->where('user_id', $userId)->where('company_id', $companyId)->exists();
                if (!$belongs && !CurrentWorker::isSystemAdmin()) {
                    return response()->json(['message' => 'Usuario no encontrado.'], 404);
                }
                $query->where('user_id', $userId)
                    ->where(fn($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
            } else {
                $query->where('company_id', $companyId);
            }

            if (!CurrentWorker::canAccessAllLocations()) {
                $query->where('location_id', CurrentLocation::id());
            }
        }

        // Bitácora de la sucursal actual (Administración › Esta sucursal).
        if ($request->input('scope') === 'location') {
            $query->where('location_id', CurrentLocation::id());
        }

        if ($request->filled('module')) {
            $modules = is_array($request->input('module')) ? $request->input('module') : explode(',', (string) $request->input('module'));
            $query->whereIn('module', $modules);
        }
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(fn($q) => $q->where('description', 'like', "%{$search}%")->orWhere('user_name', 'like', "%{$search}%"));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($request->input('date_from'))->startOfDay()->toDateTimeString());
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($request->input('date_to'))->endOfDay()->toDateTimeString());
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        $types = collect(ActivitySubjects::all())->map(fn($c) => $c['type']);

        return response()->json([
            'data' => collect($logs->items())->map(fn(ActivityLog $log) => [
                'id' => $log->id,
                'date' => $log->created_at?->toISOString(),
                'module' => $log->module,
                'module_label' => self::MODULES[$log->module] ?? ucfirst($log->module),
                'event' => $log->event,
                'description' => $log->description,
                'user' => ['id' => $log->user_id, 'name' => $log->user_name],
                'location' => $log->location ? ['id' => $log->location->id, 'name' => $log->location->name] : null,
                'subject' => $log->subject_type ? [
                    'type' => $types[$log->subject_type] ?? 'other',
                    'id' => $log->subject_id,
                    'label' => $log->subject_label,
                ] : null,
                'changes' => $log->changes,
            ])->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
            'filters' => [
                'modules' => collect(self::MODULES)->map(fn($label, $value) => compact('value', 'label'))->values(),
            ],
        ]);
    }
}
