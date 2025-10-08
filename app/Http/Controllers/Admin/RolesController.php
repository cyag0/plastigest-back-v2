<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Resources;
use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\Permissions\RolesResource;
use App\Models\Admin\Permission;
use App\Models\Admin\Role;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RolesController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = RolesResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Role::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'permissions',
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'permissions',
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
            'name' => 'required|string',
            'description' => 'nullable|string',
            'permissions' => 'array',
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
            'name' => 'required|string',
            'description' => 'nullable|string',
            'permissions' => 'array',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Procesar datos antes de crear si es necesario
        // Ejemplo: agregar company_id del usuario autenticado
        // $validatedData['company_id'] = au th()->user()->company_id;

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
        // permisisions => [id =>true, id2 => true]
        if ($request->has('permissions')) {
            $permissions = array_keys(array_filter($request->input('permissions')));
            $model->permissions()->sync($permissions);
        }
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

    /**
     * Override del método process para manejar lógica específica de roles
     */
    protected function process($callback, array $data, $method): Model
    {
        try {
            // Iniciar transacción para operaciones complejas con roles y permisos
            DB::beginTransaction();
            $permissions = $data['permissions'] ?? [];
            $permissions = array_keys(array_filter($permissions));

            /** @var Role */
            $model = $callback($data);

            $model->permissions()->sync($permissions);

            // Confirmar la transacción
            DB::commit();

            return $model;
        } catch (\Exception $e) {
            // Revertir la transacción en caso de error
            DB::rollBack();
            throw $e; // Re-lanzar la excepción para que el controlador la maneje
        }
    }
}
