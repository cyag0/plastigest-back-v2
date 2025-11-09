<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\UsageResource;
use App\Models\Usage;
use App\Models\UsageDetail;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UsageController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = UsageResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Usage::class;

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
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Información adicional del uso
            'reason' => 'nullable|string|max:255',
            'used_by' => 'nullable|string|max:255',

            // Detalles del uso
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

            // Información adicional del uso
            'reason' => 'nullable|string|max:255',
            'used_by' => 'nullable|string|max:255',

            // Detalles del uso
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method): Model
    {
        try {
            DB::beginTransaction();

            // Preparar datos del content
            $content = [
                'reason' => $data['reason'] ?? null,
                'used_by' => $data['used_by'] ?? null,
            ];

            $user = Auth::user();

            // Preparar datos del uso
            $usageData = [
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
            $usageData['total_cost'] = $totalCost;

            // Crear o actualizar uso
            $usage = $callback($usageData);

            // Manejar detalles
            if (isset($data['details'])) {
                // Si es actualización, eliminar detalles existentes
                if ($method === 'update') {
                    $usage->details()->delete();
                }

                // Crear nuevos detalles
                foreach ($data['details'] as $detail) {
                    UsageDetail::create([
                        'movement_id' => $usage->id,
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                        'unit_cost' => $detail['unit_price'],
                        'total_cost' => $detail['quantity'] * $detail['unit_price'],
                    ]);
                }
            }

            // Validar y actualizar stock automáticamente (solo al crear)
            if ($method === 'create') {
                $usage->validateAndUpdateStock();
            }

            // Recargar relaciones
            $usage->load($this->getShowRelations());

            DB::commit();
            return $usage;
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
        // No se pueden eliminar usos ya que afectan el stock inmediatamente
        return [
            'can_delete' => false,
            'message' => 'No se pueden eliminar registros de uso de productos'
        ];
    }
}
