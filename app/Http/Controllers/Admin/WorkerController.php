<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\WorkerResource;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
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
            'role',
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
            'role',
            'locations'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        $company = CurrentCompany::get();

        Log::info('Current company in WorkerController: ', [
            'company' => $company ? $company->id : 'none',
            'params' => $params
        ]);

        if ($company) {
            $query->where('company_id', $company->id);
        }

        if (isset($params['location_id'])) {
            $query->whereHas('locations', function ($q) use ($params) {
                $q->where('locations.id', $params['location_id']);
            });
        }

        if (isset($params['department'])) {
            $query->where('department', 'like', '%' . $params['department'] . '%');
        }

        if (isset($params['is_active'])) {
            // Convert string boolean to actual boolean
            $isActive = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
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
            'role_id' => 'nullable|exists:roles,id',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
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
            'company_id' => 'sometimes|exists:companies,id',
            'user_id' => 'sometimes|exists:users,id',
            'role_id' => 'nullable|exists:roles,id',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        return $validatedData;
    }

    /**
     * Acciones después de crear
     */
    protected function afterStore(Model $worker, Request $request): void
    {
        // Sincronizar locations
        if ($request->has('location_ids') && is_array($request->location_ids)) {
            $worker->locations()->sync($request->location_ids);
        }

        // Recargar las relaciones
        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $worker->load($relations);
        }
    }

    /**
     * Acciones después de actualizar
     */
    protected function afterUpdate(Model $worker, Request $request): void
    {
        // Sincronizar locations
        if ($request->has('location_ids')) {
            $worker->locations()->sync($request->location_ids);
        }

        // Recargar las relaciones
        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $worker->load($relations);
        }
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
