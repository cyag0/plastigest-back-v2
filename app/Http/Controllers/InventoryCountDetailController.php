<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\CrudController;
use App\Http\Resources\InventoryCountDetailResource;
use App\Models\InventoryCount;
use App\Models\InventoryCountDetail;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryCountDetailController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = InventoryCountDetailResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = InventoryCountDetail::class;
    protected ?string $permissionPrefix = 'inventory';
    protected ?string $companyColumn = null;
    protected ?string $locationColumn = 'location_id';

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'product',
            'location',
            'inventoryCount',
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'product',
            'location',
            'inventoryCount',
        ];
    }

    /**
     * Los renglones no tienen company_id: se aíslan por la empresa de su
     * conteo (antes un administrador veía y editaba renglones de cualquier
     * empresa) y, para quien no ve todas las sucursales, por la sucursal
     * (locationColumn).
     */
    protected function applyTenantScope($query)
    {
        $query = parent::applyTenantScope($query);

        if ($companyId = CurrentCompany::id()) {
            $query->whereHas('inventoryCount', fn($count) => $count->where('company_id', $companyId));
        }

        return $query;
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        if (isset($params['inventory_count_id'])) {
            $query->where('inventory_count_id', $params['inventory_count_id']);
        }

        if (isset($params['product_id'])) {
            $query->where('product_id', $params['product_id']);
        }

        if (isset($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        $companyId = CurrentCompany::id();

        return $request->validate([
            // Conteo y producto de la empresa (antes cualquier id existente:
            // se podía escribir en el conteo de otra sucursal o empresa).
            'inventory_count_id' => ['required', 'integer', Rule::exists('inventory_counts', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            /* 'location_id' => 'required|exists:locations,id', */
            // system_quantity ya no se toma del cliente: se calcula en el servidor
            // (ver applyCount) con el stock real, para que nadie pueda falsear la
            // "cantidad del sistema" y con ella la diferencia/ajuste.
            'system_quantity' => 'nullable|numeric',
            'counted_quantity' => 'nullable|numeric|min:0',
            // "Correcto" y "Marcar todo como correcto": lo contado es la
            // existencia del servidor al guardar; counted_quantity se ignora.
            'matches_system' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ], [
            'inventory_count_id.exists' => 'El conteo no existe en tu empresa.',
            'product_id.exists' => 'El producto no existe en tu empresa.',
        ]);
    }

    /**
     * La cantidad del sistema no viene del cliente: la decide applyCount() con
     * el conteo bloqueado.
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        unset($validatedData['system_quantity']);

        return $validatedData;
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'counted_quantity' => 'nullable|numeric|min:0',
            'matches_system' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     * Este método maneja tanto store como update de forma unificada
     * Usa transacciones para operaciones seguras
     * Cambia el estado del inventario a "counting" cuando se empieza a contar
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        return DB::transaction(function () use ($callback, $data, $method) {
            if ($method === 'update') {
                // edit() guarda con el renglón y el conteo bloqueados.
                return $callback($data);
            }

            $inventoryCount = $this->lockOpenCount((int) $data['inventory_count_id']);

            // Un renglón por producto: si ya existe se actualiza.
            $detail = InventoryCountDetail::where('inventory_count_id', $inventoryCount->id)
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first()
                ?? new InventoryCountDetail([
                    'inventory_count_id' => $inventoryCount->id,
                    'product_id' => $data['product_id'],
                ]);

            $this->applyCount($detail, $inventoryCount, $data);

            // Cambiar estado del inventario a "counting" si está en "planning"
            if ($inventoryCount->status === 'planning') {
                $inventoryCount->status = 'counting';
                $inventoryCount->save();
            }

            return $detail;
        });
    }

    /**
     * Actualiza un renglón (PUT) con las mismas reglas que al guardarlo desde
     * la pantalla del conteo.
     */
    public function edit(array $data, int $id): Model
    {
        $detail = $this->scopedQuery()->lockForUpdate()->find($id);
        if (!$detail) {
            abort(404, 'Recurso no encontrado');
        }

        $this->applyCount($detail, $this->lockOpenCount((int) $detail->inventory_count_id), $data);

        return $detail;
    }

    /**
     * Conteo abierto (en planeación o contando), bloqueado hasta el fin de la
     * transacción. Completar el conteo también lo bloquea: ningún renglón se
     * guarda mientras se aplican las diferencias ni después (422).
     *
     * Quien no ve todas las sucursales solo cuenta en la suya (404 para el
     * conteo de otra sucursal): antes podía escribir en el conteo de otra
     * sucursal y la diferencia se aplicaba al cerrarlo.
     */
    private function lockOpenCount(int $inventoryCountId): InventoryCount
    {
        $inventoryCount = InventoryCount::where('company_id', CurrentCompany::id())
            ->lockForUpdate()
            ->find($inventoryCountId);

        $outOfBranch = $inventoryCount
            && !CurrentWorker::canAccessAllLocations()
            && (int) $inventoryCount->location_id !== (int) CurrentLocation::id();

        if (!$inventoryCount || $outOfBranch) {
            abort(404, 'Conteo no encontrado');
        }

        if (!in_array($inventoryCount->status, ['planning', 'counting'], true)) {
            $state = $inventoryCount->status === 'completed' ? 'completado' : 'cancelado';
            throw new BusinessRuleException("Este conteo ya está {$state}; ya no se pueden cambiar sus productos.", 'count_closed');
        }

        return $inventoryCount;
    }

    /**
     * Guarda lo contado de un producto. La cantidad del sistema es la
     * existencia del producto en la sucursal del conteo al momento de contar:
     *
     * - matches_system ("Correcto"): lo contado es esa existencia, la del
     *   servidor. Antes la pantalla mandaba la que cargó al abrirse y, si
     *   entre tanto hubo ventas o entregas, la diferencia salía fantasma (20
     *   cocos vendidos se volvían +20 al cerrar el conteo).
     * - Renglón nuevo o cantidad contada distinta: se toma la existencia de
     *   ahora contra lo contado.
     * - Mismo conteo (p. ej. solo cambian las notas): se conservan las dos.
     *   Antes cada guardado volvía a tomar la existencia y la diferencia
     *   cambiaba sola con las ventas.
     */
    private function applyCount(InventoryCountDetail $detail, InventoryCount $inventoryCount, array $data): void
    {
        $locationId = (int) $inventoryCount->location_id;
        $liveStock = fn(): float => (float) (DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $locationId)
            ->value('current_stock') ?? 0);

        $counted = $data['counted_quantity'] ?? null;

        if (!empty($data['matches_system'])) {
            $system = $liveStock();
            $detail->system_quantity = $system;
            $detail->counted_quantity = $system;
        } elseif (!$detail->exists) {
            $detail->system_quantity = $liveStock();
            $detail->counted_quantity = $counted;
        } elseif ($counted !== null && ($detail->counted_quantity === null
            || round((float) $counted, 3) !== round((float) $detail->counted_quantity, 3))) {
            $detail->system_quantity = $liveStock();
            $detail->counted_quantity = $counted;
        }

        if (array_key_exists('notes', $data)) {
            $detail->notes = $data['notes'];
        }

        // La sucursal del conteo (antes la sucursal actual de quien guardaba).
        $detail->location_id = $locationId;
        $detail->save();
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Un conteo cerrado ya aplicó (o descartó) sus diferencias: sus
        // renglones son el registro de lo que se contó.
        if (!in_array($model->inventoryCount?->status, ['planning', 'counting'], true)) {
            return [
                'can_delete' => false,
                'message' => 'No se pueden quitar productos de un conteo completado o cancelado.'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
