<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\WorkerResource;
use App\Models\Admin\Worker;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class WorkerController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = WorkerResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = \App\Models\Admin\Worker::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company',
            'user',
            'roles',
            'companies',
            'locations'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company',
            'user',
            'roles',
            'companies',
            'locations'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        if (isset($params['department'])) {
            $query->where('department', 'like', '%' . $params['department'] . '%');
        }

        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',

            // Relaciones many-to-many
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'user_id' => 'nullable|exists:users,id',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',

            // Relaciones many-to-many
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Solo devolver los datos del worker
        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Solo devolver los datos del worker
        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras
     */
    /*     protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            $model = $callback($data);

            // Aquí puedes agregar lógica adicional específica del modelo
            // Ejemplo: manejar relaciones, archivos, etc.

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
 */
    /**
     * Acciones después de crear un trabajador
     */
    protected function afterStore(Model $worker, Request $request): void
    {
        Log::info('afterStore ejecutándose', [
            'worker_id' => $worker->id,
            'request_data' => $request->all()
        ]);

        try {
            // Sincronizar roles
            if ($request->has('role_ids') && is_array($request->role_ids)) {
                Log::info('Sincronizando roles', ['role_ids' => $request->role_ids]);
                $worker->roles()->sync($request->role_ids);
                Log::info('Roles sincronizados exitosamente');
            }

            // Sincronizar empresas
            if ($request->has('company_ids') && is_array($request->company_ids)) {
                Log::info('Sincronizando empresas', ['company_ids' => $request->company_ids]);
                $worker->companies()->sync($request->company_ids);
                Log::info('Empresas sincronizadas exitosamente');
            }

            // Sincronizar sucursales
            if ($request->has('location_ids') && is_array($request->location_ids)) {
                Log::info('Sincronizando sucursales', ['location_ids' => $request->location_ids]);
                $worker->locations()->sync($request->location_ids);
                Log::info('Sucursales sincronizadas exitosamente');
            }

            // Recargar las relaciones para que aparezcan en la respuesta
            $relations = $this->getShowRelations();
            if (!empty($relations)) {
                $worker->load($relations);
                Log::info('Relaciones recargadas', ['relations' => $relations]);
            }

            Log::info('afterStore completado exitosamente');
        } catch (\Exception $e) {
            // Log del error pero no fallar la creación del trabajador
            Log::error('Error sincronizando relaciones del trabajador: ' . $e->getMessage(), [
                'exception' => $e,
                'worker_id' => $worker->id,
                'request_data' => $request->all()
            ]);
            throw $e; // Re-lanzar para debug
        }
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Sincronizar relaciones many-to-many
        if ($request->has('role_ids')) {
            $model->roles()->sync($request->role_ids);
        }

        if ($request->has('company_ids')) {
            $model->companies()->sync($request->company_ids);
        }

        if ($request->has('location_ids')) {
            $model->locations()->sync($request->location_ids);
        }
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Validaciones para eliminar
        // Ejemplo:
        // if ($model->orders()->exists()) {
        //     return [
        //         'can_delete' => false,
        //         'message' => 'No se puede eliminar porque tiene órdenes asociadas'
        //     ];
        // }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
