<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Purchase;
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
            'purchase_date' => $resource->purchase_date,
            'document_number' => $resource->document_number,
            'status' => $resource->status,
            'total_amount' => $resource->total_amount,
        ];

        // Información del proveedor
        $supplierInfo = $resource->supplier_info;
        if ($supplierInfo) {
            $item['supplier_name'] = $supplierInfo['name'] ?? null;
            if (!$editing) {
                $item['supplier_phone'] = $supplierInfo['phone'] ?? null;
            } else {
                $item['supplier_phone'] = $supplierInfo['phone'] ?? null;
                $item['supplier_email'] = $supplierInfo['email'] ?? null;
                $item['supplier_address'] = $supplierInfo['address'] ?? null;
            }
        }

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['location_origin_id'] = $resource->location_origin_id;
            $item['comments'] = $resource->comments;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Manejo de relaciones
        if ($resource->relationLoaded('location')) {
            if (!$editing) {
                // Para index: datos simples
                $item['location_name'] = $resource->location?->name;
            } else {
                // Para show/edit: datos completos
                $item['location'] = $resource->location;
            }
        }

        if ($resource->relationLoaded('user')) {
            $item['user_name'] = $resource->user?->name;
            if ($editing) {
                $item['user'] = $resource->user;
            }
        }

        if ($resource->relationLoaded('details')) {
            if (!$editing) {
                // Para index: solo contar detalles
                $item['details_count'] = $resource->details->count();
                $item['products_summary'] = $resource->details->take(3)->pluck('product.name')->join(', ');
            } else {
                // Para show/edit: detalles completos
                $item['details'] = $resource->details->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product?->name,
                        'quantity' => $detail->purchase_quantity,
                        'unit_cost' => $detail->unit_price,
                        'subtotal' => $detail->subtotal,
                        'notes' => $detail->notes,
                    ];
                });
            }
        }

        return $item;
    }
}
