<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Services\TemplateResolver;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    /**
     * List all preferences for the current company.
     * Falls back to defaults when no preference row exists for an event type.
     */
    public function index()
    {
        $company = CurrentCompany::get();
        $defaults = NotificationPreference::getDefaults();

        $saved = NotificationPreference::where('company_id', $company->id)
            ->get()
            ->keyBy('event_type');

        $result = [];
        foreach ($defaults as $eventType => $defaultValues) {
            if ($saved->has($eventType)) {
                $pref = $saved->get($eventType);
                $result[] = [
                    'event_type'       => $pref->event_type,
                    'permission_name'  => $pref->permission_name,
                    'channel_db'       => $pref->channel_db,
                    'channel_email'    => $pref->channel_email,
                    'channel_push'     => $pref->channel_push,
                    'is_active'        => $pref->is_active,
                    'allowed_user_ids' => $pref->allowed_user_ids,
                    'is_customized'    => true,
                ];
            } else {
                $result[] = array_merge(
                    ['event_type' => $eventType, 'is_customized' => false, 'allowed_user_ids' => null],
                    $defaultValues
                );
            }
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Update (or create) a preference for a single event type.
     */
    public function update(Request $request, string $eventType)
    {
        $validEventTypes = array_keys(NotificationPreference::getDefaults());

        if (!in_array($eventType, $validEventTypes, true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de evento inválido'], 422);
        }

        $data = $request->validate([
            'channel_db'         => 'sometimes|boolean',
            'channel_email'      => 'sometimes|boolean',
            'channel_push'       => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
            'allowed_user_ids'   => 'sometimes|nullable|array',
            'allowed_user_ids.*' => 'integer|exists:users,id',
        ]);

        $company = CurrentCompany::get();
        $defaults = NotificationPreference::getDefaults()[$eventType];

        $pref = NotificationPreference::updateOrCreate(
            ['company_id' => $company->id, 'event_type' => $eventType],
            array_merge(
                ['permission_name' => $defaults['permission_name']],
                $data
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Preferencia actualizada',
            'data'    => $pref,
        ]);
    }

    /**
     * Return the users eligible to receive a specific event type notification.
     * These are the active users in the company/location who have the required permission.
     */
    public function eligibleUsers(Request $request, string $eventType, TemplateResolver $resolver)
    {
        $validEventTypes = array_keys(NotificationPreference::getDefaults());

        if (!in_array($eventType, $validEventTypes, true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de evento inválido'], 422);
        }

        $validated = $request->validate([
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        $company = CurrentCompany::get();

        // Resolve the permission required for this event type
        $template = $resolver->resolve($eventType, []);
        $permission = $template->getDefaultPermission();
        $locationId = $validated['location_id'] ?? null;

        $query = User::where('is_active', true)
            ->whereHas('companies', fn($q) => $q->where('companies.id', $company->id));

        if ($locationId) {
            $query->whereHas('locationRoles', fn($q) => $q->where('locations.id', $locationId));
        }

        if (!empty($permission)) {
            $query->where(function ($q) use ($permission, $locationId) {
                $q->whereHas('roles.permissions', fn($roleQuery) => $roleQuery->where('name', $permission))
                    ->orWhereExists(function ($subquery) use ($permission, $locationId) {
                        $subquery->selectRaw('1')
                            ->from('user_location_roles')
                            ->join('rol_permission', 'user_location_roles.role_id', '=', 'rol_permission.role_id')
                            ->join('permissions', 'rol_permission.permission_id', '=', 'permissions.id')
                            ->whereColumn('user_location_roles.user_id', 'users.id')
                            ->where('permissions.name', $permission);

                        if ($locationId) {
                            $subquery->where('user_location_roles.location_id', $locationId);
                        }
                    });
            });
        }

        $users = $query->get()
            ->map(fn($user) => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Reset all preferences for the current company to system defaults.
     */
    public function reset()
    {
        $company = CurrentCompany::get();

        NotificationPreference::where('company_id', $company->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Preferencias restablecidas a los valores predeterminados',
        ]);
    }
}
