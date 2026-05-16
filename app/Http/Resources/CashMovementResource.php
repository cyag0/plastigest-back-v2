<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'company_id'           => $this->company_id,
            'location_id'          => $this->location_id,
            'user_id'              => $this->user_id,
            'type'                 => $this->type,
            'type_label'           => $this->type_label,
            'amount'               => (float) $this->amount,
            'concept'              => $this->concept,
            'payment_method'       => $this->payment_method,
            'payment_method_label' => $this->payment_method_label,
            'source_type'          => $this->source_type,
            'source_id'            => $this->source_id,
            'source_url'           => $this->source_url,
            'notes'                => $this->notes,
            'movement_date'        => $this->movement_date?->format('Y-m-d'),
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),

            'user' => $this->whenLoaded('user', fn() => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ]),

            'location' => $this->whenLoaded('location', fn() => [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ]),
        ];
    }
}
