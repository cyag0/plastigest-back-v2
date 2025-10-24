<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Models\ProductImage;
use App\Utils\AppUploadUtil;
use App\Models\Product;
use App\Http\Resources\Resources;

class ProductResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param \Illuminate\Database\Eloquent\Model $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    protected function formatter(\Illuminate\Database\Eloquent\Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'purchase_price' => $this->purchase_price,
            'sale_price' => $this->sale_price,
            'company_id' => $this->company_id ? [$this->company_id . ""] : null,
            'category_id' => $this->category_id ? [$this->category_id . ""] : null,
            'unit_id' => $this->unit_id ? [$this->unit_id . ""] : null,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relaciones
            'company_name' => $this->whenLoaded('company', function () {
                return $this->company->name;
            }),
            'company' => $this->whenLoaded('company'),

            'category_name' => $this->whenLoaded('category', function () {
                return $this->category ? $this->category->name : null;
            }),
            'category' => $this->whenLoaded('category'),

            'unit_name' => $this->whenLoaded('unit', function () {
                return $this->unit ? $this->unit->name : null;
            }),
            'unit' => $this->whenLoaded('unit')
        ];

        if ($editing) {
            if ($this->relationLoaded('images')) {
                $item["product_images"] = $this->images->map(function ($image) {
                    return AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $image->image_path);
                });
            }
            return $item;
        }

        if ($this->relationLoaded('mainImage')) {
            $mainImage = $this->mainImage->image_path ?? null;

            if ($mainImage) {
                $item["main_image"] = AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $mainImage);
            }
        }

        return $item;
    }
}
