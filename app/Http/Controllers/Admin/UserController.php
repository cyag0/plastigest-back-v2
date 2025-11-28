<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar usuarios por la compañía actual
        $company = CurrentCompany::get();
        if ($company) {
            $query->whereHas('companies', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            });
        }

        // Filtros adicionales
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',

            // Relaciones many-to-many opcionales
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $model->id,
            'password' => 'nullable|string|min:8',

            // Relaciones many-to-many opcionales
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);
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

        // Agregar la compañía actual si no se proporcionó
        $company = CurrentCompany::get();
        if ($company && !isset($validatedData['company_ids'])) {
            $validatedData['company_ids'] = [$company->id];
        }

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
