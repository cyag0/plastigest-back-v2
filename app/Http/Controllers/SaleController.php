<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Enums\SaleStatus;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaleController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = SaleResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Sale::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'details.product',
            'location',
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'details.product.mainImage',
            'location',
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar por estado
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        // Filtrar por rango de fechas
        if (isset($params['start_date']) && isset($params['end_date'])) {
            $query->betweenDates($params['start_date'], $params['end_date']);
        }

        // Filtrar por ubicación
        if (isset($params['location_id'])) {
            $query->where('location_origin_id', $params['location_id']);
        }

        // Filtrar por método de pago
        if (isset($params['payment_method'])) {
            $query->whereJsonContains('content->payment_method', $params['payment_method']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'location_id' => 'required|exists:locations,id',
            'company_id' => 'required|exists:companies,id',
            'movement_date' => 'required|date',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Información del cliente (opcional)
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:255',

            // Método de pago
            'payment_method' => 'required|in:efectivo,tarjeta,transferencia',
            'received_amount' => 'required_if:payment_method,efectivo|nullable|numeric|min:0',
            'notes' => 'nullable|string',

            // Detalles de la venta
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'location_id' => 'sometimes|required|exists:locations,id',
            'company_id' => 'required|exists:companies,id',
            'movement_date' => 'sometimes|required|date',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Información del cliente (opcional)
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:255',

            // Método de pago
            'payment_method' => 'sometimes|required|in:efectivo,tarjeta,transferencia',
            'received_amount' => 'required_if:payment_method,efectivo|nullable|numeric|min:0',
            'notes' => 'nullable|string',

            // Detalles de la venta
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            // Preparar datos del content
            $content = [
                'payment_method' => $data['payment_method'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            // Si es efectivo, agregar monto recibido
            if ($data['payment_method'] === 'efectivo') {
                $content['received_amount'] = $data['received_amount'] ?? 0;
            }

            $user = Auth::user();

            // Preparar datos de la venta
            $saleData = [
                'location_origin_id' => $data['location_id'],
                'location_destination_id' => $data['location_id'],
                'movement_date' => $data['movement_date'],
                'document_number' => $data['document_number'] ?? null,
                'comments' => $data['comments'] ?? null,
                'content' => $content,
                'company_id' => $data['company_id'] ?? null,
                'user_id' => $user->id,
            ];

            // Calcular total
            $totalCost = 0;
            foreach ($data['details'] as $detail) {
                $totalCost += $detail['quantity'] * $detail['unit_price'];
            }
            $saleData['total_cost'] = $totalCost;

            // Validar monto recibido para efectivo
            if ($data['payment_method'] === 'efectivo' && isset($data['received_amount'])) {
                if ($data['received_amount'] < $totalCost) {
                    throw new \Exception('El monto recibido debe ser mayor o igual al total de la venta');
                }
            }

            // Crear o actualizar venta
            /** @var Sale $sale */
            $sale = $callback($saleData);

            // Manejar detalles
            if (isset($data['details'])) {
                // Si es actualización, eliminar detalles existentes
                if ($method === 'update') {
                    $sale->details()->delete();
                }

                // Crear nuevos detalles
                foreach ($data['details'] as $detail) {
                    SaleDetail::create([
                        'movement_id' => $sale->id,
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                        'unit_cost' => $detail['unit_price'],
                        'total_cost' => $detail['quantity'] * $detail['unit_price'],
                    ]);
                }
            }

            // Marcar como cerrada y afectar stock automáticamente
            if ($method === 'create') {
                // Usar transitionTo que maneja validación y actualización de stock
                $sale->transitionTo(SaleStatus::CLOSED);
            }

            // Recargar relaciones
            $sale->load($this->getShowRelations());

            DB::commit();
            return $sale;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Avanzar al siguiente estado
     */
    public function advanceStatus(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

            if ($sale->advanceStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Venta avanzada a estado: {$sale->status->label()}",
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede avanzar más el estado',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retroceder al estado anterior
     */
    public function revertStatus(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

            if ($sale->revertStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Venta revertida a estado: {$sale->status->label()}",
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede retroceder más el estado',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancelar venta
     */
    public function cancel(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

            if ($sale->transitionTo(SaleStatus::CANCELLED)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Venta cancelada correctamente',
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede cancelar esta venta',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // Solo se pueden eliminar ventas en borrador
        if ($model->status !== SaleStatus::DRAFT) {
            return [
                'can_delete' => false,
                'message' => 'Solo se pueden eliminar ventas en estado borrador'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
