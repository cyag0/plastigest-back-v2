<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;

class CategoryResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Category $resource
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
            'company_id' => $resource->company_id ? [$resource->company_id . ""] : null,
            'is_active' => $resource->is_active ?? true,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Manejo de relación con company
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                // Para index: solo el nombre de la compañía
                $item['company_name'] = $resource->company?->name;
            } else {
                // Para show/edit: datos completos de la compañía
                $item['company'] = $resource->company;
            }
        }

        return $item;
    }
}
