<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Company;
use Illuminate\Database\Eloquent\Model;

class CompanyResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Company $resource
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
            'is_active' => $resource->is_active ?? true,
        ];

        // Campos opcionales que pueden estar vacíos
        if ($resource->phone) {
            $item['phone'] = $resource->phone;
        }

        if ($resource->address) {
            $item['address'] = $resource->address;
        }

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit - incluir todo
            $item['phone'] = $resource->phone ?? '';
            $item['address'] = $resource->address ?? '';
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();

            // Información adicional para la vista de detalle
            $item['rfc_formatted'] = $this->formatRFC($resource->rfc);
            $item['full_contact'] = $this->formatFullContact($resource);
        } else {
            // Para index: incluir datos útiles para búsqueda y display
            if ($resource->phone) {
                $item['phone'] = $resource->phone;
            }
            if ($resource->address) {
                $item['address'] = $this->truncateAddress($resource->address);
            }
        }

        // Manejo de relaciones si existen
        if ($resource->relationLoaded('users')) {
            if (!$editing) {
                // Para index: solo el conteo
                $item['users_count'] = $resource->users->count();
            } else {
                // Para show/edit: información completa de usuarios
                $item['users'] = $resource->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                });
            }
        }

        if ($resource->relationLoaded('locations')) {
            if (!$editing) {
                // Para index: solo el conteo
                $item['locations_count'] = $resource->locations->count();
            } else {
                // Para show/edit: información completa de ubicaciones
                $item['locations'] = $resource->locations->map(function ($location) {
                    return [
                        'id' => $location->id,
                        'name' => $location->name,
                        'address' => $location->address,
                        'is_main' => $location->is_main ?? false,
                    ];
                });
            }
        }

        return $item;
    }

    /**
     * Formatear RFC para mejor visualización
     */
    private function formatRFC(string $rfc): string
    {
        if (strlen($rfc) === 12) {
            // Persona física: ABCD123456AB1
            return substr($rfc, 0, 4) . '-' . substr($rfc, 4, 6) . '-' . substr($rfc, 10);
        } elseif (strlen($rfc) === 13) {
            // Persona moral: ABC1234567AB1
            return substr($rfc, 0, 3) . '-' . substr($rfc, 3, 6) . '-' . substr($rfc, 9);
        }

        return $rfc;
    }

    /**
     * Formatear información de contacto completa
     */
    private function formatFullContact(Model $resource): array
    {
        $contact = [];

        if ($resource->email) {
            $contact['email'] = $resource->email;
        }

        if ($resource->phone) {
            $contact['phone'] = $resource->phone;
        }

        if ($resource->address) {
            $contact['address'] = $resource->address;
        }

        return $contact;
    }

    /**
     * Truncar dirección para el listado
     */
    private function truncateAddress(string $address, int $limit = 80): string
    {
        if (strlen($address) <= $limit) {
            return $address;
        }

        return substr($address, 0, $limit) . '...';
    }
}
