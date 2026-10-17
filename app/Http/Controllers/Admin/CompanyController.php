<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\CompanyResource;
use App\Models\Admin\Company;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanyController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = CompanyResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Company::class;
    protected ?string $permissionPrefix = 'companies';
    protected ?string $companyColumn = null;

    /**
     * El listado de compañías no requiere permiso.
     * Cualquier usuario autenticado debe poder ver las compañías donde trabaja
     * para poder seleccionarla, independientemente de sus permisos.
     * El filtrado por compañías del usuario se aplica en handleQuery().
     * Las demás acciones (crear/editar/eliminar/ver detalle) siguen protegidas
     * por el prefijo 'companies'.
     */
    protected function canIndex(): bool
    {
        return true;
    }

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el índice
            // Ejemplo: 'category', 'unit'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el show
            // Ejemplo: 'category', 'unit', 'suppliers'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Scope por usuario: si NO tiene el permiso de listar compañías
        // (permiso de super admin), solo puede ver las compañías a las que
        // está asignado vía la tabla pivote user_company. Así el selector de
        // compañía en el login muestra únicamente las compañías del usuario.
        // Los super admins (con companies_list) ven todas para administración.
        if (!CurrentWorker::hasPermission('companies_list')) {
            $userId = Auth::id();
            $query->whereHas('users', fn($q) => $q->where('users.id', $userId));
        }

        // Filtro por estado activo/inactivo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Filtro por RFC
        if (isset($params['rfc'])) {
            $query->where('rfc', 'like', '%' . $params['rfc'] . '%');
        }

        // Filtro por ciudad (si está en la dirección)
        if (isset($params['city'])) {
            $query->where('address', 'like', '%' . $params['city'] . '%');
        }

        // Búsqueda en múltiples campos
        if (isset($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        $this->normalizeRfc($request);

        return $request->validate($this->companyRules(), $this->companyMessages());
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        $this->normalizeRfc($request);

        return $request->validate($this->companyRules((int) $model->id), $this->companyMessages());
    }

    /**
     * Reglas de alta y edición. Los límites son los de las columnas: antes se
     * validaban 255 en el nombre y el correo y 500 en la dirección, y un texto
     * largo tronaba en MySQL. Razón social, RFC y correo son opcionales: la
     * base los acepta vacíos, el ticket ya los omite, y un negocio sin RFC no
     * podía ni cambiar su dirección.
     */
    private function companyRules(?int $ignoreId = null): array
    {
        $unique = fn(string $column) => 'unique:companies,' . $column . ($ignoreId ? ',' . $ignoreId : '');

        return [
            'name' => ['required', 'string', 'max:150', $unique('name')],
            'business_name' => 'nullable|string|max:255',
            // 3 letras (empresa) o 4 (persona física); la Ñ y el & existen en
            // RFC reales y antes se rechazaban.
            'rfc' => ['nullable', 'string', 'max:13', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $unique('rfc')],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]+$/',
            'email' => ['nullable', 'email', 'max:100', $unique('email')],
            'is_active' => 'nullable|boolean',
        ];
    }

    private function companyMessages(): array
    {
        return [
            'rfc.regex' => 'Revisa el RFC: son 12 caracteres si es empresa o 13 si es persona física.',
            'rfc.unique' => 'Ya hay otra empresa con ese RFC.',
            'name.unique' => 'Ya hay otra empresa con ese nombre.',
            'email.unique' => 'Ya hay otra empresa con ese correo.',
        ];
    }

    /**
     * RFC en mayúsculas y sin espacios, puntos ni guiones antes de validarlo:
     * escrito en minúsculas ("cfr010101ab1") daba 422 aunque estuviera bien.
     */
    private function normalizeRfc(Request $request): void
    {
        if (!$request->has('rfc')) {
            return;
        }
        $rfc = preg_replace('/[\s.\-]/u', '', (string) $request->input('rfc'));
        $request->merge(['rfc' => $rfc === '' ? null : mb_strtoupper($rfc)]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Normalizar RFC a mayúsculas
        if (isset($validatedData['rfc'])) {
            $validatedData['rfc'] = strtoupper(trim($validatedData['rfc']));
        }

        // Normalizar email a minúsculas
        if (isset($validatedData['email'])) {
            $validatedData['email'] = strtolower(trim($validatedData['email']));
        }

        // Limpiar teléfono de espacios extra
        if (isset($validatedData['phone'])) {
            $validatedData['phone'] = trim($validatedData['phone']);
        }

        // Establecer is_active por defecto si no se proporciona
        if (!isset($validatedData['is_active'])) {
            $validatedData['is_active'] = true;
        }

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Normalizar RFC a mayúsculas
        if (isset($validatedData['rfc'])) {
            $validatedData['rfc'] = strtoupper(trim($validatedData['rfc']));
        }

        // Normalizar email a minúsculas
        if (isset($validatedData['email'])) {
            $validatedData['email'] = strtolower(trim($validatedData['email']));
        }

        // Limpiar teléfono de espacios extra
        if (isset($validatedData['phone'])) {
            $validatedData['phone'] = trim($validatedData['phone']);
        }

        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            $model = $callback($data);

            // Aquí puedes agregar lógica adicional específica del modelo
            // Ejemplo: manejar relaciones, archivos, etc.

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Acciones después de crear (opcional)
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Log de auditoría
        Log::info('Nueva compañía creada', [
            'company_id' => $model->id,
            'name' => $model->name,
            'rfc' => $model->rfc,
            'created_by' => $request->user()?->id ?? null
        ]);

        // Aquí podrías crear ubicación principal por defecto
        // $model->locations()->create([
        //     'name' => 'Oficina Principal',
        //     'address' => $model->address,
        //     'is_main' => true
        // ]);
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Lógica adicional después de actualizar
        // Ejemplo: sincronizar relaciones, logs, etc.
    }

    /**
     * Aislamiento: show/update/destroy solo sobre compañías a las que el actor
     * tiene acceso (pertenece a ellas o es administrador del sistema). El modelo
     * es la propia empresa, así que no se aísla con la columna company_id
     * genérica de CrudController (companyColumn = null).
     */
    protected function findModel($id): ?Model
    {
        return $this->accessibleCompany($id);
    }

    protected function findModelForShow($id): ?Model
    {
        return $this->accessibleCompany($id);
    }

    private function accessibleCompany($id): ?Company
    {
        $company = Company::find($id);
        if (!$company) {
            return null;
        }

        $actor = Auth::user();
        if ($actor && !CurrentWorker::canAccessCompany((int) $company->id, $actor)) {
            return null; // 404: no es su empresa
        }

        return $company;
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Verificar si la compañía tiene usuarios asociados
        if ($model->users()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar la compañía porque tiene usuarios asociados'
            ];
        }

        // Verificar si la compañía tiene ubicaciones asociadas.
        // Se consulta por tabla para no depender de relaciones inexistentes en
        // el modelo (Company::locations() no existe y rompía el borrado con 500).
        if (DB::table('locations')->where('company_id', $model->id)->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar la compañía porque tiene ubicaciones asociadas'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
