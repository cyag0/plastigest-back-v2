<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Services\TemplateResolver;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preferencias de notificación de la empresa o, con scope=location, de la
 * sucursal actual. La sucursal hereda la configuración de la empresa en cada
 * aviso hasta que la personaliza (y puede volver a heredarla).
 */
class NotificationPreferenceController extends Controller
{
    private function forbidden()
    {
        return response()->json(['success' => false, 'message' => 'No tienes permiso para realizar esta acción.'], 403);
    }

    /**
     * Sucursal cuya configuración se consulta o edita: la actual con
     * scope=location (null = la de la empresa). false si se pidió la sucursal
     * pero el contexto no trae una de la empresa.
     */
    private function scopeLocationId(Request $request): int|false|null
    {
        if ($request->input('scope') !== 'location') {
            return null;
        }

        $location = CurrentLocation::get();
        if (!$location || (int) $location->company_id !== (int) CurrentCompany::id()) {
            return false;
        }

        return (int) $location->id;
    }

    private function noLocation()
    {
        return response()->json(['success' => false, 'message' => 'Elige una sucursal de la empresa.'], 422);
    }

    /**
     * La configuración de la empresa aplica en todas las sucursales que no
     * tienen la suya: solo la cambia quien tiene acceso a todas (o el
     * administrador del sistema). Antes un encargado con notifications_manage
     * podía apagar o redirigir para toda la empresa avisos como las mermas o
     * las diferencias de conteo. Quien solo opera su sucursal edita la de su
     * sucursal (scope=location).
     */
    private function companyScopeDenied(int|false|null $locationId)
    {
        if ($locationId !== null || CurrentWorker::canAccessAllLocations()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Solo quien tiene acceso a todas las sucursales cambia los avisos de la empresa. Puedes personalizar los de tu sucursal.',
            'code' => 'company_scope_forbidden',
        ], 403);
    }

    /** Valores de una fila tal como los ve la app. */
    private static function values(NotificationPreference $pref): array
    {
        return [
            'event_type'       => $pref->event_type,
            'permission_name'  => $pref->permission_name,
            'channel_db'       => $pref->channel_db,
            'channel_email'    => $pref->channel_email,
            'channel_push'     => $pref->channel_push,
            'is_active'        => $pref->is_active,
            'allowed_user_ids' => $pref->allowed_user_ids,
        ];
    }

    /**
     * Configuración de la empresa para cada aviso: su fila si la guardó, si no
     * los valores por defecto.
     *
     * @return array<string, array> por event_type, con `source` company|default
     */
    private function companyValues(int $companyId): array
    {
        $saved = NotificationPreference::where('company_id', $companyId)
            ->whereNull('location_id')
            ->get()
            ->keyBy('event_type');

        $result = [];
        foreach (NotificationPreference::getDefaults() as $eventType => $defaultValues) {
            $result[$eventType] = $saved->has($eventType)
                ? self::values($saved->get($eventType)) + ['source' => 'company']
                : ['event_type' => $eventType, 'allowed_user_ids' => null] + $defaultValues + ['source' => 'default'];
        }

        return $result;
    }

    /**
     * List all preferences for the current company (or, with scope=location,
     * for the current location: its own where it customized the event, the
     * company's otherwise). Falls back to defaults when no preference row
     * exists for an event type.
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('notifications_list')) {
            return $this->forbidden();
        }

        $company = CurrentCompany::get();
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'No hay empresa seleccionada'], 422);
        }

        $locationId = $this->scopeLocationId($request);
        if ($locationId === false) {
            return $this->noLocation();
        }

        $companyValues = $this->companyValues($company->id);

        if ($locationId === null) {
            $result = collect($companyValues)
                ->map(fn($values) => $values + ['is_customized' => $values['source'] === 'company'])
                ->values();

            return response()->json(['success' => true, 'data' => $result]);
        }

        $own = NotificationPreference::where('company_id', $company->id)
            ->where('location_id', $locationId)
            ->get()
            ->keyBy('event_type');

        $result = collect($companyValues)->map(function ($values, $eventType) use ($own) {
            if ($own->has($eventType)) {
                return self::values($own->get($eventType)) + ['source' => 'location', 'is_customized' => true];
            }

            // Hereda la de la empresa.
            return $values + ['is_customized' => false];
        })->values();

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Update (or create) a preference for a single event type. With
     * scope=location the current location gets its own row; it starts as a
     * copy of the company's configuration so changing one channel does not
     * reset the rest.
     */
    public function update(Request $request, string $eventType)
    {
        if (!CurrentWorker::hasPermission('notifications_manage')) {
            return $this->forbidden();
        }

        $validEventTypes = array_keys(NotificationPreference::getDefaults());

        if (!in_array($eventType, $validEventTypes, true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de evento inválido'], 422);
        }

        $locationId = $this->scopeLocationId($request);
        if ($locationId === false) {
            return $this->noLocation();
        }
        if ($denied = $this->companyScopeDenied($locationId)) {
            return $denied;
        }

        $company = CurrentCompany::get();
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'No hay empresa seleccionada'], 422);
        }

        $data = $request->validate([
            'channel_db'         => 'sometimes|boolean',
            'channel_email'      => 'sometimes|boolean',
            'channel_push'       => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
            'allowed_user_ids'   => 'sometimes|nullable|array',
            // Solo usuarios activos de la empresa: antes cualquier id de
            // usuario, también de otra empresa.
            'allowed_user_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('user_company', 'user_id')->where('company_id', $company->id),
            ],
        ], [
            'allowed_user_ids.*.exists' => 'Solo puedes elegir usuarios activos de la empresa.',
        ]);

        // Una fila nueva parte de lo que aplicaba hasta ahora: la de la
        // sucursal, de la configuración de la empresa; la de la empresa, de
        // los valores por defecto (antes tomaba los de la tabla, con el correo
        // encendido, aunque la pantalla lo mostrara apagado).
        $base = $locationId === null
            ? NotificationPreference::getDefaults()[$eventType] + ['allowed_user_ids' => null]
            : collect($this->companyValues($company->id)[$eventType])
                ->only(['permission_name', 'channel_db', 'channel_email', 'channel_push', 'is_active', 'allowed_user_ids'])
                ->all();

        $pref = NotificationPreference::firstOrCreate(
            ['company_id' => $company->id, 'location_id' => $locationId, 'event_type' => $eventType],
            $base,
        );
        $pref->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Preferencia actualizada',
            'data'    => $pref,
        ]);
    }

    /**
     * Quita la configuración propia de un aviso: la sucursal vuelve a usar la
     * de la empresa, y la empresa los valores por defecto.
     */
    public function destroy(Request $request, string $eventType)
    {
        if (!CurrentWorker::hasPermission('notifications_manage')) {
            return $this->forbidden();
        }

        $locationId = $this->scopeLocationId($request);
        if ($locationId === false) {
            return $this->noLocation();
        }
        if ($denied = $this->companyScopeDenied($locationId)) {
            return $denied;
        }

        NotificationPreference::where('company_id', CurrentCompany::id())
            ->where('event_type', $eventType)
            ->when(
                $locationId,
                fn($q) => $q->where('location_id', $locationId),
                fn($q) => $q->whereNull('location_id'),
            )
            ->delete();

        return response()->json([
            'success' => true,
            'message' => $locationId
                ? 'La sucursal usa la configuración de la empresa para este aviso'
                : 'Aviso con los valores predeterminados',
        ]);
    }

    /**
     * Return the users eligible to receive a specific event type notification.
     * These are the active users in the company/location who have the required permission.
     * With scope=location, the location is the current one.
     */
    public function eligibleUsers(Request $request, string $eventType, TemplateResolver $resolver)
    {
        if (!CurrentWorker::hasPermission('notifications_manage')) {
            return $this->forbidden();
        }

        $validEventTypes = array_keys(NotificationPreference::getDefaults());

        if (!in_array($eventType, $validEventTypes, true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de evento inválido'], 422);
        }

        $validated = $request->validate([
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        $scopeLocationId = $this->scopeLocationId($request);
        if ($scopeLocationId === false) {
            return $this->noLocation();
        }

        $company = CurrentCompany::get();

        // Resolve the permission required for this event type
        $template = $resolver->resolve($eventType, []);
        $permission = $template->getDefaultPermission();
        $locationId = $scopeLocationId ?? $validated['location_id'] ?? null;

        // Quien solo opera su sucursal elige destinatarios de su sucursal (los
        // avisos de la empresa ya no los cambia): no ve a los de las demás.
        if (!CurrentWorker::canAccessAllLocations()) {
            $locationId = CurrentLocation::id();
            if (!$locationId) {
                return $this->noLocation();
            }
        }

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
     * Reset all preferences for the current company to system defaults (with
     * scope=location: the current location goes back to the company's
     * configuration). Resetting the company keeps the locations' own
     * configuration.
     */
    public function reset(Request $request)
    {
        if (!CurrentWorker::hasPermission('notifications_manage')) {
            return $this->forbidden();
        }

        $company = CurrentCompany::get();
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'No hay empresa seleccionada'], 422);
        }

        $locationId = $this->scopeLocationId($request);
        if ($locationId === false) {
            return $this->noLocation();
        }
        if ($denied = $this->companyScopeDenied($locationId)) {
            return $denied;
        }

        NotificationPreference::where('company_id', $company->id)
            ->when(
                $locationId,
                fn($q) => $q->where('location_id', $locationId),
                fn($q) => $q->whereNull('location_id'),
            )
            ->delete();

        return response()->json([
            'success' => true,
            'message' => $locationId
                ? 'La sucursal usa la configuración de la empresa'
                : 'Preferencias restablecidas a los valores predeterminados',
        ]);
    }
}
