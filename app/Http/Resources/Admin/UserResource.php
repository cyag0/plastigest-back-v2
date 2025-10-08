<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param User $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            // Agregar aquí los campos básicos del modelo
            // Ejemplo:
            // 'name' => $resource->name,
            // 'description' => $resource->description,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['is_active'] = $resource->is_active ?? true;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Ejemplo de manejo de relaciones
        // if ($resource->relationLoaded('relatedModel')) {
        //     if (!$editing) {
        //         // Para index: datos simples
        //         $item['related_name'] = $resource->relatedModel?->name;
        //     } else {
        //         // Para show/edit: datos completos
        //         $item['related_model'] = $resource->relatedModel;
        //     }
        // }

        return $item;
    }
}
