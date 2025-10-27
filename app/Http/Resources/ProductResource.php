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

        // Get current location data from pivot table if available
        $currentLocationActive = $this->is_active; // fallback to product's is_active
        $currentStock = null;
        $minimumStock = null;
        $maximumStock = null;


        if ($this->relationLoaded('locations') && isset($data["location_id"])) {
            $currentLocation = $this->locations->where('id', $data["location_id"])->first();
            if ($currentLocation) {
                $currentLocationActive = $currentLocation->pivot->active ?? $this->is_active;
                $currentStock = $currentLocation->pivot->current_stock ?? 0;
                $minimumStock = $currentLocation->pivot->minimum_stock ?? 0;
                $maximumStock = $currentLocation->pivot->maximum_stock ?? null;
            }
        }

        $item = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'purchase_price' => $this->purchase_price,
            'sale_price' => $this->sale_price,
            'company_id' => $this->company_id,
            'category_id' => $this->category_id,
            'unit_id' => $this->unit_id,
            'supplier_id' => $this->supplier_id,
            'product_type' => $this->product_type,
            'is_active' => $currentLocationActive,
            'for_sale' => $this->for_sale,
            'current_stock' => $currentStock,
            'minimum_stock' => $minimumStock,
            'maximum_stock' => $maximumStock,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($editing) {
            if ($this->relationLoaded('images')) {
                $item["product_images"] = $this->images->map(function ($image) {
                    return AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $image->image_path);
                });
            }

            if ($this->relationLoaded('productIngredients')) {
                $item['ingredients'] = $this->productIngredients->map(function ($productIngredient) {
                    return [
                        'id' => $productIngredient->id,
                        'ingredient_id' => $productIngredient->ingredient_id,
                        'quantity' => $productIngredient->quantity,
                        'notes' => $productIngredient->notes,
                    ];
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
