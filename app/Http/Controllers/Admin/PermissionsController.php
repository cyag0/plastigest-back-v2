<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Resources;
use App\Http\Controllers\CrudController;
use App\Http\Resources\PermissionsResource;
use App\Models\Admin\Permission;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class PermissionsController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = PermissionsResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Permission::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el índice
            // Ejemplo: 'category', 'unit'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el show
            // Ejemplo: 'category', 'unit', 'suppliers'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Implementar filtros específicos del modelo
        // Ejemplo:
        // if (isset($params['category_id'])) {
        //     $query->where('category_id', $params['category_id']);
        // }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            // Agregar aquí las reglas de validación para crear
            // Ejemplo:
            // 'name' => 'required|string|max:255',
            // 'email' => 'required|email|unique:permissionss,email',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            // Agregar aquí las reglas de validación para actualizar
            // Ejemplo:
            // 'name' => 'required|string|max:255',
            // 'email' => 'required|email|unique:permissionss,email,' . $model->id,
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

    public function getPermissionsByResource()
    {
        $resources = Resources::all();
        $permissions = Permission::all()->groupBy('resource');

        return response()->json([
            'permissions_by_resource' => $permissions,
            'resources' => $resources,
        ]);
    }
}
