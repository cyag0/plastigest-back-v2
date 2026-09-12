<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Models\Operations\Formula;
use App\Models\Operations\FormulaOutput;
use App\Utils\AppUploadUtil;
use Illuminate\Database\Eloquent\Model;

class FormulaResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        /** @var Formula $resource */
        $item = [
            'id' => $resource->id,
            'company_id' => $resource->company_id,
            'name' => $resource->name,
            'description' => $resource->description,
            'version' => $resource->version,
            'is_active' => (bool) $resource->is_active,
            'notes' => $resource->notes,
            'created_at' => optional($resource->created_at)->toISOString(),
            'updated_at' => optional($resource->updated_at)->toISOString(),
        ];

        if ($resource->relationLoaded('items')) {
            $item['items'] = $resource->items->map(fn($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->relationLoaded('product') ? ($i->product?->name) : null,
                'product_code' => $i->relationLoaded('product') ? ($i->product?->code) : null,
                'unit_id' => $i->unit_id,
                'unit_name' => $i->relationLoaded('unit') ? ($i->unit?->name) : null,
                'unit_abbreviation' => $i->relationLoaded('unit') ? ($i->unit?->abbreviation) : null,
                'unit_type' => $i->relationLoaded('unit') ? ($i->unit?->unit_type) : null,
                'expected_quantity' => (float) $i->expected_quantity,
                'sort_order' => $i->sort_order,
                'notes' => $i->notes,
            ])->values();
        }

        if ($resource->relationLoaded('outputs')) {
            $item['outputs'] = $resource->outputs
                ->map(fn(FormulaOutput $o) => $this->formatOutput($o))
                ->values();

            $primary = $resource->outputs->firstWhere('is_primary', true)
                ?? $resource->outputs->first();

            // Compatibilidad de lectura: las versiones de la app anteriores a la
            // fórmula multi-salida leen estos dos campos. Apuntan a la salida
            // principal; las demás sólo se ven en `outputs`.
            $item['product_id'] = $primary?->product_id;
            $item['product_name'] = $primary?->product?->name;
            $item['product_code'] = $primary?->product?->code;
            $item['expected_output_quantity'] = $primary ? (float) $primary->expected_quantity : null;

            if ($primary?->product) {
                $item['product'] = $this->formatProduct($primary->product);
            }
        }

        return $item;
    }

    private function formatOutput(FormulaOutput $output): array
    {
        return [
            'id' => $output->id,
            'product_id' => $output->product_id,
            'product_name' => $output->relationLoaded('product') ? ($output->product?->name) : null,
            'product_code' => $output->relationLoaded('product') ? ($output->product?->code) : null,
            'unit_id' => $output->unit_id,
            'unit_name' => $output->relationLoaded('unit') ? ($output->unit?->name) : null,
            'unit_abbreviation' => $output->relationLoaded('unit') ? ($output->unit?->abbreviation) : null,
            'unit_type' => $output->relationLoaded('unit') ? ($output->unit?->unit_type) : null,
            'expected_quantity' => (float) $output->expected_quantity,
            'is_primary' => (bool) $output->is_primary,
            'sort_order' => $output->sort_order,
            'notes' => $output->notes,
            'product' => $output->relationLoaded('product') && $output->product
                ? $this->formatProduct($output->product)
                : null,
        ];
    }

    /**
     * Producto con su imagen principal, para los selectores de la app.
     */
    private function formatProduct($product): array
    {
        $mainImage = null;
        if ($product->relationLoaded('mainImage') && $product->mainImage) {
            $mainImage = AppUploadUtil::formatFile(
                Files::PRODUCT_IMAGES_PATH,
                $product->mainImage->image_path,
            );
        }

        $unit = $product->relationLoaded('unit') ? $product->unit : null;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'product_type' => $product->product_type,
            'inventory_type_id' => $product->inventory_type_id,
            'unit_id' => $product->unit_id,
            'requires_packaging' => (bool) $product->requires_packaging,
            'unit' => $unit ? [
                'id' => $unit->id,
                'name' => $unit->name,
                'abbreviation' => $unit->abbreviation,
                'unit_type' => $unit->unit_type,
            ] : null,
            'main_image' => $mainImage,
        ];
    }
}
