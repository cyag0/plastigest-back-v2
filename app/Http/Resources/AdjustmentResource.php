<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Adjustment;
use Illuminate\Database\Eloquent\Model;

class AdjustmentResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Adjustment $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $types = [
            'entry' => 'Entrada',
            'exit' => 'Salida',
        ];

        $reasons = [
            'adjustment' => 'Ajuste de Inventario',
            'return' => 'Retorno',
            'damage' => 'Daño',
            'loss' => 'Pérdida',
            'shrinkage' => 'Merma',
        ];

        $item = [
            'id' => $resource->id,
            'adjustment_number' => $resource->adjustment_number,
            'movement_date' => $resource->adjustment_date,
            'movement_reason' => $resource->movement_reason ? $reasons[$resource->movement_reason] : "N/A",
            'status' => $resource->status,
            'total_cost' => $resource->total_cost,
            'adjustment_type' => $resource->adjustment_type,
            'reason' => $resource->reason,
            'adjusted_by' => $resource->adjusted_by,
            'content' => $resource->content,
            'type' => $types[$resource->movement_type] ?? 'Desconocido',
            'movement_type' => $resource->movement_type,
        ];

        // Detalles de los productos usados
        if ($resource->relationLoaded('details')) {
            $item['details'] = $resource->details->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'quantity' => $detail->quantity,
                    'unit_id' => $detail->unit_id,
                    'unit_cost' => $detail->unit_cost,
                    'total_cost' => $detail->total_cost,
                    'previous_stock' => $detail->previous_stock,
                    'new_stock' => $detail->new_stock,
                    'product_name' => $detail->product?->name,
                    'product_code' => $detail->product?->code,
                    'product_image' => $detail->product?->mainImage,
                ];
            });
        }

        // Ubicación
        if ($resource->relationLoaded('location')) {
            $item['location'] = [
                'id' => $resource->location?->id,
                'name' => $resource->location?->name,
            ];
        }

        // Campos adicionales según el contexto
        if ($editing) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
