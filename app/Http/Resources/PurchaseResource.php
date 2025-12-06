<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PurchaseResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Purchase $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'purchase_number' => $resource->purchase_number,
            'purchase_date' => $resource->purchase_date ? Carbon::parse($resource->purchase_date)->format('Y-m-d') : null,
            'movement_date' => $resource->movement_date ? Carbon::parse($resource->movement_date)->format('Y-m-d') : null,
            'location_origin_id' => $resource->location_origin_id,
            'document_number' => $resource->document_number,
            'status' => $resource->status,
            'total_amount' => $resource->total_amount ?? $resource->total_cost,
            'company_id' => $resource->company_id,
            'location_id' => $resource->location_destination_id ?? $resource->location_id,
            'supplier_id' => $resource->supplier_id,
            'comments' => $resource->comments ?? $resource->notes,
            'created_at' => $resource->created_at,
        ];

        if ($resource->relationLoaded('details')) {
            if (!$editing) {
                // Para index: solo contar detalles
                $item['details_count'] = $resource->details->count();
                $item['products_summary'] = $resource->details->take(3)->pluck('product.name')->join(', ');
            } else {
                // Para show/edit: detalles completos mapeados al formato del formulario
                $item['purchase_items'] = $resource->details->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'name' => $detail->product?->name,
                        'code' => $detail->product?->code,
                        'product_id' => $detail->product_id,
                        'quantity' => $detail->quantity,
                        'unit_price' => $detail->unit_cost,
                        'total_price' => $detail->total_cost,
                    ];
                });

                // También mantener el formato anterior para compatibilidad
                $item['details'] = $resource->details->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product?->name,
                        'quantity' => $detail->quantity,
                        'unit_cost' => $detail->unit_cost,
                        'subtotal' => $detail->total_cost,
                        'notes' => $detail->notes,
                    ];
                });
            }
        }

        if ($resource->relationLoaded('supplier')) {
            $item['supplier_name'] = $resource->supplier->name ?? null;
        }

        if ($resource->relationLoaded('location')) {
            $item['location_name'] = $resource->location()->where('id', $item['location_id'])->first()->name ?? null;
        }

        return $item;
    }
}
