<?php

namespace App\Notifications\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

class RecipientResolver
{
    /**
     * Resolve which users should receive a notification.
     *
     * When $specificUserId is given (e.g. task events) only that user is targeted.
     * Otherwise, the company's NotificationPreference determines which permission
     * gate to use, falling back to $defaultPermission when no preference exists.
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
        if ($specificUserId !== null) {
            $user = User::find($specificUserId);
            return $user ? collect([$user]) : collect();
        }

        $pref = NotificationPreference::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->first();

        // Respect company-level deactivation
        if ($pref && !$pref->is_active) {
            return collect();
        }

        $permissionName = $pref?->permission_name ?: $defaultPermission;

        if (empty($permissionName)) {
            return collect();
        }

        $users = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $companyId))
            ->when($locationId, fn($q) => $q->whereHas('locationRoles', fn($locationQuery) => $locationQuery->where('locations.id', $locationId)))
            ->where(function ($q) use ($permissionName, $locationId) {
                $q->whereHas('roles.permissions', fn($roleQuery) => $roleQuery->where('name', $permissionName))
                    ->orWhereExists(function ($subquery) use ($permissionName, $locationId) {
                        $subquery->selectRaw('1')
                            ->from('user_location_roles')
                            ->join('rol_permission', 'user_location_roles.role_id', '=', 'rol_permission.role_id')
                            ->join('permissions', 'rol_permission.permission_id', '=', 'permissions.id')
                            ->whereColumn('user_location_roles.user_id', 'users.id')
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
