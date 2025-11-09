<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
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
            'name' => $this->name,
            'symbol' => $this->symbol,
            'description' => $this->description,
            'type' => $this->type,
            'is_base' => $this->is_base,
            'conversion_rate' => $this->conversion_rate,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn() => $this->company->name),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}