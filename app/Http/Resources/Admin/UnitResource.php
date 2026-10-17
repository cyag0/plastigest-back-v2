<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
            'unit_type' => $resource->unit_type,
            'company_id' => $resource->company_id,
            // La equivalencia escrita ("1 cj = 12 pz"). Antes era siempre "N/A":
            // se armaba con base_unit_id, que no es columna de units.
            'description' => $this->equivalence($resource),
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

    /**
     * "Unidad base" o "1 cj = 12 pz": cada unidad se mide contra la unidad
     * base de su tipo (la de la empresa o la del sistema).
     */
    private function equivalence(Model $unit): ?string
    {
        if ($unit->is_base_unit) {
            return 'Unidad base';
        }

        $factor = (float) $unit->factor_to_base;
        if ($factor <= 0 || !$unit->unit_type) {
            return null;
        }

        // Una consulta por tipo y empresa en toda la petición (el store
        // "array" vive lo que dura la petición), no una por renglón.
        $key = 'unit-base:' . $unit->unit_type . ':' . ($unit->company_id ?? 'system');
        $base = Cache::store('array')->rememberForever($key, fn() => Unit::query()
            ->where('unit_type', $unit->unit_type)
            ->where('is_base_unit', true)
            ->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $unit->company_id))
            ->orderByRaw('company_id IS NULL')
            ->value('abbreviation') ?? '');

        if ($base === '') {
            return null;
        }

        $factorText = rtrim(rtrim(number_format($factor, 6, '.', ','), '0'), '.');

        return "1 {$unit->abbreviation} = {$factorText} {$base}";
    }
}
