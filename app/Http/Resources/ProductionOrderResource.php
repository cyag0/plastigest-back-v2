<?php

namespace App\Http\Resources;

use App\Enums\ProductionOrderStatus;
use App\Models\Operations\ProductionOrder;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        /** @var ProductionOrder $resource */
        $status = $resource->status instanceof ProductionOrderStatus
            ? $resource->status
            : ProductionOrderStatus::from((string) $resource->status);

        $item = [
            'id' => $resource->id,
            'folio' => $resource->folio,
            'production_date' => $resource->production_date?->format('Y-m-d'),
            'company_id' => $resource->company_id,
            'location_id' => $resource->location_id,
            'location_name' => $resource->relationLoaded('location') ? ($resource->location?->name) : null,
            'formula_id' => $resource->formula_id,
            'formula_name' => $resource->relationLoaded('formula') ? ($resource->formula?->name) : null,
            'responsible_user_id' => $resource->responsible_user_id,
            'responsible_name' => $resource->relationLoaded('responsible') ? ($resource->responsible?->name) : null,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'notes' => $resource->notes,
            'wastes' => $resource->wastes ?? [],
            'total_consumed_quantity' => $resource->total_consumed_quantity !== null ? (float) $resource->total_consumed_quantity : null,
            'total_produced_quantity' => $resource->total_produced_quantity !== null ? (float) $resource->total_produced_quantity : null,
            'waste_percentage' => $resource->waste_percentage !== null ? (float) $resource->waste_percentage : null,
            'completed_at' => optional($resource->completed_at)->toISOString(),
            'cancelled_at' => optional($resource->cancelled_at)->toISOString(),
            'created_at' => optional($resource->created_at)->toISOString(),
            'updated_at' => optional($resource->updated_at)->toISOString(),
        ];

        if ($resource->relationLoaded('consumptions')) {
            $item['consumptions'] = $resource->consumptions->map(function ($c) {
                return [
                    'id' => $c->id,
                    'product_id' => $c->product_id,
                    'product_name' => $c->relationLoaded('product') ? ($c->product?->name) : null,
                    'product_code' => $c->relationLoaded('product') ? ($c->product?->code) : null,
                    'unit_id' => $c->unit_id,
                    'unit_name' => $c->relationLoaded('unit') ? ($c->unit?->name) : null,
                    'quantity' => (float) $c->quantity,
                    'expected_quantity' => $c->expected_quantity !== null ? (float) $c->expected_quantity : null,
                    'variance_pct' => $this->variancePct($c->quantity, $c->expected_quantity),
                    'notes' => $c->notes,
                ];
            })->values();
        }

        if ($resource->relationLoaded('outputs')) {
            $item['outputs'] = $resource->outputs->map(function ($o) {
                return [
                    'id' => $o->id,
                    'product_id' => $o->product_id,
                    'product_name' => $o->relationLoaded('product') ? ($o->product?->name) : null,
                    'product_code' => $o->relationLoaded('product') ? ($o->product?->code) : null,
                    'unit_id' => $o->unit_id,
                    'unit_name' => $o->relationLoaded('unit') ? ($o->unit?->name) : null,
                    'quantity' => (float) $o->quantity,
                    'expected_quantity' => $o->expected_quantity !== null ? (float) $o->expected_quantity : null,
                    'variance_pct' => $this->variancePct($o->quantity, $o->expected_quantity),
                    'notes' => $o->notes,
                ];
            })->values();
        }

        if ($resource->relationLoaded('wastes')) {
            $item['wastes'] = $resource->wastes->map(function ($w) {
                return [
                    'id' => $w->id,
                    'product_id' => $w->product_id,
                    'product_name' => $w->relationLoaded('product') ? ($w->product?->name) : null,
                    'unit_id' => $w->unit_id,
                    'unit_name' => $w->relationLoaded('unit') ? ($w->unit?->name) : null,
                    'quantity' => (float) $w->quantity,
                    'reason' => $w->reason_code instanceof \BackedEnum ? $w->reason_code->value : $w->reason_code,
                    'reason_label' => $w->reason_code instanceof \BackedEnum ? $w->reason_code->label() : $w->reason_code,
                    'notes' => $w->notes,
                ];
            })->values()->all();
        }

        // Summary computado
        $item['summary'] = [
            'consumption_count' => $resource->relationLoaded('consumptions') ? $resource->consumptions->count() : null,
            'output_count' => $resource->relationLoaded('outputs') ? $resource->outputs->count() : null,
            'waste_count' => $resource->relationLoaded('wastes') ? $resource->wastes->count() : null,
            'waste_percentage' => $resource->waste_percentage !== null ? (float) $resource->waste_percentage : null,
        ];

        return $item;
    }

    private function variancePct($actual, $expected): ?float
    {
        if ($expected === null || (float) $expected <= 0) {
            return null;
        }
        return round((((float) $actual - (float) $expected) / (float) $expected) * 100, 2);
    }
}
