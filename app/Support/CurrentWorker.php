<?php

namespace App\Support;

use App\Models\Admin\Role;
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
     * Fallback a roles directos del usuario si no hay rol de sucursal.
     */
    public static function hasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // 1. Verificar por rol de sucursal actual
        $locationId = CurrentLocation::id();
        if ($locationId) {
            $entry = DB::table('user_location_roles')
                ->where('user_id', $user->id)
                ->where('location_id', $locationId)
                ->first();

            if ($entry && $entry->role_id) {
                $hasIt = Role::where('id', $entry->role_id)
                    ->whereHas('permissions', fn($q) => $q->where('name', $permissionName))
                    ->exists();

                if ($hasIt) {
                    return true;
                }
            }
        }

        // 2. Fallback: roles directos del usuario (tabla users_roles)
        return $user->roles()
            ->whereHas('permissions', fn($q) => $q->where('name', $permissionName))
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
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        $roleIds = [];

        $locationId = CurrentLocation::id();
        if ($locationId) {
            $entry = DB::table('user_location_roles')
                ->where('user_id', $user->id)
                ->where('location_id', $locationId)
                ->first();

            if ($entry && $entry->role_id) {
                $roleIds[] = (int) $entry->role_id;
            }
        }

        $directRoleIds = $user->roles()->pluck('roles.id')->toArray();
        $roleIds = array_unique(array_merge($roleIds, $directRoleIds));

        if (empty($roleIds)) {
            return [];
        }

        return DB::table('permissions')
            ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roleIds)
            ->pluck('permissions.name')
            ->unique()
            ->values()
            ->toArray();
    }
}
