<?php

namespace App\Http\Resources\Admin\Permissions;

use App\Http\Resources\Resources;
use App\Models\Admin\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RolesResource extends Resources
{
    /**
     * @var Role $resource
     */
    public function formatter($resource, array $data, array $context): array
    {
        $editing = $context['editing'] ?? false;

        $item = [
            'id' => $resource->id,
            'name' => $resource->name,
            'description' => $resource->description,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['is_active'] = $resource->is_active ?? true;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Permisos según contexto
        if ($resource->relationLoaded('permissions')) {
            if (!$editing) {
                // Para index: solo las primeras descripciones (para los chips)
                $item['permissions'] = $resource->permissions
                    ->take(10) // Limitar para performance
                    ->pluck('description');

                $item['permissions_count'] = $resource->permissions->count();
            } else {
                // Para show/edit: datos completos de permisos
                // Forzar que permissions sea un objeto (no array) para el frontend
                $permissions = (object)[];
                foreach ($resource->permissions as $permission) {
                    $permissions->{$permission->id} = true;
                }
                $item['permissions'] = $permissions;
            }
        }

        return $item;
    }
}
