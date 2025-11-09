<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\ProductionResource;
use App\Models\Production;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ProductionController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = ProductionResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Production::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'details.product',
            'locationDestination',
            'user'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'details.product',
            'locationDestination',
            'user'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtros específicos para producción
        if (isset($params['location_id'])) {
            $query->where('location_destination_id', $params['location_id']);
        }

        if (isset($params['start_date'])) {
            $query->where('movement_date', '>=', $params['start_date']);
        }

        if (isset($params['end_date'])) {
            $query->where('movement_date', '<=', $params['end_date']);
        }

        if (isset($params['product_id'])) {
            $query->whereHas('details', function ($q) use ($params) {
                $q->where('product_id', $params['product_id']);
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            // Campos principales del formulario
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'required|exists:locations,id',
            'production_date' => 'required|date',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.001',
            'comments' => 'nullable|string',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'company_id' => 'sometimes|exists:companies,id',
            'location_id' => 'sometimes|exists:locations,id',
            'production_date' => 'sometimes|date',
            'product_id' => 'sometimes|exists:products,id',
            'quantity' => 'sometimes|numeric|min:0.001',
            'comments' => 'nullable|string',
        ]);
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras y maneja toda la lógica de producción
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            // Extraer datos del producto a producir
            $productId = $data['product_id'];
            $quantity = $data['quantity'];
            unset($data['product_id']);
            unset($data['quantity']);

            // Mapear campos del formulario a la estructura de movements
            if (isset($data['location_id'])) {
                $data['location_destination_id'] = $data['location_id'];
                unset($data['location_id']);
            }

            if (isset($data['production_date'])) {
                $data['movement_date'] = $data['production_date'];
                unset($data['production_date']);
            }

            // Establecer valores por defecto para movements
            $data['movement_type'] = 'production';
            $data['movement_reason'] = 'production';
            $data['reference_type'] = 'production_order';
            $data['user_id'] = Auth::id() ?? 1;
            $data['status'] = 'closed';

            // Crear o actualizar el modelo principal (Production/Movement)
            $production = $callback($data);

            // Crear el detalle con el producto producido
            $production->details()->delete(); // Limpiar detalles anteriores si es update

            $detail = $production->details()->create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => 0, // Se puede calcular el costo basado en ingredientes
                'total_cost' => 0,
                'comments' => 'Producto terminado',
            ]);

            // Procesar la producción: restar ingredientes y agregar producto final
            $production->processProduction();

            DB::commit();
            return $production;
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
        // No se puede eliminar producciones porque ya afectaron el stock
        return [
            'can_delete' => false,
            'message' => 'No se puede eliminar una producción porque ya afectó el inventario'
        ];
    }
}
