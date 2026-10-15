<?php

namespace App\Support;

use App\Models\Admin\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrentWorker
{
    /**
     * Obtener el rol del usuario en la sucursal actual (tabla user_location_roles)
     */
    public static function role(): ?Role
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        $locationId = CurrentLocation::id();
        if (!$locationId) {
            return null;
        }

        $entry = DB::table('user_location_roles')
            ->where('user_id', $user->id)
            ->where('location_id', $locationId)
            ->first();

        if (!$entry || !$entry->role_id) {
            return null;
        }

        return Role::find($entry->role_id);
    }

    /**
     * Verificar si el usuario actual tiene un permiso en la sucursal actual.
     */
    public static function hasPermission(string $permissionName): bool
    {
        $roleIds = self::roleIdsForCurrentLocation();
        if (empty($roleIds)) {
            return false;
        }

        return Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn($q) => $q->where('name', $permissionName))
            ->exists();
    }

    /**
     * Roles que aplican al usuario en la sucursal actual:
     * - el rol que tiene asignado en esa sucursal;
     * - si no tiene asignación ahí pero es administrador (puede entrar a
     *   cualquier sucursal), los roles de sus demás sucursales: si no, podía
     *   entrar a la sucursal pero todo le respondía 403;
     * - siempre, sus roles directos (users_roles).
     *
     * @return int[]
     */
    private static function roleIdsForCurrentLocation(): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        $roleIds = [];
        $locationId = CurrentLocation::id();

        if ($locationId) {
            $roleId = DB::table('user_location_roles')
                ->where('user_id', $user->id)
                ->where('location_id', $locationId)
                ->value('role_id');

            if ($roleId) {
                $roleIds[] = (int) $roleId;
            } elseif (self::canAccessAllLocations($user)) {
                $roleIds = DB::table('user_location_roles')
                    ->where('user_id', $user->id)
                    ->whereNotNull('role_id')
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id)
                    ->all();
            }
        }

        $directRoleIds = $user->roles()->pluck('roles.id')->map(fn($id) => (int) $id)->all();

        return array_values(array_unique(array_merge($roleIds, $directRoleIds)));
    }

    /**
     * El usuario puede ver y operar cualquier sucursal de la empresa.
     *
     * Mismo criterio que el selector de sucursales (LocationController): quien
     * tiene locations_list es administrador. Se busca en todos sus roles —los
     * directos y los de cualquier sucursal—, no solo en el de la sucursal
     * actual, porque esto se usa justamente para decidir si puede estar en ella.
     *
     * El permiso se evalúa SOLO con los roles que aplican a la empresa actual
     * (los de la empresa + los roles de sistema sin empresa, como Super Admin).
     * Así un administrador de la empresa A no hereda "todas las sucursales"
     * cuando manda el contexto de la empresa B.
     */
    public static function canAccessAllLocations(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) {
            return false;
        }

        $roleIds = DB::table('user_location_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNotNull('role_id')
            ->pluck('role_id')
            ->merge($user->roles()->pluck('roles.id'))
            ->unique();

        if ($roleIds->isEmpty()) {
            return false;
        }

        $companyId = CurrentCompany::id();

        return Role::whereIn('id', $roleIds)
            ->when($companyId, fn($q) => $q->where(
                fn($w) => $w->where('company_id', $companyId)->orWhereNull('company_id')
            ))
            ->whereHas('permissions', fn($q) => $q->where('name', 'locations_list'))
            ->exists();
    }

    /**
     * ¿El usuario es administrador del sistema? (permiso system_admin en
     * cualquiera de sus roles, directos o de sucursal). Independiente de la
     * empresa/sucursal actual: lo usamos justamente para decidir si puede
     * seleccionar una empresa a la que no pertenece ("Gestión de compañías").
     */
    public static function isSystemAdmin(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) {
            return false;
        }

        $roleIds = DB::table('user_location_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNotNull('role_id')
            ->pluck('role_id')
            ->merge($user->roles()->pluck('roles.id'))
            ->unique();

        return $roleIds->isNotEmpty() && Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn($q) => $q->where('name', 'system_admin'))
            ->exists();
    }

    /**
     * ¿El usuario puede operar en la empresa indicada? Pertenece a ella
     * (user_company) o es administrador del sistema (puede elegir cualquiera).
     */
    public static function canAccessCompany(int $companyId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) {
            return false;
        }

        $belongs = DB::table('user_company')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('company_id', $companyId)
            ->exists();

        return $belongs || self::isSystemAdmin($user);
    }

    /**
     * ¿El usuario puede entrar a la sucursal? Sí cuando tiene acceso a todas
     * las sucursales (canAccessAllLocations) o está asignado a ella
     * (user_location_roles). Un id null nunca es accesible.
     *
     * Con una empresa en el contexto, la sucursal además tiene que ser de esa
     * empresa: sin esto un administrador de la empresa A podía mandar en
     * X-Location-ID una sucursal de la empresa B y quedar operando en ella.
     * Sin empresa en el contexto no hay contra qué comparar y queda la regla
     * de siempre.
     *
     * La usan SetCurrentLocation (para aceptar X-Location-ID) y las acciones
     * sobre registros o location_id de otra sucursal (ver
     * abortUnlessCanAccessLocation()).
     */
    public static function canAccessLocation(?int $locationId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user || !$locationId) {
            return false;
        }

        $companyId = CurrentCompany::id();
        if ($companyId && !self::locationBelongsToCompany($locationId, $companyId)) {
            return false;
        }

        $assigned = DB::table('user_location_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('location_id', $locationId)
            ->exists();

        return $assigned || self::canAccessAllLocations($user);
    }

    /**
     * Corta con 404 si el registro es de una sucursal a la que el usuario no
     * puede entrar (canAccessLocation). Es 404 y no 403 a propósito: a quien
     * no tiene acceso no se le confirma que el registro existe.
     *
     * Para acciones que cargan un registro de sucursal por id (ventas, turnos,
     * gastos, ajustes, producción, compras, transferencias...). Un location_id
     * null también corta: un registro de sucursal sin sucursal no se expone.
     *
     *   CurrentWorker::abortUnlessCanAccessLocation($expense->location_id);
     *
     * Lanza una HttpException: llámalo antes del try/catch de la acción (o
     * deja pasar HttpException), porque un catch (\Throwable) que responde con
     * errorDetail() convertiría el 404 en 422/500. errorResponse() sí la
     * respeta.
     *
     * Ojo: comprueba acceso a la sucursal, no que sea la sucursal actual. Un
     * usuario asignado a dos sucursales pasa en las dos, y sus permisos se
     * evalúan con el rol de la sucursal actual. Si la acción solo debe hacerse
     * desde la sucursal del registro (p. ej. aprobar desde el origen), compara
     * además con CurrentLocation::id() salvo para canAccessAllLocations().
     */
    public static function abortUnlessCanAccessLocation(?int $locationId): void
    {
        if (!self::canAccessLocation($locationId)) {
            abort(404, 'Recurso no encontrado');
        }
    }

    /**
     * Sucursal a la que se limita una consulta de lectura que acepta un
     * location_id opcional del cliente (reportes, estadísticas, kárdex,
     * listados con filtro de sucursal):
     *
     * - Sin acceso a todas las sucursales: siempre la sucursal actual, diga lo
     *   que diga el filtro. Sin sucursal actual responde 403, porque devolver
     *   null significaría "todas".
     * - Con acceso a todas: la que pida; null, '' o 'all' = todas (null). La
     *   sucursal pedida tiene que ser de la empresa actual; si no (o si el
     *   valor no es un id), 404.
     *
     * null = todas las sucursales de la empresa: quien llama sigue filtrando
     * por empresa. Mismo criterio que InventoryMovementController::locationScope
     * y BusinessReportController::locationScope. Corta con HttpException (como
     * abortUnlessCanAccessLocation): llámalo fuera del try/catch.
     *
     *   $locationId = CurrentWorker::locationScope($request->input('location_id'));
     *   $query->when($locationId, fn($q) => $q->where('location_id', $locationId));
     */
    public static function locationScope(mixed $requested): ?int
    {
        if (!self::canAccessAllLocations()) {
            $currentLocationId = CurrentLocation::id();
            if (!$currentLocationId) {
                abort(403, 'Selecciona una sucursal para continuar.');
            }

            return $currentLocationId;
        }

        if ($requested === null || $requested === '' || $requested === 'all') {
            return null;
        }

        $locationId = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $companyId = CurrentCompany::id();

        if ($locationId === false || !$companyId || !self::locationBelongsToCompany($locationId, $companyId)) {
            abort(404, 'Sucursal no encontrada');
        }

        return $locationId;
    }

    private static function locationBelongsToCompany(int $locationId, int $companyId): bool
    {
        return DB::table('locations')
            ->where('id', $locationId)
            ->where('company_id', $companyId)
            ->exists();
    }

    /**
     * Verificar si el usuario actual tiene un rol específico en la sucursal actual
     */
    public static function hasRole(string $roleName): bool
    {
        $role = self::role();
        return $role && $role->name === $roleName;
    }

    /**
     * Verificar si el usuario actual tiene alguno de los roles especificados
     */
    public static function hasAnyRole(array $roleNames): bool
    {
        $role = self::role();
        return $role && in_array($role->name, $roleNames);
    }

    /**
     * Verificar si el usuario actual tiene todos los permisos especificados
     */
    public static function hasAllPermissions(array $permissionNames): bool
    {
        $role = self::role();
        if (!$role) {
            return false;
        }

        $found = $role->permissions()->whereIn('name', $permissionNames)->pluck('name')->toArray();
        return count($found) === count($permissionNames);
    }

    /**
     * Verificar si el usuario actual tiene alguno de los permisos especificados
     */
    public static function hasAnyPermission(array $permissionNames): bool
    {
        $role = self::role();
        if (!$role) {
            return false;
        }

        return $role->permissions()->whereIn('name', $permissionNames)->exists();
    }

    /**
     * Obtener todos los nombres de permiso del usuario para la sucursal actual.
     * Combina permisos del rol de sucursal y roles directos del usuario.
     *
     * @return string[]
     */
    public static function permissions(): array
    {
        $roleIds = self::roleIdsForCurrentLocation();

        if (empty($roleIds)) {
            return [];
        }

        return DB::table('permissions')
            ->join('rol_permission', 'permissions.id', '=', 'rol_permission.permission_id')
            ->whereIn('rol_permission.role_id', $roleIds)
            ->pluck('permissions.name')
            ->unique()
            ->values()
            ->toArray();
    }
}
