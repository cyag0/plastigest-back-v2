<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->content ?? [];

        return [
            'id' => $this->id,
            'movement_id' => $this->movement_id,
            'product_id' => $this->product_id,

            // Product info
            'product' => $this->whenLoaded('product', function () {
                $productData = [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'code' => $this->product->code,
                    'sku' => $this->product->sku ?? null,
                ];

                // Load main image if relationship is loaded
                if ($this->product->relationLoaded('mainImage') && $this->product->mainImage) {
                    $mainImagePath = $this->product->mainImage->image_path ?? null;
                    if ($mainImagePath) {
                        $productData['main_image'] = AppUploadUtil::formatFile(
                            Files::PRODUCT_IMAGES_PATH,
                            $mainImagePath
                        );
                    }
                }

                // Load unit if relationship is loaded
                if ($this->product->relationLoaded('unit') && $this->product->unit) {
                    $productData['unit'] = [
                        'id' => $this->product->unit->id,
                        'name' => $this->product->unit->name,
                        'abbreviation' => $this->product->unit->abbreviation,
                    ];
                }

                return $productData;
            }),

            // Unit info
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', function () {
                return [
                    'id' => $this->unit->id,
                    'name' => $this->unit->name,
                    'abbreviation' => $this->unit->abbreviation,
                ];
            }),

            // Quantities - for transfers we use content to store the workflow quantities
            'quantity_requested' => $content['quantity_requested'] ?? $this->quantity,
            'quantity_shipped' => $content['quantity_shipped'] ?? null,
            'quantity_received' => $content['quantity_received'] ?? null,
            'quantity' => (float) $this->quantity, // The actual quantity moved

            // Costs
            'unit_cost' => $this->unit_cost ? (float) $this->unit_cost : 0,
            'total_cost' => $this->total_cost ? (float) $this->total_cost : 0,

            // Stock tracking
            'previous_stock' => (float) $this->previous_stock,
            'new_stock' => (float) $this->new_stock,

            // Batch/Lot
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date,

            // Differences tracking (for transfers)
            'has_difference' => $content['has_difference'] ?? false,
            'difference' => $content['difference'] ?? 0,

            // Notes
            'notes' => $content['notes'] ?? null,
            'damage_report' => $content['damage_report'] ?? null,

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
