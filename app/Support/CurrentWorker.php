<?php

namespace App\Support;

use App\Models\Admin\Worker;
use Illuminate\Support\Facades\Auth;

class CurrentWorker
{
    /**
     * Obtener el worker activo del usuario actual para la empresa actual
     *
     * @return Worker|null
     */
    public static function get(): ?Worker
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        $company = CurrentCompany::get();

        if (!$company) {
            return null;
        }

        return $user->getWorkerForCompany($company->id);
    }

    /**
     * Obtener el worker activo del usuario actual para una empresa específica
     *
     * @param int $companyId
     * @return Worker|null
     */
    public static function getForCompany(int $companyId): ?Worker
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        return $user->getWorkerForCompany($companyId);
    }

    /**
     * Obtener el ID del worker activo
     *
     * @return int|null
     */
    public static function id(): ?int
    {
        $worker = self::get();
        return $worker?->id;
    }

    /**
     * Obtener el rol del worker activo
     *
     * @return \App\Models\Admin\Role|null
     */
    public static function role()
    {
        $worker = self::get();
        return $worker?->role;
    }

    /**
     * Verificar si el worker actual tiene un rol específico
     *
     * @param string $roleName
     * @return bool
     */
    public static function hasRole(string $roleName): bool
    {
        $role = self::role();
        return $role && $role->name === $roleName;
    }

    /**
     * Verificar si el worker actual tiene alguno de los roles especificados
     *
     * @param array $roleNames
     * @return bool
     */
    public static function hasAnyRole(array $roleNames): bool
    {
        $role = self::role();
        return $role && in_array($role->name, $roleNames);
    }

    /**
     * Verificar si el worker actual tiene un permiso específico
     *
     * @param string $permissionName
     * @return bool
     */
    public static function hasPermission(string $permissionName): bool
    {
        $role = self::role();

        if (!$role) {
            return false;
        }

        return $role->permissions()->where('name', $permissionName)->exists();
    }

    /**
     * Verificar si el worker actual tiene todos los permisos especificados
     *
     * @param array $permissionNames
     * @return bool
     */
    public static function hasAllPermissions(array $permissionNames): bool
    {
        $role = self::role();

        if (!$role) {
            return false;
        }

        $permissions = $role->permissions()->whereIn('name', $permissionNames)->pluck('name')->toArray();

        return count($permissions) === count($permissionNames);
    }

    /**
     * Verificar si el worker actual tiene alguno de los permisos especificados
     *
     * @param array $permissionNames
     * @return bool
     */
    public static function hasAnyPermission(array $permissionNames): bool
    {
        $role = self::role();

        if (!$role) {
            return false;
        }

        return $role->permissions()->whereIn('name', $permissionNames)->exists();
    }
}
