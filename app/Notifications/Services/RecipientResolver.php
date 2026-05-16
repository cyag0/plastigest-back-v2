<?php

namespace App\Notifications\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Admin\Worker;
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
        ?int $specificUserId = null
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

        $users = Worker::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('role.permissions', fn($q) => $q->where('name', $permissionName))
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        // If the company configured a specific user list, restrict to those users only.
        if ($pref && is_array($pref->allowed_user_ids) && count($pref->allowed_user_ids) > 0) {
            $allowed = $pref->allowed_user_ids;
            $users = $users->filter(fn($user) => in_array($user->id, $allowed));
        }

        return $users;
    }
}
