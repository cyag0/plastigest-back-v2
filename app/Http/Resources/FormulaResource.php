<?php

namespace App\Http\Resources;

use App\Models\Operations\Formula;
use Illuminate\Database\Eloquent\Model;

class FormulaResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        /** @var Formula $resource */
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'company_id' => $resource->company_id,
            'product_id' => $resource->product_id,
            'product_name' => $resource->relationLoaded('product') ? ($resource->product?->name) : null,
            'product_code' => $resource->relationLoaded('product') ? ($resource->product?->code) : null,
            'name' => $resource->name,
            'description' => $resource->description,
            'version' => $resource->version,
            'is_active' => (bool) $resource->is_active,
            'notes' => $resource->notes,
            'created_at' => optional($resource->created_at)->toISOString(),
            'updated_at' => optional($resource->updated_at)->toISOString(),
        ];

        if ($resource->relationLoaded('items')) {
            $item['items'] = $resource->items->map(function ($i) {
                return [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'product_name' => $i->relationLoaded('product') ? ($i->product?->name) : null,
                    'product_code' => $i->relationLoaded('product') ? ($i->product?->code) : null,
                    'unit_id' => $i->unit_id,
                    'unit_name' => $i->relationLoaded('unit') ? ($i->unit?->name) : null,
                    'expected_quantity' => (float) $i->expected_quantity,
                    'expected_output_quantity' => $i->expected_output_quantity !== null ? (float) $i->expected_output_quantity : null,
                    'sort_order' => $i->sort_order,
                    'notes' => $i->notes,
                ];
            })->values();
        }

        return $item;
    }
}
