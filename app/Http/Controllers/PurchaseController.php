<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PurchaseController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = PurchaseResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Purchase::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'details.product',
            'location',
            'supplier',
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
            'location',
            'user'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtros específicos para compras
        if (isset($params['location_id'])) {
            $query->where('location_origin_id', $params['location_id']);
        }

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['start_date'])) {
            $query->where('movement_date', '>=', $params['start_date']);
        }

        if (isset($params['end_date'])) {
            $query->where('movement_date', '<=', $params['end_date']);
        }

        if (isset($params['document_number'])) {
            $query->where('document_number', 'like', '%' . $params['document_number'] . '%');
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
            'location_origin_id' => 'required|exists:locations,id',
            'purchase_date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'status' => 'nullable|in:draft,ordered,in_transit,received',
            'comments' => 'nullable|string',

            // Productos seleccionados para la compra
            'purchase_items' => 'required|array|min:1',
            'purchase_items.*.product_id' => 'required|exists:products,id',
            'purchase_items.*.quantity' => 'required|numeric|min:0.001',
            'purchase_items.*.unit_price' => 'required|numeric|min:0',
            'purchase_items.*.total_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        // Verificar si la compra puede editarse
        if ($model->status !== 'draft' && $model->status !== \App\Enums\PurchaseStatus::DRAFT) {
            throw new \Exception('Solo se pueden editar compras en estado de borrador');
        }

        return $request->validate([
            // Campos principales del formulario
            'company_id' => 'sometimes|exists:companies,id',
            'location_origin_id' => 'exists:locations,id',
            'purchase_date' => 'sometimes|date',
            'supplier_id' => 'exists:suppliers,id',
            'status' => 'sometimes|in:draft,ordered,in_transit,received',
            'comments' => 'nullable|string',

            // Productos seleccionados para la compra
            'purchase_items' => 'sometimes|array|min:1',
            'purchase_items.*.product_id' => 'required|exists:products,id',
            'purchase_items.*.quantity' => 'required|numeric|min:0.001',
            'purchase_items.*.unit_price' => 'required|numeric|min:0',
            'purchase_items.*.total_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras y maneja toda la lógica de compras
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            // Extraer purchase_items para procesamiento separado
            $purchaseItems = $data['purchase_items'] ?? [];
            unset($data['purchase_items']);

            // Mapear campos del formulario a la estructura de movements
            if (isset($data['purchase_date'])) {
                $data['movement_date'] = $data['purchase_date'];
                unset($data['purchase_date']);
            }

            // Establecer valores por defecto para movements
            $data['movement_type'] = 'entry';
            $data['movement_reason'] = 'purchase';
            $data['reference_type'] = 'purchase_order';
            $data['user_id'] = Auth::id() ?? 1;

            // Establecer status por defecto si no se proporciona
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }

            if (isset($data['comments'])) {
                $data['notes'] = $data['comments'];
            }

            $purchase = $callback($data);

            // Calcular total y manejar detalles
            $totalAmount = 0;

            if ($method === 'create') {
                // Para crear: agregar todos los purchase_items como detalles
                foreach ($purchaseItems as $item) {
                    $detail = $purchase->details()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_price'],
                        'total_cost' => $item['total_price'],
                        'comments' => null,
                    ]);

                    $totalAmount += $item['total_price'];
                }
            } else {
                // Para actualizar: eliminar detalles existentes y crear nuevos
                $purchase->details()->delete();

                foreach ($purchaseItems as $item) {
                    $detail = $purchase->details()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_price'],
                        'total_cost' => $item['total_price'],
                        'comments' => null,
                    ]);

                    $totalAmount += $item['total_price'];
                }
            }

            // Actualizar total de la compra
            $purchase->update(['total_cost' => $totalAmount]);

            DB::commit();
            return $purchase;
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
        // No se puede eliminar compras que han sido recibidas (afectan stock)
        if ($model->status === \App\Enums\PurchaseStatus::RECEIVED || $model->status === 'received') {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar una compra recibida porque afecta el stock'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Validar si se puede editar una compra
     */
    protected function canUpdate(Model $model): array
    {
        // Solo se pueden editar compras en borrador
        if ($model->status !== \App\Enums\PurchaseStatus::DRAFT && $model->status !== 'draft') {
            return [
                'can_update' => false,
                'message' => 'Solo se pueden editar compras en estado de borrador'
            ];
        }

        return [
            'can_update' => true,
            'message' => ''
        ];
    }

    /**
     * Override del método update para verificar permisos de edición
     */
    public function update(Request $request, $id)
    {
        $model = $this->model::findOrFail($id);

        $canUpdate = $this->canUpdate($model);
        if (!$canUpdate['can_update']) {
            return response()->json([
                'success' => false,
                'message' => $canUpdate['message']
            ], 422);
        }

        return parent::update($request, $id);
    }

    /**
     * Avanzar al siguiente estado en el flujo
     */
    public function advance(Request $request, $id)
    {
        try {
            $purchase = Purchase::findOrFail($id);

            if ($purchase->advanceStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Estado actualizado a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede avanzar al siguiente estado'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Retroceder al estado anterior en el flujo
     */
    public function revert(Request $request, $id)
    {
        try {
            $purchase = Purchase::findOrFail($id);

            if ($purchase->revertStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Estado revertido a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede retroceder al estado anterior'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Transicionar a un estado específico
     */
    public function transitionTo(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:draft,ordered,in_transit,received'
        ]);

        try {
            $purchase = Purchase::findOrFail($id);
            $newStatus = \App\Enums\PurchaseStatus::from($request->status);

            if ($purchase->transitionTo($newStatus)) {
                return response()->json([
                    'success' => true,
                    'message' => "Estado actualizado a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede realizar la transición'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener información de estados disponibles
     */
    public function statusInfo()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Enums\PurchaseStatus::options()
        ]);
    }
}
