<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\LocationResource;
use App\Models\Admin\Location;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LocationController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = LocationResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Location::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        /** @var \Illuminate\Database\Eloquent\Builder $query */

        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por tipo de locación
        if (isset($params['location_type'])) {
            $query->where('location_type', $params['location_type']);
        }

        // Filtro por código postal
        if (isset($params['postal_code'])) {
            $query->where('postal_code', 'like', "%{$params['postal_code']}%");
        }

        // Filtro por ciudad
        if (isset($params['city'])) {
            $query->where('city', 'like', "%{$params['city']}%");
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Asegurar que is_active tenga un valor por defecto
        if (!isset($validatedData['is_active'])) {
            $validatedData['is_active'] = true;
        }

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Validar que no se cambie de compañía si hay restricciones
        // Por ejemplo, si tiene transacciones asociadas
        if (isset($validatedData['company_id']) && $model->company_id !== $validatedData['company_id']) {
            // Aquí podrías agregar validaciones adicionales si es necesario
        }

        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras
     */
    protected function process($callback, array $data, $method = 'create'): Model
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

    /**
     * Acciones después de crear (opcional)
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Lógica adicional después de crear
        // Ejemplo: crear relaciones, enviar notificaciones, etc.
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Lógica adicional después de actualizar
        // Ejemplo: sincronizar relaciones, logs, etc.
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
