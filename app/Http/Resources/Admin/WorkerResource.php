<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Worker;
use Illuminate\Database\Eloquent\Model;

class WorkerResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Worker $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'company_id' => $resource->company_id,
            'user_id' => $resource->user_id,
            'role_id' => $resource->role_id,
            'position' => $resource->position,
            'department' => $resource->department,
            'hire_date' => $resource->hire_date?->format('Y-m-d'),
            'salary' => $resource->salary,
            'is_active' => $resource->is_active ?? true,
        ];

        // Relación con Company
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                $item['company_name'] = $resource->company?->name;
            } else {
                $item['company'] = $resource->company ? [
                    'id' => $resource->company->id,
                    'name' => $resource->company->name,
                    'business_name' => $resource->company->business_name,
                ] : null;
            }
        }

        // Relación con User
        if ($resource->relationLoaded('user')) {
            if (!$editing) {
                $item["name"] = $resource->user?->name;
                $item['user_name'] = $resource->user?->name;
                $item['user_email'] = $resource->user?->email;
            } else {
                $item['user'] = $resource->user ? [
                    'id' => $resource->user->id,
                    'name' => $resource->user->name,
                    'email' => $resource->user->email,
                ] : null;
            }
        }

        // Relación con Role
        if ($resource->relationLoaded('role')) {
            if (!$editing) {
                $item['role_name'] = $resource->role?->name;
            } else {
                $item['role'] = $resource->role ? [
                    'id' => $resource->role->id,
                    'name' => $resource->role->name,
                    'display_name' => $resource->role->display_name,
                ] : null;
            }
        }

        // Incluir role también en index para mostrar en la lista
        if (!$editing && $resource->relationLoaded('role') && $resource->role) {
            $item['role'] = [
                'id' => $resource->role->id,
                'name' => $resource->role->name,
            ];
        }

        // Relación con Locations
        if ($resource->relationLoaded('locations')) {
            if (!$editing) {
                $item['locations'] = $resource->locations->map(fn($location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                ]);
            } else {
                $item['locations'] = $resource->locations;
                $item['location_ids'] = $resource->locations->pluck('id')->toArray();
            }
        }

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
