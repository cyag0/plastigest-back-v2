<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UserResource;
use App\Models\Admin\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return ['companies', 'roles'];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return ['companies', 'roles', 'locationRoles'];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar usuarios por compañía específica
        if (isset($params['by_company'])) {
            $company = CurrentCompany::get();
            if ($company) {
                $query->whereHas('companies', function ($q) use ($company) {
                    $q->where('companies.id', $company->id);
                });
            }
        }

        // Filtros adicionales
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }
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
        $this->syncCompanies($user, $request, $locationIds);

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
        $this->syncCompanies($user, $request, $locationIds);

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

        return array_keys($sync);
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
        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
