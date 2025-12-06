<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\InventoryCount;
use App\Models\InventoryCountDetail;
use Illuminate\Database\Eloquent\Model;

class InventoryCountResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param InventoryCount $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);


        $item = [
            'id' => $resource->id,
            'name' => $resource->name,
            'count_date' => $resource->count_date?->toDateString(),
            'status' => $resource->status,
            'notes' => $resource->notes,
            'content' => $resource->content,
        ];

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

        // Relación con usuario
        if ($resource->relationLoaded('user')) {
            if (!$editing) {
                $item['user'] = [
                    'id' => $resource->user?->id,
                    'name' => $resource->user?->name,
                ];
            } else {
                $item['user'] = $resource->user;
            }
        }

        if ($editing) {
            // Relación con detalles
            if ($resource->relationLoaded('details')) {
                // details by product_id
                $details = [];

                foreach ($resource->details as $detail) {
                    $details['product_' . $detail->product_id] = [
                        'id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'location_id' => $detail->location_id,
                        'system_quantity' => $detail->system_quantity,
                        'counted_quantity' => $detail->counted_quantity,
                        'difference' => $detail->difference,
                        'notes' => $detail->notes,
                    ];

                    // Incluir datos del producto si está cargado
                    if ($detail->relationLoaded('product')) {
                        $details['product_' . $detail->product_id]['product'] = [
                            'id' => $detail->product->id,
                            'name' => $detail->product->name,
                            'code' => $detail->product->code,
                            'image' => $detail->product->main_image?->uri ?? null,
                            'unit' => $detail->product->unit ? [
                                'name' => $detail->product->unit->name,
                                'abbreviation' => $detail->product->unit->abbreviation,
                            ] : null,
                        ];
                    }
                }

                $item['details'] = $details;
                $item['details_count'] = $resource->details->count();
            }

            // Campos adicionales según el contexto

            $item['company_id'] = $resource->company_id;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();

            return $item;
        }

        if ($resource->relationLoaded('details')) {
            $item['details_count'] = $resource->details->count();

            // Calcular progreso para gráfica
            $totalProducts = $resource->content ? $resource->content["products_count"] ?? 0 : 0;
            $countedProducts = $resource->details->count();
            $pendingProducts = max(0, $totalProducts - $countedProducts);

            if ($countedProducts && $item["status"] === "planning") {
                $item["status"] = "counting";
            }

            $item['progress'] = [
                'total' => $totalProducts,
                'counted' => $countedProducts,
                'pending' => $pendingProducts,
                'percentage' => $totalProducts > 0 ? round(($countedProducts / $totalProducts) * 100, 2) : 0,
            ];
        }

        return $item;
    }
}
