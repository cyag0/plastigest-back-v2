<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Model;

class LocationResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Location $resource
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
            'description' => $resource->description,
            'address' => $resource->address,
            'phone' => $resource->phone,
            'email' => $resource->email,
            'company_id' => $resource->company_id,
            'is_main' => $resource->is_main ?? false,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['is_active'] = $resource->is_active ?? true;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        } else {
            // Para index: mostrar solo el estado
            $item['is_active'] = $resource->is_active ?? true;
        }

        // Manejo de la relación con Company
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                // Para index: datos simples
                $item['company_name'] = $resource->company?->name;
            } else {
                // Para show/edit: datos completos
                $item['company'] = $resource->company ? [
                    'id' => $resource->company->id,
                    'name' => $resource->company->name,
                    'business_name' => $resource->company->business_name,
                ] : null;
            }
        }

        return $item;
    }
}
