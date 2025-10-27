<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Supplier $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'name' => $resource->name,
            'business_name' => $resource->business_name,
            'rfc' => $resource->rfc,
            'email' => $resource->email,
            'phone' => $resource->phone,
            'is_active' => $resource->is_active,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['social_reason'] = $resource->social_reason;
            $item['address'] = $resource->address;
            $item['company_id'] = $resource->company_id;
            $item['is_active'] = $resource->is_active ?? true;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
            $item["company_id"] = $resource->company_id;
        }

        // Manejo de relaciones
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                // Para index: datos simples
                $item['company_name'] = $resource->company?->name;
            } else {
                // Para show/edit: datos completos
                $item['company'] = $resource->company;
            }
        }

        return $item;
    }
}
