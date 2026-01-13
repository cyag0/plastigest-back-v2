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
                // Para show/edit: cargar detalles en formato de carrito
                $item['cart_items'] = $resource->details->map(function ($detail) use ($resource) {
                    $product = $detail->product;

                    // Obtener stock de la ubicación actual
                    $currentStock = null;
                    $locationId = $resource->location_origin_id;
                    if ($product->relationLoaded('locations') && $locationId) {
                        $location = $product->locations->where('id', $locationId)->first();
                        if ($location) {
                            $currentStock = $location->pivot->current_stock ?? 0;
                        }
                    }

                    // Obtener imagen principal
                    $mainImage = null;
                    if ($product->relationLoaded('mainImage') && $product->mainImage) {
                        $mainImage = \App\Utils\AppUploadUtil::formatFile(
                            \App\Constants\Files::PRODUCT_IMAGES_PATH,
                            $product->mainImage->image_path
                        );
                    }

                    return [
                        'id' => $product->id,
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'code' => $product->code,
                        'price' => (float) $detail->unit_cost,
                        'unit_price' => (float) $detail->unit_cost,
                        'quantity' => (float) $detail->quantity,
                        'total' => (float) $detail->total_cost,
                        'total_price' => (float) $detail->total_cost,
                        'current_stock' => $currentStock,
                        'product_type' => $product->product_type,
                        'main_image' => $mainImage,
                        'unit_id' => $detail->unit_id,
                        'unit_name' => $detail->unit?->name,
                        'unit_abbreviation' => $detail->unit?->abbreviation,
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
