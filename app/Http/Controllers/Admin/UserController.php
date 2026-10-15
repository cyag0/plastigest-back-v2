<?php

namespace App\Http\Controllers\Admin;

use App\Support\Activity\ActivityLogger;
use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UserResource;
use App\Models\Admin\Location;
use App\Models\Admin\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = UserResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = User::class;
    protected ?string $permissionPrefix = 'users';
    protected ?string $companyColumn = null;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        // En "Usuarios" de la empresa o de la sucursal, la lista muestra las
        // sucursales y roles de esa empresa; la de todas las empresas, todas.
        $request = request();
        $onlyCurrentCompany = filter_var($request->input('by_company'), FILTER_VALIDATE_BOOLEAN)
            || $request->input('scope') === 'location';

        return ['companies', 'roles', ...$this->locationRolesRelation($onlyCurrentCompany)];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        // Todas sus sucursales: el formulario de la empresa las reenvía al
        // guardar y no debe perder las de otra empresa.
        return ['companies', 'roles', ...$this->locationRolesRelation(false)];
    }

    /**
     * Sucursales del usuario con el nombre de su rol en cada una (la lista y
     * el detalle muestran "Sucursal · Rol" sin una consulta por renglón).
     */
    private function locationRolesRelation(bool $onlyCurrentCompany): array
    {
        $companyId = $onlyCurrentCompany ? CurrentCompany::id() : null;

        return ['locationRoles' => function ($query) use ($companyId) {
            $query->leftJoin('roles', 'roles.id', '=', 'user_location_roles.role_id')
                ->select('locations.id', 'locations.name', 'locations.company_id', 'roles.name as role_name')
                ->when($companyId, fn($q) => $q->where('locations.company_id', $companyId))
                ->orderBy('locations.name');
        }];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Aislamiento: salvo el administrador del sistema, un usuario solo ve a
        // los usuarios que comparten alguna de sus empresas. Antes el listado
        // devolvía TODOS los usuarios de todas las empresas.
        $this->scopeToAccessibleUsers($query);

        // "Usuarios" de la empresa: solo los de la empresa seleccionada. El
        // administrador del sistema veía aquí a los de todas las empresas.
        if (filter_var($params['by_company'] ?? false, FILTER_VALIDATE_BOOLEAN) && ($companyId = CurrentCompany::id())) {
            $query->whereHas('companies', fn($q) => $q->where('companies.id', $companyId));
        }

        // "Usuarios" de la sucursal: los que tienen acceso a la sucursal actual.
        if (($params['scope'] ?? null) === 'location') {
            $this->scopeToCurrentLocation($query);
        }

        // Filtros adicionales
        if (isset($params['is_active'])) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    /**
     * Restringe la query a usuarios que comparten empresa con el actor.
     * El administrador del sistema ve a todos.
     *
     * Quien solo puede operar su sucursal (no ve todas las sucursales) solo ve
     * y edita a los usuarios de esa sucursal, también desde "Usuarios" de la
     * empresa.
     */
    private function scopeToAccessibleUsers($query): void
    {
        $actor = Auth::user();
        if (!$actor || CurrentWorker::isSystemAdmin($actor)) {
            return;
        }

        $companyIds = $this->actorCompanyIds();
        $query->whereHas('companies', fn($q) => $q->whereIn('companies.id', $companyIds));

        if ($this->actorIsBranchOnly()) {
            $this->scopeToCurrentLocation($query);
        }
    }

    /**
     * Solo los usuarios asignados a la sucursal actual. Sin una sucursal de la
     * empresa en el contexto no hay a quién mostrar.
     */
    private function scopeToCurrentLocation($query): void
    {
        $locationId = $this->currentBranchId();

        if (!$locationId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('locationRoles', fn($q) => $q->where('locations.id', $locationId));
    }

    /**
     * Sucursal del contexto (X-Location-ID), siempre que sea de la empresa
     * seleccionada.
     */
    private function currentBranchId(): ?int
    {
        $location = CurrentLocation::get();

        if (!$location || (int) $location->company_id !== (int) CurrentCompany::id()) {
            return null;
        }

        return (int) $location->id;
    }

    /**
     * El actor solo opera su sucursal: no es administrador del sistema ni
     * puede ver todas las sucursales de la empresa.
     */
    private function actorIsBranchOnly(): bool
    {
        $actor = Auth::user();

        return $actor
            && !CurrentWorker::isSystemAdmin($actor)
            && !CurrentWorker::canAccessAllLocations($actor);
    }

    /**
     * IDs de las empresas a las que pertenece el actor.
     *
     * @return int[]
     */
    private function actorCompanyIds(): array
    {
        return DB::table('user_company')
            ->where('user_id', Auth::id())
            ->pluck('company_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * Permisos que tiene el actor en cualquiera de sus roles (directos o de
     * sucursal). Se usa para que nadie pueda asignar permisos que él no tiene.
     *
     * @return string[]
     */
    private function actorPermissionNames(): array
    {
        $roleIds = DB::table('user_location_roles')
            ->where('user_id', Auth::id())
            ->whereNotNull('role_id')
            ->pluck('role_id')
            ->merge(DB::table('users_roles')->where('user_id', Auth::id())->pluck('role_id'))
            ->unique();

        if ($roleIds->isEmpty()) {
            return [];
        }

        return DB::table('permissions')
            ->join('rol_permission', 'permissions.id', '=', 'rol_permission.permission_id')
            ->whereIn('rol_permission.role_id', $roleIds)
            ->pluck('permissions.name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Impide la escalación de privilegios al crear/editar usuarios: salvo el
     * administrador del sistema, nadie puede asignar roles de sistema, roles de
     * otra empresa, permisos que no tiene, ni empresas/sucursales ajenas.
     */
    private function guardAssignment(Request $request): ?JsonResponse
    {
        $actor = Auth::user();
        if (!$actor || CurrentWorker::isSystemAdmin($actor)) {
            return null; // el administrador del sistema puede asignar todo
        }

        $actorCompanyIds = $this->actorCompanyIds();

        // Quien solo opera su sucursal cambia el rol de esa sucursal
        // (location_role_id), no las sucursales del usuario: location_roles
        // reemplaza todas, incluidas las que él no ve.
        if ($request->has('location_roles') && $this->actorIsBranchOnly()) {
            return $this->forbiddenResponse('cambiar las sucursales de un usuario');
        }

        // Roles pedidos: directos (role_ids) + por sucursal (location_roles) +
        // el de la sucursal actual (location_role_id).
        $roleIds = collect($request->input('role_ids', []))
            ->merge(collect($request->input('location_roles', []))->pluck('role_id'))
            ->push($request->input('location_role_id'))
            ->filter(fn($id) => $id !== null && $id !== '')
            ->map(fn($id) => (int) $id)
            ->unique();

        if ($roleIds->isNotEmpty()) {
            $actorPermissions = $this->actorPermissionNames();
            $roles = Role::with('permissions')->whereIn('id', $roleIds)->get();

            foreach ($roles as $role) {
                if ($role->is_system) {
                    return $this->forbiddenResponse('asignar un rol del sistema');
                }

                if ($role->company_id !== null && !in_array((int) $role->company_id, $actorCompanyIds, true)) {
                    return $this->forbiddenResponse('asignar un rol de otra empresa');
                }

                $extraPermissions = $role->permissions->pluck('name')->diff($actorPermissions);
                if ($extraPermissions->isNotEmpty()) {
                    return $this->forbiddenResponse('asignar permisos que tú no tienes');
                }
            }
        }

        // Empresas: solo las del actor.
        $companyIds = collect($request->input('company_ids', []))
            ->filter(fn($id) => $id !== null && $id !== '')
            ->map(fn($id) => (int) $id);
        if ($companyIds->diff($actorCompanyIds)->isNotEmpty()) {
            return $this->forbiddenResponse('asignar una empresa a la que no perteneces');
        }

        // Sucursales: solo las de las empresas del actor.
        foreach ((array) $request->input('location_roles', []) as $lr) {
            if (empty($lr['location_id'])) {
                continue;
            }
            $location = Location::find($lr['location_id']);
            if (!$location || !in_array((int) $location->company_id, $actorCompanyIds, true)) {
                return $this->forbiddenResponse('asignar una sucursal de otra empresa');
            }
        }

        return null;
    }

    /**
     * Un usuario con companyColumn=null no se aísla por la query base de
     * CrudController, así que el show/update/destroy se acotan aquí: salvo el
     * administrador del sistema, solo se puede actuar sobre usuarios que
     * comparten empresa con el actor.
     */
    protected function findModel($id): ?Model
    {
        return $this->accessibleUserQuery()->find($id);
    }

    protected function findModelForShow($id): ?Model
    {
        $query = $this->accessibleUserQuery();

        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    private function accessibleUserQuery()
    {
        $query = User::query();
        $this->scopeToAccessibleUsers($query);

        return $query;
    }

    public function store(Request $request)
    {
        if (!$this->canStore()) {
            return $this->forbiddenResponse('crear este recurso');
        }

        if ($denied = $this->guardAssignment($request) ?? $this->requireBranchForLocationRole($request)) {
            return $denied;
        }

        return parent::store($request);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canEdit()) {
            return $this->forbiddenResponse('editar este recurso');
        }

        // El objetivo debe ser accesible (comparte empresa con el actor).
        if (!$this->findModel($id)) {
            return response()->json(['message' => 'Recurso no encontrado'], 404);
        }

        if ($denied = $this->guardAssignment($request) ?? $this->requireBranchForLocationRole($request)) {
            return $denied;
        }

        return parent::update($request, $id);
    }

    /**
     * location_role_id es el rol en la sucursal actual: sin una sucursal de la
     * empresa en el contexto no hay dónde asignarlo.
     */
    private function requireBranchForLocationRole(Request $request): ?JsonResponse
    {
        if ($request->has('location_role_id') && !$this->currentBranchId()) {
            return response()->json(['message' => 'Elige una sucursal de la empresa para asignar el rol.'], 422);
        }

        return null;
    }

    /**
     * Sobreescribir applyBasicFilters para evitar el filtro automático de company_id
     * Los usuarios no tienen columna company_id, usan relación many-to-many
     */
    protected function applyBasicFilters($query, array $params)
    {
        // Búsqueda por texto
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtro por estado activo/inactivo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // NO aplicar filtro directo de company_id porque users no tiene esa columna
        // El filtro de company se maneja en handleQuery con whereHas

        // Filtro por fecha de creación
        if (isset($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
        }
    }

    /**
     * El rol de la sucursal actual tiene que ser un rol de la empresa (o
     * ninguno: el usuario entra a la sucursal sin permisos).
     */
    private function locationRoleRule(): array
    {
        return [
            'sometimes',
            'nullable',
            \Illuminate\Validation\Rule::exists('roles', 'id')->where('company_id', CurrentCompany::id()),
        ];
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'is_active' => 'sometimes|boolean',

            // Relaciones many-to-many opcionales
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',

            // Sucursales con rol asignado
            'location_roles' => 'nullable|array',
            'location_roles.*.location_id' => 'required_with:location_roles|exists:locations,id',
            'location_roles.*.role_id' => 'nullable|exists:roles,id',

            // Rol en la sucursal actual (Administración › Esta sucursal)
            'location_role_id' => $this->locationRoleRule(),
        ]);

        // Validar avatar por separado (puede venir como array o como archivo único)
        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar.*' => 'image|max:5120',
            ]);
        }

        return $validated;
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $model->id,
            'password' => 'nullable|string|min:8',
            'is_active' => 'sometimes|boolean',

            // Relaciones many-to-many opcionales
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',

            // Sucursales con rol asignado
            'location_roles' => 'nullable|array',
            'location_roles.*.location_id' => 'required_with:location_roles|exists:locations,id',
            'location_roles.*.role_id' => 'nullable|exists:roles,id',

            // Rol en la sucursal actual (Administración › Esta sucursal)
            'location_role_id' => $this->locationRoleRule(),
        ]);

        // Validar avatar por separado
        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar.*' => 'image|max:5120',
            ]);
        }

        return $validated;
    }

    /**
     * Procesar datos antes de crear
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Hashear la contraseña
        if (isset($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }

        // Establecer is_active por defecto si no viene
        if (!isset($validatedData['is_active'])) {
            $validatedData['is_active'] = true;
        }

        // Manejar subida de avatar
        $file = null;
        if ($request->hasFile('avatar')) {
            $files = $request->file('avatar');
            $file = is_array($files) ? $files[0] : $files;
        }

        if ($file && $file->isValid()) {
            $result = AppUploadUtil::saveFile($file, Files::USER_AVATARS_PATH);

            if ($result['success']) {
                $validatedData['avatar'] = basename($result['path']);
            }
        }

        // Remover avatar del array de validatedData para evitar errores
        unset($validatedData['avatar.*']);

        // La asociación con la empresa actual se hace en afterStore(),
        // ya que company_ids es una relación many-to-many, no una columna.

        return $validatedData;
    }
    /**
     * Procesar datos antes de actualizar
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Hashear la contraseña solo si se proporciona
        if (isset($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        } else {
            unset($validatedData['password']);
        }

        // Manejar subida de avatar usando AppUploadUtil
        if ($request->hasFile('avatar')) {
            $newFiles = $request->file('avatar');
            $oldFiles = $model->avatar ? [$model->avatar] : [];

            // Sincronizar archivos: elimina los antiguos y guarda los nuevos
            $result = AppUploadUtil::syncFilesByNames(
                Files::USER_AVATARS_PATH,
                is_array($newFiles) ? $newFiles : [$newFiles],
                $oldFiles
            );

            if (!empty($result['saved'])) {
                $validatedData['avatar'] = $result['saved'][0]['name'];
            }
        }

        // Remover avatar del array de validatedData para evitar errores
        unset($validatedData['avatar.*']);

        return $validatedData;
    }

    /**
     * Acciones después de crear
     */
    protected function afterStore(Model $user, Request $request): void
    {
        // Sincronizar roles
        if ($request->has('role_ids') && is_array($request->role_ids)) {
            $user->roles()->sync($request->role_ids);
        }

        $locationIds = $this->syncLocationRoles($user, $request);
        if ($locationId = $this->syncCurrentLocationRole($user, $request)) {
            $locationIds[] = $locationId;
        }
        $this->syncCompanies($user, $request, array_values(array_unique($locationIds)));

        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $user->load($relations);
        }
    }

    /**
     * Acciones después de actualizar
     */
    protected function afterUpdate(Model $user, Request $request): void
    {
        // Sincronizar relaciones many-to-many
        if ($request->has('role_ids')) {
            $user->roles()->sync($request->role_ids);
        }

        $locationIds = $this->syncLocationRoles($user, $request);
        if ($locationId = $this->syncCurrentLocationRole($user, $request)) {
            $locationIds[] = $locationId;
        }
        $this->syncCompanies($user, $request, array_values(array_unique($locationIds)));

        // Cerrar las sesiones del usuario afectado cuando:
        //  - se cambió su contraseña (un token viejo no debe seguir vivo), o
        //  - se le desactivó la cuenta (debe perder el acceso de inmediato).
        $deactivated = $request->has('is_active')
            && !filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);

        if ($request->filled('password') || $deactivated) {
            $user->tokens()->delete();
            // Sin sesión, sus dispositivos tampoco deben seguir recibiendo push.
            \App\Models\DeviceToken::where('user_id', $user->id)
                ->update(['is_active' => false]);
        }

        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $user->load($relations);
        }
    }

    /**
     * Sincroniza la tabla user_location_roles y devuelve los IDs de sucursal
     * con los que quedó el usuario.
     *
     * Si la petición no trae location_roles no se toca nada: se devuelven las
     * sucursales que ya tenía para que el cálculo de empresas siga siendo correcto.
     *
     * @return int[]
     */
    private function syncLocationRoles(Model $user, Request $request): array
    {
        if (!$request->has('location_roles')) {
            return $user->locationRoles()->pluck('locations.id')->all();
        }

        $before = $this->describeLocationRoles($user);

        $sync = [];
        foreach ((array) $request->input('location_roles', []) as $lr) {
            if (empty($lr['location_id'])) {
                continue;
            }

            // role_id puede llegar como "" desde multipart/form-data; la FK
            // solo acepta un ID válido o NULL.
            $roleId = $lr['role_id'] ?? null;

            $sync[(int) $lr['location_id']] = [
                'role_id' => ($roleId === null || $roleId === '') ? null : (int) $roleId,
            ];
        }

        $user->locationRoles()->sync($sync);

        $after = $this->describeLocationRoles($user);
        // Al crear, el alta del usuario ya cuenta; aquí sólo los cambios.
        if (!$user->wasRecentlyCreated && $before !== $after) {
            ActivityLogger::log(
                'administracion', 'roles',
                "Cambió las sucursales de «{$user->name}»: " . ($after ? implode(', ', $after) : 'sin sucursales'),
                $user, $user->name,
                ['sucursales' => [$before ? implode(', ', $before) : '—', $after ? implode(', ', $after) : '—']],
            );
        }

        return array_keys($sync);
    }

    /**
     * Rol del usuario en la sucursal actual (Administración › Esta sucursal).
     * Solo toca esa sucursal y conserva las demás asignaciones; así también se
     * agrega a la sucursal a alguien que ya es usuario de la empresa.
     *
     * @return int|null la sucursal, o null si la petición no trae location_role_id
     */
    private function syncCurrentLocationRole(Model $user, Request $request): ?int
    {
        if (!$request->has('location_role_id') || !($locationId = $this->currentBranchId())) {
            return null;
        }

        $roleId = $request->input('location_role_id');
        $roleId = ($roleId === null || $roleId === '') ? null : (int) $roleId;

        $current = DB::table('user_location_roles')
            ->where('user_id', $user->getKey())
            ->where('location_id', $locationId)
            ->first();

        if ($current && ($current->role_id === null ? null : (int) $current->role_id) === $roleId) {
            return $locationId;
        }

        if ($current) {
            $user->locationRoles()->updateExistingPivot($locationId, ['role_id' => $roleId]);
        } else {
            $user->locationRoles()->attach($locationId, ['role_id' => $roleId]);
        }

        // Al crear, el alta del usuario ya cuenta; aquí sólo los cambios.
        if (!$user->wasRecentlyCreated) {
            $roleNames = Role::whereIn('id', array_filter([$roleId, $current?->role_id]))->pluck('name', 'id');
            $after = $roleId ? ($roleNames[$roleId] ?? 'otro rol') : 'sin rol';
            $location = CurrentLocation::name();

            if ($current) {
                $before = $current->role_id ? ($roleNames[$current->role_id] ?? 'otro rol') : 'sin rol';
                ActivityLogger::log(
                    'administracion', 'roles',
                    "Cambió el rol de «{$user->name}» en {$location}: {$before} → {$after}",
                    $user, $user->name,
                    ['rol' => [$before, $after]],
                );
            } else {
                ActivityLogger::log(
                    'administracion', 'roles',
                    "Agregó a «{$user->name}» a {$location} ({$after})",
                    $user, $user->name,
                    ['sucursal' => ['—', "{$location} ({$after})"]],
                );
            }
        }

        return $locationId;
    }

    /**
     * Quita al usuario de la sucursal actual (Administración › Esta sucursal).
     * Sigue siendo usuario de la empresa y conserva sus otras sucursales; sin
     * sucursales ya no puede entrar a ninguna hasta que le asignen una.
     */
    public function removeFromCurrentLocation($id): JsonResponse
    {
        if (!$this->canEdit()) {
            return $this->forbiddenResponse('quitar usuarios de la sucursal');
        }

        $user = $this->findModel($id);
        if (!$user) {
            return response()->json(['message' => 'Recurso no encontrado'], 404);
        }

        $locationId = $this->currentBranchId();
        if (!$locationId) {
            return response()->json(['message' => 'Elige una sucursal de la empresa.'], 422);
        }

        if ((int) $user->getKey() === (int) Auth::id()) {
            return response()->json(['message' => 'No puedes quitarte de la sucursal en la que estás.'], 422);
        }

        $current = DB::table('user_location_roles')
            ->where('user_id', $user->getKey())
            ->where('location_id', $locationId)
            ->first();

        if (!$current) {
            return response()->json(['message' => 'El usuario no está en esta sucursal.'], 404);
        }

        $user->locationRoles()->detach($locationId);

        $role = $current->role_id ? Role::whereKey($current->role_id)->value('name') : null;
        $location = CurrentLocation::name();
        ActivityLogger::log(
            'administracion', 'roles',
            "Quitó a «{$user->name}» de {$location}",
            $user, $user->name,
            ['sucursal' => [$role ? "{$location} ({$role})" : $location, '—']],
        );

        return response()->json(['message' => "{$user->name} ya no tiene acceso a {$location}."]);
    }

    /**
     * "Sucursal (Rol)" de cada asignación del usuario, ordenado.
     *
     * @return string[]
     */
    private function describeLocationRoles(Model $user): array
    {
        return DB::table('user_location_roles')
            ->join('locations', 'locations.id', '=', 'user_location_roles.location_id')
            ->leftJoin('roles', 'roles.id', '=', 'user_location_roles.role_id')
            ->where('user_location_roles.user_id', $user->getKey())
            ->orderBy('locations.name')
            ->get(['locations.name as location', 'roles.name as role'])
            ->map(fn($r) => $r->role ? "{$r->location} ({$r->role})" : $r->location)
            ->all();
    }

    /**
     * Sincroniza las empresas del usuario.
     *
     * Con company_ids explícitos manda la petición. Sin ellos, el usuario debe
     * pertenecer como mínimo a las empresas de las sucursales que se le asignaron:
     * antes solo se usaba la empresa del header X-Company-ID, así que un usuario
     * creado sin ese contexto —o asignado a una sucursal de otra empresa— quedaba
     * sin fila en user_company y luego no podía entrar a ninguna sucursal.
     *
     * @param int[] $locationIds
     */
    private function syncCompanies(Model $user, Request $request, array $locationIds): void
    {
        if ($request->has('company_ids') && is_array($request->company_ids)) {
            $user->companies()->sync($request->company_ids);
            return;
        }

        $companyIds = [];

        if (!empty($locationIds)) {
            $companyIds = Location::whereIn('id', $locationIds)
                ->pluck('company_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($currentCompanyId = CurrentCompany::id()) {
            $companyIds[] = $currentCompanyId;
        }

        $companyIds = array_values(array_unique($companyIds));

        if (!empty($companyIds)) {
            $user->companies()->syncWithoutDetaching($companyIds);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        if ((int) $model->id === (int) Auth::id()) {
            return ['can_delete' => false, 'message' => 'No puedes eliminar tu propio usuario.'];
        }

        // Quien solo opera su sucursal no puede borrar a alguien que también
        // trabaja en otras: le quitaría el acceso a sucursales que él no ve.
        if ($this->actorIsBranchOnly()) {
            $inOtherBranches = DB::table('user_location_roles')
                ->where('user_id', $model->id)
                ->where('location_id', '!=', (int) $this->currentBranchId())
                ->exists();

            if ($inOtherBranches) {
                return [
                    'can_delete' => false,
                    'message' => 'Este usuario también trabaja en otras sucursales. Quítalo de esta sucursal en su lugar.',
                ];
            }
        }

        // Ventas, cortes, gastos, conteos y movimientos guardan al usuario con
        // borrado en cascada: eliminar a un cajero que dejó la empresa borraba
        // todas sus ventas y cortes de caja. Con historial solo se desactiva
        // (pierde el acceso igual: se le cierran las sesiones).
        $id = $model->id;
        $hasHistory = DB::table('sales')->where('user_id', $id)->exists()
            || DB::table('cash_closings')->where('user_id', $id)->exists()
            || DB::table('cash_register_sessions')->where('user_id', $id)->exists()
            || DB::table('expenses')->where('user_id', $id)->exists()
            || DB::table('inventory_counts')->where('user_id', $id)->exists()
            || DB::table('movements')->where('user_id', $id)->exists()
            || DB::table('sales_reports')->where('user_id', $id)->exists()
            || DB::table('purchases')->where('user_id', $id)->exists();

        if ($hasHistory) {
            return [
                'can_delete' => false,
                'message' => 'Este usuario tiene ventas, cortes u otros movimientos registrados y borrarlo eliminaría ese historial. Desactívalo en su lugar.',
            ];
        }

        return ['can_delete' => true, 'message' => ''];
    }
}
