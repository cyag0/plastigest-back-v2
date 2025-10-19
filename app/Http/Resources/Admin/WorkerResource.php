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
                $item['company'] = [
                    'id' => $resource->company?->id,
                    'name' => $resource->company?->name,
                ];
            }
        }

        // Relación con User
        if ($resource->relationLoaded('user')) {
            if (!$editing) {
                $item['user_name'] = $resource->user?->name;
                $item['user_email'] = $resource->user?->email;
            } else {
                $item['user'] = [
                    'id' => $resource->user?->id,
                    'name' => $resource->user?->name,
                    'email' => $resource->user?->email,
                ];
            }
        }

        // Relaciones many-to-many
        if ($resource->relationLoaded('roles')) {
            $item['role_ids'] = $resource->roles->map(function ($role) {
                return $role->id . "";
            });
        }

        if ($resource->relationLoaded('companies')) {
            $item['companies'] = $resource->companies->map(function ($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                ];
            });
        }

        if ($resource->relationLoaded('locations')) {
            $item['location_ids'] = $resource->locations->map(function ($location) {
                return $location->id;
            });
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
