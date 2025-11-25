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
            'description' => "N/A",
            'base_unit_id' => $resource->base_unit_id,
            'factor_to_base' => $resource->factor_to_base,
            'created_at' => $resource->created_at,

            'conversion' => isset($resource->base_unit_id)
        ];

        if ($resource->relationLoaded('baseUnit') && $resource->baseUnit) {
            $item['base_unit'] = [
                'id' => $resource->baseUnit->id,
                'name' => $resource->baseUnit->name,
                'abbreviation' => $resource->baseUnit->abbreviation,
            ];

            $item['description'] = "1 {$resource->abbreviation} = {$resource->factor_to_base} {$resource->baseUnit->abbreviation}";
        } else {
            $item['base_unit'] = null;
        }


        if ($resource->relationLoaded('company')) {
            $item['company_name'] = $resource->company->name;
        }

        return $item;
    }
}
