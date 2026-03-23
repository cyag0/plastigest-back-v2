<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryTransferDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Obtener el stock disponible en la ubicación de origen
        $transfer = $this->transfer;
        $availableStock = 0;

        if ($transfer && $transfer->fromLocation) {
            $productLocationStock = DB::table('product_location')
                ->where('product_id', $this->product_id)
                ->where('location_id', $transfer->from_location_id)
                ->first();
            // Calculate available_stock as current_stock - reserved_stock
            if ($productLocationStock) {
                $availableStock = (float) $productLocationStock->current_stock - (float) $productLocationStock->reserved_stock;
            }
        }

        // Formatear imagen del producto
        $mainImage = null;

        Log::info('Product info in InventoryTransferDetailResource', [
            'product_id' => $this->product_id,
            'has_product' => (bool) $this->product,
            'has_main_image' => (bool) ($this->product && $this->product->mainImage),
        ]);

        if ($this->product && $this->product->mainImage) {
            $mainImage = \App\Utils\AppUploadUtil::formatFile(
                \App\Constants\Files::PRODUCT_IMAGES_PATH,
                $this->product->mainImage->image_path
            );
        }

        return [
            'id' => $this->id,
            'transfer_id' => $this->transfer_id,
            'product_id' => $this->product_id,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'code' => $this->product->code,
                'main_image' => $mainImage,
            ],
            'package_id' => $this->package_id,
            'package' => $this->when($this->package_id, function () {
                return [
                    'id' => $this->package->id,
                    'package_name' => $this->package->package_name,
                    'quantity_per_package' => (float) $this->package->quantity_per_package,
                    'barcode' => $this->package->barcode,
                ];
            }),
            'unit_id' => $this->unit_id,
            'unit' => $this->when($this->unit_id, function () {
                return [
                    'id' => $this->unit->id,
                    'name' => $this->unit->name,
                    'abbreviation' => $this->unit->abbreviation,
                ];
            }),
            'quantity_requested' => (float) $this->quantity_requested,
            'quantity_shipped' => (float) $this->quantity_shipped,
            'quantity_received' => (float) $this->quantity_received,
            'available_stock' => $availableStock,
            'difference' => $this->difference,
            'has_difference' => $this->has_difference,
            'unit_cost' => (float) $this->unit_cost,
            'total_cost' => (float) $this->total_cost,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'damage_report' => $this->damage_report,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
