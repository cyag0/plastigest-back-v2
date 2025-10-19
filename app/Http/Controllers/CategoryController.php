<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CategoryController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = CategoryResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Category::class;

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
        // Filtrar por compañía
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtrar por estado activo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Búsqueda por nombre
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['search'] . '%')
                    ->orWhere('description', 'like', '%' . $params['search'] . '%');
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
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
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Procesar datos antes de crear si es necesario
        // Ejemplo: agregar company_id del usuario autenticado
        // $validatedData['company_id'] = auth()->user()->company_id;

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
