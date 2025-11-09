<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Admin\Customer;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CustomerController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = CustomerResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Customer::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return ['company'];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return ['company'];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtro por company_id
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por estado activo/inactivo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Búsqueda en múltiples campos
        if (isset($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:150',
            'business_name' => 'nullable|string|max:255',
            'social_reason' => 'nullable|string|max:20',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'company_id' => 'sometimes|required|exists:companies,id',
            'name' => 'sometimes|required|string|max:150',
            'business_name' => 'nullable|string|max:255',
            'social_reason' => 'nullable|string|max:20',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'is_active' => 'boolean',
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
        // Procesar datos antes de actualizar si es necesario
        // Ejemplo: no permitir cambiar company_id
        // unset($validatedData['company_id']);

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
