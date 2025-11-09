<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Customer $resource
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
            'name' => $resource->name,
            'business_name' => $resource->business_name,
            'social_reason' => $resource->social_reason,
            'rfc' => $resource->rfc,
            'address' => $resource->address,
            'phone' => $resource->phone,
            'email' => $resource->email,
            'is_active' => $resource->is_active ?? true,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Manejo de relación con Company
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                $item['company_name'] = $resource->company?->name;
            } else {
                $item['company'] = $resource->company;
            }
        }

        return $item;
    }
}
