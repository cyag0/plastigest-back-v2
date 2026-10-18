<?php

namespace App\Notifications\Services;

use App\Models\Admin\Role;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

class RecipientResolver
{
    /**
     * Resolve which users should receive a notification.
     *
     * When $specificUserId is given (e.g. task events) only that user is targeted.
     * Otherwise, the NotificationPreference of the event's location (or, if it
     * has none, the company's) determines which permission gate to use, falling
     * back to $defaultPermission when no preference exists.
     *
     * @return Collection<User>
     */
    public function resolve(
        int $companyId,
        string $eventType,
        string $defaultPermission,
        ?int $specificUserId = null,
        ?int $locationId = null,
    ): Collection {
        // La de la sucursal del evento si la tiene; si no, la de la empresa.
        $pref = NotificationPreference::effective($companyId, $eventType, $locationId);

        // Respect company-level deactivation (también para los avisos
        // dirigidos a una persona, como las tareas: antes se enviaban aunque
        // la empresa los hubiera apagado).
        if ($pref && !$pref->is_active) {
            return collect();
        }

        if ($specificUserId !== null) {
            $user = User::where('is_active', true)->find($specificUserId);
            return $user ? collect([$user]) : collect();
        }

        $permissionName = $pref?->permission_name ?: $defaultPermission;

        if (empty($permissionName)) {
            return collect();
        }

        // Solo cuentan los roles que dan sus permisos: a quien tiene un rol
        // desactivado ya no le llegan sus avisos (Role::grantsAccess()).
        $users = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $companyId))
            ->when($locationId, fn($q) => $q->whereHas('locationRoles', fn($locationQuery) => $locationQuery->where('locations.id', $locationId)))
            ->where(function ($q) use ($permissionName, $locationId) {
                $q->whereHas('roles', fn($roleQuery) => $roleQuery->grantingAccess()
                    ->whereHas('permissions', fn($permissionQuery) => $permissionQuery->where('name', $permissionName)))
                    ->orWhereExists(function ($subquery) use ($permissionName, $locationId) {
                        $subquery->selectRaw('1')
                            ->from('user_location_roles')
                            ->join('roles', 'roles.id', '=', 'user_location_roles.role_id')
                            ->join('rol_permission', 'user_location_roles.role_id', '=', 'rol_permission.role_id')
                            ->join('permissions', 'rol_permission.permission_id', '=', 'permissions.id')
                            ->whereColumn('user_location_roles.user_id', 'users.id')
                            ->where(Role::grantsAccessCondition())
                            ->where('permissions.name', $permissionName);

                        if ($locationId) {
                            $subquery->where('user_location_roles.location_id', $locationId);
                        }
                    });
            })
            ->get();

        // If the company configured a specific user list, restrict to those users only.
        if ($pref && is_array($pref->allowed_user_ids)) {
            $allowed = $pref->allowed_user_ids;
            $users = $users->filter(fn($user) => in_array($user->id, $allowed));
        }

        return $users;
    }
}
