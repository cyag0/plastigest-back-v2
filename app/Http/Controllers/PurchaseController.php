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
            'location_origin_id' => 'required|exists:locations,id',
            'movement_date' => 'required|date',
            'document_number' => 'nullable|string|max:50',
            'status' => 'nullable|in:open,closed',

            // Información del proveedor
            'supplier_name' => 'required|string|max:255',
            'supplier_phone' => 'nullable|string|max:20',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_address' => 'nullable|string',

            'comments' => 'nullable|string',

            // Detalles de la compra
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_cost' => 'required|numeric|min:0',
            'details.*.notes' => 'nullable|string',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'location_origin_id' => 'sometimes|exists:locations,id',
            'movement_date' => 'sometimes|date',
            'document_number' => 'nullable|string|max:50',
            'status' => 'sometimes|in:open,closed',

            // Información del proveedor
            'supplier_name' => 'sometimes|string|max:255',
            'supplier_phone' => 'nullable|string|max:20',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_address' => 'nullable|string',

            'comments' => 'nullable|string',

            // Detalles de la compra
            'details' => 'sometimes|array|min:1',
            'details.*.id' => 'sometimes|exists:movements_details,id',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_cost' => 'required|numeric|min:0',
            'details.*.notes' => 'nullable|string',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Agregar company_id y user_id del usuario autenticado
        $validatedData['company_id'] = Auth::user()->company_id ?? 1; // Temporal
        $validatedData['user_id'] = Auth::id() ?? 1; // Temporal

        // Establecer valores por defecto
        $validatedData['status'] = $validatedData['status'] ?? 'open';

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // No permitir cambiar company_id y user_id
        unset($validatedData['company_id']);
        unset($validatedData['user_id']);

        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras y maneja toda la lógica de compras
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            // Extraer detalles para procesamiento separado
            $details = $data['details'] ?? [];
            unset($data['details']);

            // Procesar información del proveedor
            if (isset($data['supplier_name'])) {
                $supplierInfo = [
                    'name' => $data['supplier_name'],
                    'phone' => $data['supplier_phone'] ?? null,
                    'email' => $data['supplier_email'] ?? null,
                    'address' => $data['supplier_address'] ?? null,
                ];

                // Guardar info del proveedor en comments si no hay comments
                if (empty($data['comments'])) {
                    $data['comments'] = 'SUPPLIER:' . json_encode($supplierInfo);
                }

                // Limpiar campos individuales del proveedor
                unset($data['supplier_name'], $data['supplier_phone'], $data['supplier_email'], $data['supplier_address']);
            }

            // Crear o actualizar el modelo principal
            $purchase = $callback($data);

            // Calcular total
            $totalAmount = 0;

            if ($method === 'create') {
                // Para crear: agregar todos los detalles
                foreach ($details as $detail) {
                    $detailModel = $purchase->details()->create([
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                        'unit_cost' => $detail['unit_cost'],
                        'total_cost' => $detail['quantity'] * $detail['unit_cost'],
                        'notes' => $detail['notes'] ?? null,
                    ]);

                    $totalAmount += $detailModel->total_cost;
                }
            } else {
                // Para actualizar: manejar detalles existentes y nuevos
                $existingDetailIds = [];

                foreach ($details as $detail) {
                    if (isset($detail['id'])) {
                        // Actualizar detalle existente
                        $existingDetailIds[] = $detail['id'];
                        $detailModel = $purchase->details()->findOrFail($detail['id']);
                        $detailModel->update([
                            'product_id' => $detail['product_id'],
                            'quantity' => $detail['quantity'],
                            'unit_cost' => $detail['unit_cost'],
                            'total_cost' => $detail['quantity'] * $detail['unit_cost'],
                            'notes' => $detail['notes'] ?? null,
                        ]);
                    } else {
                        // Crear nuevo detalle
                        $detailModel = $purchase->details()->create([
                            'product_id' => $detail['product_id'],
                            'quantity' => $detail['quantity'],
                            'unit_cost' => $detail['unit_cost'],
                            'total_cost' => $detail['quantity'] * $detail['unit_cost'],
                            'notes' => $detail['notes'] ?? null,
                        ]);
                        $existingDetailIds[] = $detailModel->id;
                    }

                    $totalAmount += $detailModel->total_cost;
                }

                // Eliminar detalles que no están en la actualización
                $purchase->details()->whereNotIn('id', $existingDetailIds)->delete();
            }

            // Actualizar total de la compra
            $purchase->update(['total_cost' => $totalAmount]);

            // Si la compra está cerrada, integrar con inventario
            if ($purchase->status === 'closed') {
                $this->integrateWithInventory($purchase);
            }

            DB::commit();
            return $purchase;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Integrar compra cerrada con el sistema de inventario
     */
    private function integrateWithInventory(Purchase $purchase): void
    {
        // Solo integrar si aún no se ha integrado
        if (!$purchase->inventory_integrated) {
            $inventoryService = app(\App\Services\InventoryService::class);

            $movementData = [
                'company_id' => $purchase->company_id,
                'location_id' => $purchase->location_origin_id,
                'movement_type' => 'entry',
                'movement_reason' => 'purchase',
                'document_number' => $purchase->document_number,
                'reference_id' => $purchase->id,
                'reference_type' => 'purchase_order',
                'movement_date' => $purchase->movement_date,
                'notes' => "Compra integrada: {$purchase->purchase_number}",
                'products' => []
            ];

            foreach ($purchase->details as $detail) {
                $movementData['products'][] = [
                    'product_id' => $detail->product_id,
                    'quantity' => $detail->quantity,
                    'unit_cost' => $detail->unit_cost,
                    'notes' => $detail->notes
                ];
            }

            $inventoryService->processMovement($movementData);

            // Marcar como integrado (necesitaríamos agregar este campo)
            // $purchase->update(['inventory_integrated' => true]);
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
