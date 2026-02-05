<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\InventoryCountDetailResource;
use App\Models\InventoryCountDetail;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\CurrentLocation;

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
        return $request->validate([
            'inventory_count_id' => 'required|exists:inventory_counts,id',
            'product_id' => 'required|exists:products,id',
            /* 'location_id' => 'required|exists:locations,id', */
            'system_quantity' => 'required|numeric|min:0',
            'counted_quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'counted_quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     * Este método maneja tanto store como update de forma unificada
     * Usa transacciones para operaciones seguras
     * Cambia el estado del inventario a "in_progress" cuando se empieza a contar
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            CurrentLocation::id();

            $data[]
            $detail = InventoryCountDetail::whereProductId($data['product_id'])
                ->whereInventoryCountId($data['inventory_count_id']);

            if ($detail->exists()) {
                // Actualizar
                $detail = $detail->first();
                $detail->fill($data);
                $detail->save();
            } else {
                // Crear
                $detail = InventoryCountDetail::create($data);
            }

            /** @var InventoryCountDetail $detail */


            // Cambiar estado del inventario a "in_progress" si está en "planning"
            if ($method === 'create') {
                $inventoryCount = $detail->inventoryCount;
                if ($inventoryCount->status === 'planning') {
                    $inventoryCount->status = 'counting';
                    $inventoryCount->save();
                }
            }

            DB::commit();
            return $detail;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Validaciones para eliminar
        // Ejemplo:
        // if ($model->orders()->exists()) {
        //     return [
        //         'can_delete' => false,
        //         'message' => 'No se puede eliminar porque tiene órdenes asociadas'
        //     ];
        // }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
