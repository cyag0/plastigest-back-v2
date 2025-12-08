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

        $locationId =  isset($data["location_id"]) ? $data["location_id"] : (current_location_id() ?? null);

        if ($this->relationLoaded('locations') && $locationId) {
            $currentLocation = $this->locations->where('id', $locationId)->first();
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
            'current_stock' => (int) $currentStock ?? 0,
            'minimum_stock' => (int) $minimumStock,
            'maximum_stock' => (int) $maximumStock,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->relationLoaded('category')) {
            if (!$editing) {
                $item['category_name'] = $this->category?->name;
            } else {
                $item['category'] = [
                    'id' => $this->category?->id,
                    'name' => $this->category?->name,
                ];
            }
        }

        if ($resource->relationLoaded('unit')) {
            $item['unit'] = [
                'id' => $this->unit?->id,
                'name' => $this->unit?->name,
                'abbreviation' => $this->unit?->abbreviation,
            ];
        }

        // Agregar unidades disponibles (base + derivadas)
        if (isset($this->available_units)) {
            $item['available_units'] = $this->available_units->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'abbreviation' => $unit->abbreviation,
                    'base_unit_id' => $unit->base_unit_id,
                    'factor_to_base' => $unit->factor_to_base,
                ];
            });
        }

        if ($editing) {


            if ($this->relationLoaded('productIngredients')) {
                $item['ingredients'] = $this->productIngredients->map(function ($productIngredient) {
                    return [
                        'id' => $productIngredient->id,
                        'ingredient_id' => $productIngredient->ingredient_id,
                        'quantity' => (int) $productIngredient->quantity,
                        'notes' => $productIngredient->notes,
                        'ingredient_name' => $productIngredient->ingredient?->name,
                        'ingredient_code' => $productIngredient->ingredient?->code,
                    ];
                });
            }

            return $item;
        }

        if ($this->relationLoaded('images')) {
            $item["product_images"] = $this->images->map(function ($image) {
                return AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $image->image_path);
            });
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
