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
            'employee_number' => $resource->employee_number,
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

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
