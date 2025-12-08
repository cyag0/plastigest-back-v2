<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\AdjustmentResource;
use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Product;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdjustmentController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = AdjustmentResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Adjustment::class;

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
        // Filtrar por rango de fechas
        if (isset($params['start_date']) && isset($params['end_date'])) {
            $query->betweenDates($params['start_date'], $params['end_date']);
        }

        // Filtrar por ubicación
        if (isset($params['location_id'])) {
            $query->where('location_origin_id', $params['location_id']);
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
            'movement_reason' => 'required|in:adjustment,return,damage,loss,shrinkage',

            // Comentarios
            'content.comments' => 'nullable|string',

            // Detalles del ajuste
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
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
            'movement_reason' => 'sometimes|required|in:adjustment,return,damage,loss,shrinkage',

            // Comentarios
            'content.comments' => 'nullable|string',

            // Detalles del ajuste
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method): Model
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();

            // Determinar si es incremento o decremento basado en movement_reason
            $isIncrement = in_array($data['movement_reason'], ['adjustment']); // Solo 'adjustment' puede ser incremento
            $movementType = $isIncrement ? 'entry' : 'exit';

            // Preparar datos del content
            $content = [
                'comments' => $data['content']['comments'] ?? null,
            ];

            // Preparar datos del ajuste
            $adjustmentData = [
                'location_origin_id' => $data['location_id'],
                'location_destination_id' => $data['location_id'],
                'movement_date' => $data['movement_date'],
                'movement_type' => $movementType,
                'movement_reason' => $data['movement_reason'],
                'content' => $content,
                'company_id' => $data['company_id'] ?? null,
                'user_id' => $user->id,
                'status' => 'closed', // Siempre cerrado
            ];

            // Crear o actualizar ajuste
            $adjustment = $callback($adjustmentData);

            // Manejar detalles
            if (isset($data['details'])) {
                // Si es actualización, eliminar detalles existentes
                if ($method === 'update') {
                    $adjustment->details()->delete();
                }

                // Crear nuevos detalles con conversión de unidades
                foreach ($data['details'] as $detail) {
                    // Obtener información del producto y unidad
                    $product = \App\Models\Product::findOrFail($detail['product_id']);
                    $unit = \App\Models\Unit::findOrFail($detail['unit_id']);

                    // Convertir cantidad a unidad base
                    $quantityInBaseUnit = $detail['quantity'];
                    if ($unit->base_unit_id !== null) {
                        // Es una unidad derivada, convertir a base
                        $quantityInBaseUnit = $detail['quantity'] * $unit->factor_to_base;
                    }

                    // Obtener stock actual
                    $productLocation = DB::table('product_location')
                        ->where('product_id', $detail['product_id'])
                        ->where('location_id', $data['location_id'])
                        ->first();

                    $previousStock = $productLocation->current_stock ?? 0;

                    // Calcular nuevo stock
                    $newStock = $isIncrement
                        ? $previousStock + $quantityInBaseUnit
                        : $previousStock - $quantityInBaseUnit;

                    // Validar stock suficiente para decrementos
                    if (!$isIncrement && $newStock < 0) {
                        throw new \Exception(
                            "Stock insuficiente para el producto '{$product->name}'. " .
                                "Stock disponible: {$previousStock}, " .
                                "Cantidad a restar: {$quantityInBaseUnit}"
                        );
                    }

                    // Crear detalle
                    AdjustmentDetail::create([
                        'movement_id' => $adjustment->id,
                        'product_id' => $detail['product_id'],
                        'unit_id' => $detail['unit_id'],
                        'quantity' => $quantityInBaseUnit, // Guardar en unidad base
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'unit_cost' => 0, // No manejamos costos en ajustes
                        'total_cost' => 0,
                    ]);

                    // Actualizar stock
                    if ($isIncrement) {
                        DB::table('product_location')
                            ->where('product_id', $detail['product_id'])
                            ->where('location_id', $data['location_id'])
                            ->increment('current_stock', $quantityInBaseUnit);
                    } else {
                        DB::table('product_location')
                            ->where('product_id', $detail['product_id'])
                            ->where('location_id', $data['location_id'])
                            ->decrement('current_stock', $quantityInBaseUnit);
                    }
                }
            }

            // Recargar relaciones
            $adjustment->load($this->getShowRelations());

            // Verificar stock bajo y notificar
            $this->checkLowStockAndNotify($adjustment);

            DB::commit();
            return $adjustment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // No se pueden eliminar ajustes ya que afectan el stock inmediatamente
        return [
            'can_delete' => false,
            'message' => 'No se pueden eliminar registros de ajuste de inventario'
        ];
    }

    /**
     * Verificar stock bajo y enviar notificaciones
     * TODO: Esta mal esto
     */
    protected function checkLowStockAndNotify(Adjustment $adjustment): void
    {
        try {
            $locationId = $adjustment->location_origin_id;
            $companyId = $adjustment->company_id;

            foreach ($adjustment->details as $detail) {
                $product = Product::find($detail->product_id);

                if (!$product || !$product->minimum_stock) {
                    continue;
                }

                // Obtener stock actual del producto en la ubicación
                $currentStock = DB::table('product_location')
                    ->where('product_id', $product->id)
                    ->where('location_id', $locationId)
                    ->value('current_stock') ?? 0;

                // Si el stock actual es menor al mínimo, enviar notificación
                if ($currentStock < $product->minimum_stock) {
                    NotificationService::notifyLowStock(
                        $companyId,
                        $locationId,
                        $product,
                        $currentStock,
                        $product->minimum_stock
                    );
                }
            }
        } catch (\Exception $e) {
            // Log error pero no fallar el ajuste
            \Log::error('Error al verificar stock bajo: ' . $e->getMessage(), [
                'adjustment_id' => $adjustment->id,
                'exception' => $e,
            ]);
        }
    }
}
