<?php

namespace App\Http\Controllers\Admin;

use App\Support\Activity\ActivityLogger;
use App\Enums\Resources;
use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\Permissions\RolesResource;
use App\Models\Admin\Permission;
use App\Models\Admin\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
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
    protected ?string $permissionPrefix = 'roles';

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
        $company = CurrentCompany::get();

        if ($company) {
            $query->where('company_id', $company->id);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'permissions' => 'array',
        ]);

        $this->guardGrantedPermissions($request);

        return $validated;
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        // El rol de sistema (Super Admin) siempre tiene todos los permisos:
        // quitarle alguno, o desactivarlo, deja sin acceso al dueño.
        if ($model->is_system) {
            abort(response()->json(['message' => 'El rol del sistema no se puede modificar.'], 422));
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'permissions' => 'array',
        ]);

        $this->guardGrantedPermissions($request);
        $this->guardRoleChanges($request, $model);

        // Para la bitácora: los permisos no son atributos del modelo y el
        // observer no los ve.
        $this->permissionsBefore = $model->permissions()->pluck('permissions.name')->all();

        return $validated;
    }

    /** Permisos del rol antes de editarlo (bitácora). */
    private ?array $permissionsBefore = null;

    /**
     * Salvo el administrador del sistema, nadie puede dar a un rol permisos
     * que él mismo no tiene: bastaba con editar su propio rol para escalar.
     */
    private function guardGrantedPermissions(Request $request): void
    {
        if (!$request->has('permissions') || CurrentWorker::isSystemAdmin()) {
            return;
        }

        $requested = Permission::whereIn('id', array_keys(array_filter((array) $request->input('permissions'))))
            ->pluck('name');
        $extra = $requested->diff(CurrentWorker::permissions());

        if ($extra->isNotEmpty()) {
            abort(response()->json(['message' => 'No puedes asignar permisos que tú no tienes.'], 403));
        }
    }

    /**
     * Quitar también se controla: sync() reemplaza todos los permisos del rol,
     * y con roles_update se podía vaciar el rol del gerente y, ya degradado,
     * cambiarle la contraseña (UserController::guardTarget solo compara
     * permisos). Salvo el administrador del sistema:
     * - nadie quita permisos que él no tiene;
     * - nadie edita un rol que tiene un usuario con permisos que él no tiene
     *   (CurrentWorker::canManageUser, la misma regla que
     *   UserController::guardTarget, en todas sus sucursales).
     */
    private function guardRoleChanges(Request $request, Role $role): void
    {
        if (CurrentWorker::isSystemAdmin()) {
            return;
        }

        $actorPermissions = CurrentWorker::allPermissionNames();

        if ($request->has('permissions')) {
            $kept = array_keys(array_filter((array) $request->input('permissions')));
            $removed = $role->permissions()->whereNotIn('permissions.id', $kept)->pluck('permissions.name');

            if ($removed->diff($actorPermissions)->isNotEmpty()) {
                abort(response()->json(['message' => 'No puedes quitar permisos que tú no tienes.'], 403));
            }
        }

        $holderIds = DB::table('user_location_roles')->where('role_id', $role->id)->pluck('user_id')
            ->merge(DB::table('users_roles')->where('role_id', $role->id)->pluck('user_id'))
            ->unique();

        CurrentWorker::preloadRoles($holderIds->all());
        foreach (User::whereIn('id', $holderIds)->get() as $holder) {
            if (!CurrentWorker::canManageUser($holder)) {
                abort(response()->json([
                    'message' => 'No puedes editar un rol que tiene un usuario con permisos que tú no tienes.',
                ], 403));
            }
        }
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        $company = CurrentCompany::get();

        if (!$company) {
            abort(422, 'No hay una empresa seleccionada para crear el rol.');
        }

        $validatedData['company_id'] = $company->id;

        // Un rol nuevo nace activo salvo que se indique lo contrario.
        if (!array_key_exists('is_active', $validatedData)) {
            $validatedData['is_active'] = true;
        }

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        $company = CurrentCompany::get();

        if (!$company) {
            abort(422, 'No hay una empresa seleccionada para actualizar el rol.');
        }

        // Nunca reasignar la empresa de un rol de sistema/global (company_id
        // null, como "Super Admin"): hacerlo lo secuestraba hacia la empresa
        // actual. Se conserva su empresa original.
        if (!$model->is_system && $model->company_id !== null) {
            $validatedData['company_id'] = $company->id;
        }

        // is_system no se cambia desde la API.
        unset($validatedData['is_system']);

        return $validatedData;
    }

    /**
     * Aislamiento: salvo el administrador del sistema, solo se pueden
     * ver/editar/eliminar roles de la empresa actual. Los roles de sistema
     * (company_id null) quedan fuera para que nadie los edite por su id.
     */
    protected function findModel($id): ?Model
    {
        return $this->accessibleRoleQuery()->find($id);
    }

    protected function findModelForShow($id): ?Model
    {
        $query = $this->accessibleRoleQuery();

        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    private function accessibleRoleQuery()
    {
        $query = Role::query();

        if (!CurrentWorker::isSystemAdmin()) {
            $query->where('company_id', CurrentCompany::id())
                ->where('is_system', false);
        }

        return $query;
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
        if ($request->has('permissions')) {
            $permissions = array_keys(array_filter($request->input('permissions')));
            $model->permissions()->sync($permissions);

            $this->logPermissionChanges($model);
        }
    }

    private function logPermissionChanges(Model $model): void
    {
        if ($this->permissionsBefore === null) {
            return;
        }

        $after = $model->permissions()->pluck('permissions.name')->all();
        $added = array_values(array_diff($after, $this->permissionsBefore));
        $removed = array_values(array_diff($this->permissionsBefore, $after));
        if (!$added && !$removed) {
            return;
        }

        $parts = array_filter([
            $added ? count($added) . ' agregado' . (count($added) > 1 ? 's' : '') : null,
            $removed ? count($removed) . ' quitado' . (count($removed) > 1 ? 's' : '') : null,
        ]);

        ActivityLogger::log(
            'administracion', 'permissions',
            "Cambió los permisos del rol «{$model->name}»: " . implode(', ', $parts),
            $model, $model->name,
            ['agregados' => $added, 'quitados' => $removed],
        );
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        if ($model->is_system) {
            return ['can_delete' => false, 'message' => 'El rol del sistema no se puede eliminar.'];
        }

        // Al borrar el rol, sus usuarios se quedaban sin permisos en la
        // sucursal sin ningún aviso (la FK lo pone en null).
        $users = DB::table('user_location_roles')->where('role_id', $model->id)->distinct()->count('user_id')
            + DB::table('users_roles')->where('role_id', $model->id)->count();
        if ($users > 0) {
            return [
                'can_delete' => false,
                'message' => $users === 1
                    ? 'No se puede eliminar: 1 usuario tiene este rol. Asígnale otro rol primero.'
                    : "No se puede eliminar: {$users} usuarios tienen este rol. Asígnales otro rol primero.",
            ];
        }

        return ['can_delete' => true, 'message' => ''];
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

            /** @var Role */
            $model = $callback($data);

            // Sin "permissions" en la petición los permisos se quedan como
            // están: antes se sincronizaba con una lista vacía y una edición
            // sin ellos (solo el nombre) dejaba el rol sin ningún permiso.
            if (array_key_exists('permissions', $data)) {
                $model->permissions()->sync(array_keys(array_filter((array) $data['permissions'])));
            }

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
