<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'description' => $this->description,
            'code' => $this->code,
            'purchase_price' => $this->purchase_price,
            'sale_price' => $this->sale_price,
            'company_id' => $this->company_id ? [$this->company_id . ""] : null,
            'category_id' => $this->category_id ? [$this->category_id . ""] : null,
            'unit_id' => $this->unit_id ? [$this->unit_id . ""] : null,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relaciones
            'company_name' => $this->whenLoaded('company', function () {
                return $this->company->name;
            }),
            'company' => $this->whenLoaded('company'),

            'category_name' => $this->whenLoaded('category', function () {
                return $this->category ? $this->category->name : null;
            }),
            'category' => $this->whenLoaded('category'),

            'unit_name' => $this->whenLoaded('unit', function () {
                return $this->unit ? $this->unit->name : null;
            }),
            'unit' => $this->whenLoaded('unit'),
        ];
    }
}
