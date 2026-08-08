<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\InventoryType;
use Illuminate\Database\Eloquent\Model;

class InventoryTypeResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param InventoryType $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $item = [
            'id' => $resource->id,
            'company_id' => $resource->company_id,
            'code' => $resource->code,
            'slug' => $resource->slug,
            'name' => $resource->name,
            'description' => $resource->description,
            'icon' => $resource->icon,
            'color' => $resource->color,
            'is_purchasable' => (bool) $resource->is_purchasable,
            'is_sellable' => (bool) $resource->is_sellable,
            'is_producible' => (bool) $resource->is_producible,
            'is_consumable' => (bool) $resource->is_consumable,
            'is_stockable' => (bool) $resource->is_stockable,
            'is_system' => (bool) $resource->is_system,
            'is_active' => (bool) $resource->is_active,
            'sort_order' => (int) $resource->sort_order,
        ];

        // Presente sólo cuando se pide with_counts=1 (menú de tipos de inventario).
        if (isset($resource->products_count)) {
            $item['products_count'] = (int) $resource->products_count;
        }

        if ($this->getContext('editing', false)) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
