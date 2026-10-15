<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\LocationResource;
use App\Models\Admin\Location;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Container\Attributes\Log;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log as FacadesLog;

class LocationController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = LocationResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Location::class;
    protected ?string $permissionPrefix = 'locations';

    /**
     * El listado de sucursales no requiere permiso.
     * Cualquier usuario autenticado debe poder ver las sucursales donde trabaja
     * para poder seleccionarla, independientemente de sus permisos.
     * El filtrado por sucursales del usuario se aplica en handleQuery().
     * Las demás acciones (crear/editar/eliminar/ver detalle) siguen protegidas
     * por el prefijo 'locations'.
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
            'company'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        /** @var \Illuminate\Database\Eloquent\Builder $query */

        $company = CurrentCompany::get();

        if ($company) {
            $query->where('company_id', $company->id);
        }

        // Scope por usuario: quien no tiene acceso a todas las sucursales
        // (system_admin o locations_all) solo ve las sucursales a las que está
        // asignado vía la tabla user_location_roles. Así el selector de
        // sucursal en el login muestra únicamente las sucursales a las que
        // puede entrar. Antes bastaba locations_list ("Listar ubicaciones").
        if (!CurrentWorker::canAccessAllLocations()) {
            $userId = Auth::id();
            $query->whereIn('id', function ($q) use ($userId) {
                $q->select('location_id')
                    ->from('user_location_roles')
                    ->where('user_id', $userId);
            });
        }

        FacadesLog::info('HandleQuery Params: ', $params);

        // Filtro por tipo de locación
        if (isset($params['location_type'])) {
            $query->where('location_type', $params['location_type']);
        }

        // Filtro por código postal
        if (isset($params['postal_code'])) {
            $query->where('postal_code', 'like', "%{$params['postal_code']}%");
        }

        // Filtro por ciudad
        if (isset($params['city'])) {
            $query->where('city', 'like', "%{$params['city']}%");
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);

        // Quien solo opera su sucursal edita sus datos, pero no la desactiva
        // (su personal ya no podría elegirla al entrar) ni la pasa a otra
        // empresa: eso es de quien administra todas. El formulario reenvía
        // todos los campos, así que solo cuenta lo que de verdad cambia.
        if (!CurrentWorker::canAccessAllLocations()) {
            if (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== (bool) $model->is_active) {
                abort($this->forbiddenResponse('activar o desactivar sucursales'));
            }
            if ((int) $validated['company_id'] !== (int) $model->company_id) {
                abort($this->forbiddenResponse('mover la sucursal a otra empresa'));
            }
        }

        return $validated;
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Asegurar que is_active tenga un valor por defecto
        if (!isset($validatedData['is_active'])) {
            $validatedData['is_active'] = true;
        }

        return $validatedData;
    }

    /**
     * La empresa de la sucursal tiene que ser una del actor (o ser
     * administrador del sistema). Sin esto, un administrador de la empresa A
     * podía crear/mover sucursales hacia la empresa B.
     */
    private function companyIsAccessible(Request $request): bool
    {
        $companyId = $request->input('company_id');
        if ($companyId === null) {
            return true; // validate() se encarga de exigirlo
        }

        return CurrentWorker::canAccessCompany((int) $companyId, Auth::user());
    }

    public function store(Request $request)
    {
        if (!$this->canStore()) {
            return $this->forbiddenResponse('crear este recurso');
        }
        if (!$this->companyIsAccessible($request)) {
            return $this->forbiddenResponse('crear sucursales en esa empresa');
        }

        return parent::store($request);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canEdit()) {
            return $this->forbiddenResponse('editar este recurso');
        }
        if (!$this->companyIsAccessible($request)) {
            return $this->forbiddenResponse('mover la sucursal a esa empresa');
        }

        return parent::update($request, $id);
    }

    /**
     * Ver, editar o eliminar una sucursal: quien no tiene acceso a todas las
     * sucursales (system_admin o locations_all) solo puede con la actual, como
     * SettingsController::authorizeLocation. El listado ya ocultaba las demás,
     * pero por id se podía ver, renombrar o desactivar cualquier sucursal de
     * la empresa.
     */
    protected function findModel($id): ?Model
    {
        return $this->accessibleLocationQuery()->find($id);
    }

    protected function findModelForShow($id): ?Model
    {
        return $this->accessibleLocationQuery()->with($this->getShowRelations())->find($id);
    }

    private function accessibleLocationQuery()
    {
        $query = $this->scopedQuery();

        if (!CurrentWorker::canAccessAllLocations()) {
            $locationId = CurrentLocation::id();
            $locationId ? $query->whereKey($locationId) : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // La validación de empresa accesible se hace en update() antes de llegar
        // aquí (companyIsAccessible), para devolver 403 y no un 500.

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
        // Dar de alta en la sucursal nueva los productos que ya existen en la
        // empresa. El listado, compras y ventas filtran por product_location:
        // sin esta fila la sucursal nace sin catálogo.
        $now = now();
        $rows = DB::table('products')
            ->where('company_id', $model->company_id)
            ->pluck('id')
            ->map(fn($productId) => [
                'product_id'    => $productId,
                'location_id'   => $model->id,
                'active'        => true,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_location')->insert($chunk);
        }

        // Los administradores del sistema de la empresa están en todas las
        // sucursales con su rol de sistema (UserSeeder): así les llegan los
        // avisos de cada una. Sin esto, la sucursal nueva no les avisaba nada.
        $admins = DB::table('users_roles')
            ->join('rol_permission', 'rol_permission.role_id', '=', 'users_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
            ->join('user_company', 'user_company.user_id', '=', 'users_roles.user_id')
            ->where('permissions.name', 'system_admin')
            ->where('user_company.company_id', $model->company_id)
            ->pluck('users_roles.role_id', 'users_roles.user_id');

        foreach ($admins as $userId => $roleId) {
            DB::table('user_location_roles')->insertOrIgnore([
                'user_id' => $userId, 'location_id' => $model->id, 'role_id' => $roleId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
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
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Las ventas, compras, movimientos de caja, cortes, kárdex y gastos de
        // la sucursal se borran en cascada con ella: un clic borraba todo su
        // historial. Con actividad solo se puede desactivar.
        $id = $model->id;
        $inUse = DB::table('sales')->where('location_id', $id)->exists()
            || DB::table('purchases')->where('location_id', $id)->exists()
            || DB::table('stock_ledger_entries')->where('location_id', $id)->exists()
            || DB::table('cash_movements')->where('location_id', $id)->exists()
            || DB::table('inventory_transfers')->where('from_location_id', $id)->orWhere('to_location_id', $id)->exists()
            || DB::table('product_location')->where('location_id', $id)->where('current_stock', '!=', 0)->exists()
            || DB::table('cash_registers')->where('location_id', $id)->exists();

        if ($inUse) {
            return [
                'can_delete' => false,
                'message' => 'Esta sucursal ya tiene movimientos (ventas, compras, stock o cajas) y borrarla eliminaría su historial. Desactívala en su lugar.',
            ];
        }

        return ['can_delete' => true, 'message' => ''];
    }
}
