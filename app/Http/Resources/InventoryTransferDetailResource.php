<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            $productLocationStock = \DB::table('product_location_stock')
                ->where('product_id', $this->product_id)
                ->where('location_id', $transfer->from_location_id)
                ->first();
            $availableStock = $productLocationStock ? (float) $productLocationStock->available_stock : 0;
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
            ],
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
            
            // Envíos detallados (productos realmente enviados)
            'shipments' => InventoryTransferShipmentResource::collection($this->whenLoaded('shipments')),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
