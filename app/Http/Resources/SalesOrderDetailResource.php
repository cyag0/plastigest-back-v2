<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;

class SalesOrderDetailResource extends Resources
{
    protected function formatter(Model $resource, array $data, array $context): array
    {
        $item = [
            'id' => $resource->id,
            'product_id' => $resource->product_id,
            'package_id' => $resource->package_id,
            'unit_id' => $resource->unit_id,
            'requested_quantity' => $resource->requested_quantity,
            'prepared_quantity' => $resource->prepared_quantity,
            'delivered_quantity' => $resource->delivered_quantity,
            'reserved_quantity_base' => $resource->reserved_quantity_base,
            'delivered_quantity_base' => $resource->delivered_quantity_base,
            'unit_price' => $resource->unit_price,
            'line_subtotal' => $resource->line_subtotal,
            'line_total' => $resource->line_total,
            'content' => $resource->content,
        ];

        if ($resource->relationLoaded('product') && $resource->product) {
            $item['product'] = [
                'id' => $resource->product->id,
                'name' => $resource->product->name,
                'code' => $resource->product->code,
            ];
        }

        if ($resource->relationLoaded('package') && $resource->package) {
            $item['package'] = [
                'id' => $resource->package->id,
                'package_name' => $resource->package->package_name,
                'quantity_per_package' => $resource->package->quantity_per_package,
            ];
        }

        if ($resource->relationLoaded('unit') && $resource->unit) {
            $item['unit'] = [
                'id' => $resource->unit->id,
                'name' => $resource->unit->name,
                'abbreviation' => $resource->unit->abbreviation,
            ];
        }

        return $item;
    }
}