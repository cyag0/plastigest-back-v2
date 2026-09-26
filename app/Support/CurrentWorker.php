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

        return $roleIds->isNotEmpty() && Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn($q) => $q->where('name', 'locations_list'))
            ->exists();
    }

    /**
     * El usuario está asignado a la sucursal, o es administrador.
     */
    public static function canAccessLocation(int $locationId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) {
            return false;
        }

        $assigned = DB::table('user_location_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('location_id', $locationId)
            ->exists();

        return $assigned || self::canAccessAllLocations($user);
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
