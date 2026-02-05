<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UserResource;
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

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return ['companies'];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return ['companies'];
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
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
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
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
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

        // Agregar la compañía actual si no se proporcionó
        $company = CurrentCompany::get();
        if ($company && !isset($validatedData['company_ids'])) {
            $validatedData['company_ids'] = [$company->id];
        }

        return $validatedData;
    }    /**
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

        // Sincronizar empresas
        if ($request->has('company_ids') && is_array($request->company_ids)) {
            $user->companies()->sync($request->company_ids);
        }

        // Sincronizar sucursales
        if ($request->has('location_ids') && is_array($request->location_ids)) {
            $user->locations()->sync($request->location_ids);
        }

        // Recargar las relaciones
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

        if ($request->has('company_ids')) {
            $user->companies()->sync($request->company_ids);
        }

        if ($request->has('location_ids')) {
            $user->locations()->sync($request->location_ids);
        }

        // Recargar las relaciones
        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $user->load($relations);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // No permitir eliminar si tiene workers asociados
        if ($model->workers()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar porque tiene trabajadores asociados'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
