<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;

class UnitResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Unit $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $item = [
            'id' => $resource->id,
            'name' => $resource->name,
            'abbreviation' => $resource->abbreviation,
            'company_id' => $resource->company_id,
            'type' => $resource->type,
            'is_base' => $resource->is_base ?? false,
            'factor_to_base' => $resource->factor_to_base,
            'created_at' => $resource->created_at,
        ];

        // Agregar descripción basada en el tipo y factor
        if ($resource->is_base) {
            $item['description'] = "Unidad base de {$this->getTypeLabel($resource->type)}";
        } elseif ($resource->factor_to_base && $resource->factor_to_base != 1) {
            $item['description'] = "1 {$resource->abbreviation} = {$resource->factor_to_base} unidades base";
        } else {
            $item['description'] = "N/A";
        }

        if ($resource->relationLoaded('company')) {
            $item['company_name'] = $resource->company->name ?? '';
        }

        return $item;
    }

    /**
     * Obtener la etiqueta del tipo de unidad
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'volume' => 'Volumen',
            'mass' => 'Masa',
            'quantity' => 'Cantidad',
            'length' => 'Longitud',
            'area' => 'Área',
            'other' => 'Otro',
            default => 'Desconocido'
        };
    }
}
