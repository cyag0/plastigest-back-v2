<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\InventoryCountDetail;
use Illuminate\Database\Eloquent\Model;

class InventoryCountDetailResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param InventoryCountDetail $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'inventory_count_id' => $resource->inventory_count_id,
            'product_id' => $resource->product_id,
            'location_id' => $resource->location_id,
            'system_quantity' => $resource->system_quantity,
            'counted_quantity' => $resource->counted_quantity,
            'difference' => $resource->difference,
            'notes' => $resource->notes,
        ];

        // Relación con producto
        if ($resource->relationLoaded('product')) {
            if (!$editing) {
                $item['product'] = [
                    'id' => $resource->product?->id,
                    'name' => $resource->product?->name,
                    'code' => $resource->product?->code,
                ];
            } else {
                $item['product'] = $resource->product;
            }
        }

        // Relación con ubicación
        if ($resource->relationLoaded('location')) {
            if (!$editing) {
                $item['location'] = [
                    'id' => $resource->location?->id,
                    'name' => $resource->location?->name,
                ];
            } else {
                $item['location'] = $resource->location;
            }
        }

        // Campos adicionales según el contexto
        if ($editing) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
