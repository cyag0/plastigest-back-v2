<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\LocationResource;
use App\Models\Admin\Location;
use App\Support\CurrentCompany;
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
