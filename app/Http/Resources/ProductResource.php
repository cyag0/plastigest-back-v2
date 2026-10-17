<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Models\ProductImage;
use App\Utils\AppUploadUtil;
use App\Models\Product;
use App\Http\Resources\Resources;
use App\Support\CurrentLocation;

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
        $reservedStock = null;
        $minimumStock = null;
        $maximumStock = null;

        $location = CurrentLocation::get();
        $locationId = $location ? $location->id : (isset($data["location_id"]) ? $data["location_id"] : (current_location_id() ?? null));

        if ($this->relationLoaded('locations') && $locationId) {
            $currentLocation = $this->locations->where('id', $locationId)->first();
            if ($currentLocation) {
                $currentLocationActive = $currentLocation->pivot->active ?? false;
                $currentStock = $currentLocation->pivot->current_stock ?? 0;
                $reservedStock = $currentLocation->pivot->reserved_stock ?? 0;
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
            'inventory_type_id' => $this->inventory_type_id,
            'is_active' => $currentLocationActive,
            'for_sale' => $this->for_sale,
            // Receta de empaque: de qué granel sale y cuánto lleva cada unidad.
            'requires_packaging' => (bool) $this->requires_packaging,
            'packaging_source_product_id' => $this->packaging_source_product_id,
            'packaging_source_quantity' => $this->packaging_source_quantity !== null
                ? (float) $this->packaging_source_quantity
                : null,
            'packaging_source_unit_id' => $this->packaging_source_unit_id,
            'current_stock' => (float) $currentStock ?? 0,
            'reserved_stock' => (float) $reservedStock ?? 0,
            'available_stock' => ((float) $currentStock ?? 0) - ((float) $reservedStock ?? 0),
            // Decimales (la columna guarda 3): con (int) un mínimo de 2.5 kg se
            // mostraba como 2 y al volver a guardar el producto quedaba en 2.
            'minimum_stock' => (float) $minimumStock,
            'maximum_stock' => (float) $maximumStock,
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
                'unit_type' => $this->unit?->unit_type,
            ];
        }

        if ($this->relationLoaded('inventoryType') && $this->inventoryType) {
            $item['inventory_type'] = [
                'id' => $this->inventoryType->id,
                'code' => $this->inventoryType->code,
                'slug' => $this->inventoryType->slug,
                'name' => $this->inventoryType->name,
                'is_producible' => (bool) $this->inventoryType->is_producible,
                'is_consumable' => (bool) $this->inventoryType->is_consumable,
                'is_sellable' => (bool) $this->inventoryType->is_sellable,
                'is_purchasable' => (bool) $this->inventoryType->is_purchasable,
            ];
        }

        // Va antes del corte de `$editing`: el formulario necesita la receta
        // para editarla y la vista de detalle para mostrarla.
        if ($this->relationLoaded('packagingMaterials')) {
            $item['packaging_materials'] = $this->packagingMaterials->map(function ($material) {
                return [
                    'id' => $material->id,
                    'material_product_id' => $material->material_product_id,
                    'material_name' => $material->material?->name,
                    'material_code' => $material->material?->code,
                    'unit_id' => $material->unit_id,
                    'unit_name' => $material->unit?->name,
                    'unit_abbreviation' => $material->unit?->abbreviation,
                    'quantity' => (float) $material->quantity,
                    'sort_order' => $material->sort_order,
                    'notes' => $material->notes,
                ];
            })->values();
        }

        if ($this->relationLoaded('packagingSourceProduct') && $this->packagingSourceProduct) {
            $item['packaging_source_product'] = [
                'id' => $this->packagingSourceProduct->id,
                'name' => $this->packagingSourceProduct->name,
                'code' => $this->packagingSourceProduct->code,
                'unit_id' => $this->packagingSourceProduct->unit_id,
            ];
        }

        // Con estos el formulario muestra el proveedor y la unidad del granel
        // ya elegidos (selectedLabelField) y el detalle los nombra. Solo si la
        // relación viene cargada: así no cuesta una consulta por producto.
        if ($this->relationLoaded('supplier')) {
            $item['supplier_name'] = $this->supplier?->name;
        }

        if ($this->relationLoaded('packagingSourceUnit')) {
            $item['packaging_source_unit'] = $this->packagingSourceUnit ? [
                'id' => $this->packagingSourceUnit->id,
                'name' => $this->packagingSourceUnit->name,
            ] : null;
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

        if ($this->relationLoaded('activePackages')) {
            $item['active_packages'] = $this->activePackages->map(function ($package) {
                return [
                    'id' => $package->id,
                    'package_name' => $package->package_name,
                    'barcode' => $package->barcode,
                    'quantity_per_package' => $package->quantity_per_package,
                    'purchase_price' => $package->purchase_price,
                    'sale_price' => $package->sale_price,
                    'is_active' => $package->is_active,
                    'is_default' => $package->is_default,
                    'sort_order' => $package->sort_order,
                ];
            });
        }

        return $item;
    }
}
