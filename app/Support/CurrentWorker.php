<?php

namespace App\Support;

use App\Models\Admin\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrentWorker
{
    /**
     * Permiso que da acceso a todas las sucursales de la empresa (además de
     * system_admin). Antes era locations_list ("Listar ubicaciones"), así que
     * cualquier rol con ese permiso era, sin decirlo, administrador de todas
     * las sucursales.
     */
    public const ALL_LOCATIONS_PERMISSION = 'locations_all';

    /**
     * Lo que ya se consultó durante la petición en curso: roles por sucursal,
     * roles directos y permisos de cada rol. Una pantalla revisaba permisos
     * decenas de veces y cada revisión eran 3 consultas.
     *
     * null = no hay petición en curso (consola, colas, pruebas fuera de una
     * petición): no se guarda nada y cada llamada consulta la base. Lo abre y
     * lo cierra SetCurrentCompany alrededor de cada petición de la API, así que
     * nada pasa de una petición a otra (ni de una prueba a otra).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $cache = null;

    /**
     * Empieza la caché de la petición (la llama SetCurrentCompany).
     */
    public static function beginRequest(): void
    {
        self::$cache = [];
    }

    /**
     * Termina la caché de la petición: lo siguiente vuelve a consultar.
     */
    public static function endRequest(): void
    {
        self::$cache = null;
    }

    /**
     * Olvida lo consultado en esta petición. Para después de cambiar roles o
     * permisos dentro de la misma petición.
     */
    public static function flush(): void
    {
        if (self::$cache !== null) {
            self::$cache = [];
        }
    }

    /**
     * @template T
     * @param callable(): T $resolve
     * @return T
     */
    private static function remember(string $key, callable $resolve): mixed
    {
        if (self::$cache === null) {
            return $resolve();
        }

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $resolve();
        }

        return self::$cache[$key];
    }

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

        $roleId = self::locationRoleIds($user)[$locationId] ?? null;
        if (!$roleId) {
            return null;
        }

        // Un rol desactivado cuenta como si no tuviera rol (Role::grantsAccess()).
        return self::remember("role-model:{$roleId}", fn() => Role::query()->grantingAccess()->find($roleId));
    }

    /**
     * Verificar si el usuario actual tiene un permiso en la sucursal actual.
     */
    public static function hasPermission(string $permissionName): bool
    {
        return in_array($permissionName, self::permissions(), true);
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
            $locationRoles = self::locationRoleIds($user);
            $roleId = $locationRoles[$locationId] ?? null;

            if ($roleId) {
                $roleIds[] = $roleId;
            } elseif (self::canAccessAllLocations($user)) {
                $roleIds = array_values(array_filter($locationRoles));
            }
        }

        return array_values(array_unique(array_merge($roleIds, self::directRoleIds($user))));
    }

    /**
     * El usuario puede ver y operar cualquier sucursal de la empresa: es
     * administrador del sistema o tiene locations_all ("Acceso a todas las
     * sucursales"). locations_list ya no cuenta: solo deja listar sucursales.
     * El selector de sucursales (LocationController) usa el mismo criterio.
     *
     * Se busca en todos sus roles —los directos y los de cualquier sucursal—,
     * no solo en el de la sucursal actual, porque esto se usa justamente para
     * decidir si puede estar en ella.
     *
     * Los dos permisos se evalúan SOLO con los roles que aplican a la empresa
     * actual (los de la empresa + los roles de sistema sin empresa, como Super
     * Admin). Así un administrador de la empresa A no hereda "todas las
     * sucursales" cuando manda el contexto de la empresa B.
     */
    public static function canAccessAllLocations(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) {
            return false;
        }

        $companyId = CurrentCompany::id();

        foreach (self::roles(self::allRoleIds($user)) as $role) {
            if ($companyId && $role['company_id'] !== null && $role['company_id'] !== $companyId) {
                continue;
            }

            if (array_intersect(['system_admin', self::ALL_LOCATIONS_PERMISSION], $role['permissions'])) {
                return true;
            }
        }

        return false;
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

        return in_array('system_admin', self::allPermissionNames($user), true);
    }

    /**
     * Todos los permisos del usuario en cualquiera de sus roles: directos y de
     * todas sus sucursales, de cualquier empresa. Para comparar a dos usuarios
     * (nadie modifica a quien tiene permisos que él no tiene), no para
     * autorizar una acción en la sucursal actual (eso es hasPermission()).
     *
     * @return string[]
     */
    public static function allPermissionNames(?Authenticatable $user = null): array
    {
        $user ??= Auth::user();
        if (!$user) {
            return [];
        }

        $names = [];
        foreach (self::roles(self::allRoleIds($user)) as $role) {
            array_push($names, ...$role['permissions']);
        }

        return array_values(array_unique($names));
    }

    /**
     * ¿$actor (por defecto el usuario actual) puede cambiarle a $target lo que
     * define su acceso: contraseña, correo, estado, roles, sucursales?
     * - El administrador del sistema, a cualquiera.
     * - A un administrador del sistema, solo otro.
     * - A los demás, quien tiene todos sus permisos (los de todos sus roles,
     *   en todas sus sucursales: allPermissionNames()).
     *
     * Una sola regla para UserController::guardTarget, la marca can_manage de
     * la lista y el detalle de usuarios, y editar un rol que alguien tiene
     * (RolesController).
     */
    public static function canManageUser(Authenticatable $target, ?Authenticatable $actor = null): bool
    {
        $actor ??= Auth::user();
        if (!$actor) {
            return false;
        }

        if (self::isSystemAdmin($actor)) {
            return true;
        }

        if (self::isSystemAdmin($target)) {
            return false;
        }

        return array_diff(self::allPermissionNames($target), self::allPermissionNames($actor)) === [];
    }

    /**
     * Por qué $actor (por defecto el usuario actual) no puede asignar ni
     * quitar $role, o null si puede:
     * - 'system': es un rol del sistema;
     * - 'company': es de una empresa a la que no pertenece;
     * - 'permissions': tiene permisos que él no tiene en ninguno de sus roles
     *   (allPermissionNames()).
     *
     * No exime al administrador del sistema: eso lo decide quien llama
     * (canAssignRole(), UserController::guardAssignment). Una sola regla para
     * UserController::guardRoles y la marca assignable de la lista de roles.
     *
     * @return 'system'|'company'|'permissions'|null
     */
    public static function roleAssignmentDenial(Role $role, ?Authenticatable $actor = null): ?string
    {
        $actor ??= Auth::user();

        if ($role->is_system) {
            return 'system';
        }

        if ($role->company_id !== null && !in_array((int) $role->company_id, self::companyIds($actor), true)) {
            return 'company';
        }

        $permissions = $role->relationLoaded('permissions')
            ? $role->permissions->pluck('name')
            : $role->permissions()->pluck('permissions.name');

        if ($permissions->diff(self::allPermissionNames($actor))->isNotEmpty()) {
            return 'permissions';
        }

        return null;
    }

    /**
     * ¿$actor (por defecto el usuario actual) puede asignarle o quitarle $role
     * a un usuario? El administrador del sistema, cualquiera; los demás, si
     * roleAssignmentDenial() no lo impide. La lista de roles lo manda como
     * assignable para que la app no ofrezca lo que UserController rechazaría.
     */
    public static function canAssignRole(Role $role, ?Authenticatable $actor = null): bool
    {
        $actor ??= Auth::user();
        if (!$actor) {
            return false;
        }

        return self::isSystemAdmin($actor) || self::roleAssignmentDenial($role, $actor) === null;
    }

    /**
     * Carga en la caché de la petición los roles (directos y por sucursal) de
     * varios usuarios con dos consultas, para las listas que comparan permisos
     * usuario por usuario (can_manage). Sin petición en curso no hace nada.
     *
     * @param int[] $userIds
     */
    public static function preloadRoles(array $userIds): void
    {
        if (self::$cache === null) {
            return;
        }

        $userIds = array_values(array_filter(
            array_unique(array_map('intval', $userIds)),
            fn(int $id) => !array_key_exists("location-roles:{$id}", self::$cache)
                || !array_key_exists("direct-roles:{$id}", self::$cache),
        ));
        if ($userIds === []) {
            return;
        }

        $locationRoles = DB::table('user_location_roles')->whereIn('user_id', $userIds)
            ->get(['user_id', 'location_id', 'role_id'])->groupBy('user_id');
        $directRoles = DB::table('users_roles')->whereIn('user_id', $userIds)
            ->get(['user_id', 'role_id'])->groupBy('user_id');

        foreach ($userIds as $userId) {
            // Mismo formato que locationRoleIds() y directRoleIds().
            self::$cache["location-roles:{$userId}"] ??= collect($locationRoles->get($userId, []))
                ->mapWithKeys(fn($row) => [(int) $row->location_id => $row->role_id === null ? null : (int) $row->role_id])
                ->all();
            self::$cache["direct-roles:{$userId}"] ??= collect($directRoles->get($userId, []))
                ->map(fn($row) => (int) $row->role_id)
                ->unique()
                ->values()
                ->all();
        }
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

        return in_array($companyId, self::companyIds($user), true) || self::isSystemAdmin($user);
    }

    /**
     * Empresas a las que pertenece el usuario (user_company).
     *
     * @return int[]
     */
    public static function companyIds(?Authenticatable $user = null): array
    {
        $user ??= Auth::user();
        if (!$user) {
            return [];
        }

        $userId = (int) $user->getAuthIdentifier();

        return self::remember("company-ids:{$userId}", fn() => DB::table('user_company')
            ->where('user_id', $userId)
            ->pluck('company_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all());
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

        $assigned = array_key_exists($locationId, self::locationRoleIds($user));

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
        $locationCompanyId = self::remember("location-company:{$locationId}", function () use ($locationId) {
            $companyId = DB::table('locations')->where('id', $locationId)->value('company_id');

            return $companyId === null ? null : (int) $companyId;
        });

        return $locationCompanyId === $companyId;
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
     * Verificar si el usuario actual tiene todos los permisos especificados.
     * Con los mismos roles que hasPermission(): antes solo se miraba el rol de
     * la sucursal y se ignoraban los roles directos y los de quien entra a
     * todas las sucursales.
     */
    public static function hasAllPermissions(array $permissionNames): bool
    {
        return array_diff(array_unique($permissionNames), self::permissions()) === [];
    }

    /**
     * Verificar si el usuario actual tiene alguno de los permisos especificados
     * (mismos roles que hasPermission()).
     */
    public static function hasAnyPermission(array $permissionNames): bool
    {
        return array_intersect($permissionNames, self::permissions()) !== [];
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

        $names = [];
        foreach (self::roles($roleIds) as $role) {
            array_push($names, ...$role['permissions']);
        }

        return array_values(array_unique($names));
    }

    // ─── Datos del usuario (cacheados durante la petición) ──────────────────

    /**
     * Sucursales del usuario y su rol en cada una: [location_id => role_id|null].
     *
     * @return array<int, int|null>
     */
    private static function locationRoleIds(Authenticatable $user): array
    {
        $userId = (int) $user->getAuthIdentifier();

        return self::remember("location-roles:{$userId}", fn() => DB::table('user_location_roles')
            ->where('user_id', $userId)
            ->pluck('role_id', 'location_id')
            ->mapWithKeys(fn($roleId, $locationId) => [(int) $locationId => $roleId === null ? null : (int) $roleId])
            ->all());
    }

    /**
     * Roles directos del usuario (users_roles).
     *
     * @return int[]
     */
    private static function directRoleIds(Authenticatable $user): array
    {
        $userId = (int) $user->getAuthIdentifier();

        return self::remember("direct-roles:{$userId}", fn() => DB::table('users_roles')
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * Todos los roles del usuario: directos y de cualquier sucursal.
     *
     * @return int[]
     */
    private static function allRoleIds(Authenticatable $user): array
    {
        return array_values(array_unique(array_merge(
            array_values(array_filter(self::locationRoleIds($user))),
            self::directRoleIds($user)
        )));
    }

    /**
     * Empresa y permisos de cada rol que da acceso, consultando solo los que
     * aún no se conocen en esta petición.
     *
     * Un rol borrado o desactivado no da nada: no aparece en el resultado, así
     * que tampoco cuenta para todas las sucursales ni para administrador del
     * sistema. El rol del sistema siempre cuenta (Role::grantsAccess()).
     *
     * @param int[] $roleIds
     * @return array<int, array{company_id: int|null, permissions: string[]}>
     */
    private static function roles(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            return [];
        }

        $resolved = [];
        $missing = [];
        foreach ($roleIds as $roleId) {
            if (self::$cache !== null && array_key_exists("role:{$roleId}", self::$cache)) {
                $resolved[$roleId] = self::$cache["role:{$roleId}"];
            } else {
                $missing[] = $roleId;
            }
        }

        if ($missing !== []) {
            $companies = DB::table('roles')
                ->whereIn('id', $missing)
                ->where(Role::grantsAccessCondition())
                ->pluck('company_id', 'id');
            $permissions = $companies->isEmpty() ? collect() : DB::table('rol_permission')
                ->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
                ->whereIn('rol_permission.role_id', $companies->keys()->all())
                ->get(['rol_permission.role_id', 'permissions.name'])
                ->groupBy('role_id');

            foreach ($missing as $roleId) {
                // null = borrado o desactivado: se recuerda igual, para no
                // volver a consultarlo en cada revisión de permisos.
                $role = null;
                if ($companies->has($roleId)) {
                    $companyId = $companies[$roleId];
                    $role = [
                        'company_id' => $companyId === null ? null : (int) $companyId,
                        'permissions' => collect($permissions->get($roleId, []))->pluck('name')->unique()->values()->all(),
                    ];
                }

                $resolved[$roleId] = $role;
                if (self::$cache !== null) {
                    self::$cache["role:{$roleId}"] = $role;
                }
            }
        }

        return array_filter($resolved, fn($role) => $role !== null);
    }
}
