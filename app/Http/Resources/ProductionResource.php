<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Http\Resources\Resources;
use App\Models\Production;
use App\Utils\AppUploadUtil;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ProductionResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Production $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'production_number' => (int) $resource->production_number,
            'production_date' => $resource->production_date ? Carbon::parse($resource->production_date)->format('Y-m-d') : null,
            'movement_date' => $resource->movement_date ? Carbon::parse($resource->movement_date)->format('Y-m-d') : null,
            'location_id' => $resource->location_destination_id,
            'location_name' => $resource->locationDestination?->name ?? null,
            'company_id' => $resource->company_id,
            'status' => $resource->status ?? 'completed',
            'comments' => $resource->comments ?? null,
        ];

        if ($resource->relationLoaded('details')) {
            $detail = $resource->details->first();

            if ($detail) {
                $image = $detail->product->mainImage()->first();
                $item['product_image'] = $image ? AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $image->image_path) : null;
                $item["product_image"] = $item['product_image']["uri"] ?? null;



                $item['product_id'] = $detail->product_id;
                $item['product_name'] = $detail->product?->name;
                $item['product_code'] = $detail->product?->code;
                $item['quantity'] = (int) $detail->quantity;

                if (!$editing) {
                    // Para index: información resumida
                    $item['product_summary'] = $detail->product?->name . ' (' . $detail->quantity . ' unidades)';
                } else {
                    // Para show/edit: detalles completos
                    $item['product_details'] = [
                        'id' => $detail->product_id,
                        'name' => $detail->product?->name,
                        'code' => $detail->product?->code,
                        'image_url' => $detail->product?->image_url,
                        'quantity' => (int) $detail->quantity,
                    ];
                }
            }
        }

        return $item;
    }
}
