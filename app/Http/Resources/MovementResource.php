<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementResource extends JsonResource
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
            'transfer_number' => $content['transfer_number'] ?? 'MOV-' . str_pad($this->id, 6, '0', STR_PAD_LEFT),
            'company_id' => $this->company_id,

            // Locations
            'from_location_id' => $this->location_origin_id,
            'from_location' => $this->whenLoaded('locationOrigin', function () {
                return [
                    'id' => $this->locationOrigin->id,
                    'name' => $this->locationOrigin->name,
                    'is_main' => $this->locationOrigin->is_main ?? false,
                    'address' => $this->locationOrigin->address ?? null,
                ];
            }),

            'to_location_id' => $this->location_destination_id,
            'to_location' => $this->whenLoaded('locationDestination', function () {
                return [
                    'id' => $this->locationDestination->id,
                    'name' => $this->locationDestination->name,
                    'is_main' => $this->locationDestination->is_main ?? false,
                    'address' => $this->locationDestination->address ?? null,
                ];
            }),

            // Status
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),

            // Users
            'requested_by' => $content['requested_by'] ?? $this->user_id,
            'requested_by_user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),

            'approved_by' => $content['approved_by'] ?? null,
            'shipped_by' => $content['shipped_by'] ?? null,
            'received_by' => $content['received_by'] ?? null,

            // Dates
            'requested_at' => $this->movement_date ?
                (\Carbon\Carbon::parse($this->movement_date)->toISOString()) : null,
            'approved_at' => isset($content['approved_at']) ?
                \Carbon\Carbon::parse($content['approved_at'])->toISOString() : null,
            'shipped_at' => isset($content['shipped_at']) ?
                \Carbon\Carbon::parse($content['shipped_at'])->toISOString() : null,
            'received_at' => isset($content['received_at']) ?
                \Carbon\Carbon::parse($content['received_at'])->toISOString() : null,
            'rejected_at' => isset($content['rejected_at']) ?
                \Carbon\Carbon::parse($content['rejected_at'])->toISOString() : null,

            // Financial
            'total_cost' => $this->total_cost ? (float) $this->total_cost : 0,

            // Notes
            'notes' => $this->notes,
            'rejection_reason' => $content['rejection_reason'] ?? null,
            'shipping_notes' => $content['shipping_notes'] ?? null,
            'receiving_notes' => $content['receiving_notes'] ?? null,

            // Details
            'details' => MovementDetailResource::collection($this->whenLoaded('details')),

            // Metadata
            'has_differences' => $content['has_differences'] ?? false,
            'received_complete' => $content['received_complete'] ?? false,
            'received_partial' => $content['received_partial'] ?? false,

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Get human-readable status label
     */
    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Borrador',
            'ordered' => 'Ordenado',
            'in_transit' => 'En Tránsito',
            'received' => 'Recibido',
            'closed' => 'Cerrado',
            'rejected' => 'Rechazado',
            'open' => 'Abierto',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    protected function getStatusColor(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'ordered' => 'blue',
            'in_transit' => 'orange',
            'received' => 'green',
            'closed' => 'green',
            'rejected' => 'red',
            'open' => 'blue',
            default => 'gray',
        };
    }
}
