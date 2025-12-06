<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'transfer_number' => $this->transfer_number,
            
            // Ubicaciones
            'from_location_id' => $this->from_location_id,
            'from_location' => [
                'id' => $this->fromLocation->id,
                'name' => $this->fromLocation->name,
                'is_main' => $this->fromLocation->is_main,
            ],
            'to_location_id' => $this->to_location_id,
            'to_location' => [
                'id' => $this->toLocation->id,
                'name' => $this->toLocation->name,
                'is_main' => $this->toLocation->is_main,
            ],
            
            // Estado
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            
            // Usuarios
            'requested_by' => $this->requested_by,
            'requested_by_user' => $this->requestedByUser ? [
                'id' => $this->requestedByUser->id,
                'name' => $this->requestedByUser->name,
            ] : null,
            
            'approved_by' => $this->approved_by,
            'approved_by_user' => $this->approvedByUser ? [
                'id' => $this->approvedByUser->id,
                'name' => $this->approvedByUser->name,
            ] : null,
            
            'shipped_by' => $this->shipped_by,
            'shipped_by_user' => $this->shippedByUser ? [
                'id' => $this->shippedByUser->id,
                'name' => $this->shippedByUser->name,
            ] : null,
            
            'received_by' => $this->received_by,
            'received_by_user' => $this->receivedByUser ? [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->name,
            ] : null,
            
            // Fechas
            'requested_at' => $this->requested_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            
            // Totales y notas
            'total_cost' => (float) $this->total_cost,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            
            // Diferencias
            'total_differences' => $this->total_differences,
            'has_differences' => $this->has_differences,
            
            // Detalles (solo si están cargados)
            'details' => InventoryTransferDetailResource::collection($this->whenLoaded('details')),
            
            // Shipments - aplanar todos los shipments de todos los detalles
            'shipments' => $this->when($this->relationLoaded('details'), function() {
                return $this->details->flatMap(function($detail) {
                    return ($detail->shipments ?? collect())->map(function($shipment) {
                        \Log::info('Processing shipment:', [
                            'shipment_id' => $shipment->id,
                            'product_id' => $shipment->product_id,
                            'product_loaded' => $shipment->relationLoaded('product'),
                            'product_name' => $shipment->product?->name ?? 'NO NAME',
                            'product_code' => $shipment->product?->code ?? 'NO CODE'
                        ]);
                        
                        return [
                            'id' => $shipment->id,
                            'transfer_detail_id' => $shipment->transfer_detail_id,
                            'product_id' => $shipment->product_id,
                            'product' => $shipment->product ? [
                                'id' => $shipment->product->id,
                                'name' => $shipment->product->name,
                                'code' => $shipment->product->code,
                                'sku' => $shipment->product->code, // Usar code como sku
                            ] : null,
                            'quantity_shipped' => (float) $shipment->quantity_shipped,
                            'unit_cost' => (float) $shipment->unit_cost,
                            'total_cost' => (float) $shipment->total_cost,
                            'batch_number' => $shipment->batch_number,
                            'expiry_date' => $shipment->expiry_date?->format('Y-m-d'),
                            'notes' => $shipment->notes,
                        ];
                    });
                })->values();
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
