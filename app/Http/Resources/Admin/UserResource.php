<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\User;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
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
            'name' => $resource->name,
            'email' => $resource->email,
            'avatar' => $resource->avatar
                ? [AppUploadUtil::formatFile(Files::USER_AVATARS_PATH, $resource->avatar)] ?? []
                : [],
            'avatar_name' => $resource->avatar,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['is_active'] = $resource->is_active ?? true;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Relación con companies
        if ($resource->relationLoaded('companies')) {
            if (!$editing) {
                // Para index: solo información básica
                $item['companies'] = $resource->companies->map(fn($company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                ]);
            } else {
                // Para show/edit: datos completos
                $item['companies'] = $resource->companies;
                $item['company_ids'] = $resource->companies->pluck('id')->toArray();
            }
        }

        return $item;
    }
}
