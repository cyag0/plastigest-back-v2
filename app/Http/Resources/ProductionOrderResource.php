<?php

namespace App\Http\Resources;

use App\Enums\ProductionOrderStatus;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Services\ProductionOrderService;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        /** @var ProductionOrder $resource */
        $status = $resource->status instanceof ProductionOrderStatus
            ? $resource->status
            : ProductionOrderStatus::from((string) $resource->status);
        $isPackaging = $resource->isPackaging();
        $formulaName = $resource->relationLoaded('formula') ? $resource->formula?->name : null;

        $item = [
            'id' => $resource->id,
            'folio' => $resource->folio,
            'kind' => $resource->kind ?? ProductionOrder::KIND_PROCESS,
            'kind_label' => $isPackaging ? 'Envasado' : 'Producción',
            // Lo que se muestra como nombre: el proceso que se corrió.
            'title' => $isPackaging ? 'Envasado' : ($formulaName ?? 'Producción'),
            'production_date' => $resource->production_date?->format('Y-m-d'),
            'company_id' => $resource->company_id,
            'location_id' => $resource->location_id,
            'location_name' => $resource->relationLoaded('location') ? ($resource->location?->name) : null,
            'formula_id' => $resource->formula_id,
            'formula_name' => $formulaName,
            'responsible_user_id' => $resource->responsible_user_id,
            'responsible_name' => $resource->relationLoaded('responsible') ? ($resource->responsible?->name) : null,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'status_icon' => $status->icon(),
            'notes' => $resource->notes,
            'wastes' => $resource->getAttribute('wastes') ?? [],
            'total_consumed_quantity' => $resource->total_consumed_quantity !== null ? (float) $resource->total_consumed_quantity : null,
            'total_produced_quantity' => $resource->total_produced_quantity !== null ? (float) $resource->total_produced_quantity : null,
            'waste_percentage' => $resource->waste_percentage !== null ? (float) $resource->waste_percentage : null,
            'completed_at' => optional($resource->completed_at)->toISOString(),
            'packed_at' => optional($resource->packed_at)->toISOString(),
            'cancelled_at' => optional($resource->cancelled_at)->toISOString(),
            'created_at' => optional($resource->created_at)->toISOString(),
            'updated_at' => optional($resource->updated_at)->toISOString(),
        ];

        if ($resource->relationLoaded('consumptions')) {
            $item['consumptions'] = $resource->consumptions->map(fn($c) => $this->line($c))->values();
        }

        if ($resource->relationLoaded('outputs')) {
            $item['outputs'] = $resource->outputs->map(fn($o) => $this->line($o))->values();
        }

        if ($status === ProductionOrderStatus::DRAFT) {
            // Un borrador todavía no generó ajustes de inventario: sus mermas
            // viven sólo en la columna JSON. Sin esto la relación sale vacía y
            // al editar el borrador se perdían.
            $item['wastes'] = $this->plannedWastes($resource->getAttribute('wastes') ?? []);
            $item['planned_packagings'] = $this->plannedPackagings($resource->planned_packagings ?? []);
        } elseif ($resource->relationLoaded('wastes')) {
            // getRelation() bypasses attribute casts — needed because 'wastes' is also
            // a JSON column on the model, and getAttribute() would return the array cast
            // instead of the Eloquent Collection.
            $item['wastes'] = $resource->getRelation('wastes')->map(function ($w) {
                return [
                    'id' => $w->id,
                    'product_id' => $w->product_id,
                    'product_name' => $w->relationLoaded('product') ? ($w->product?->name) : null,
                    'unit_id' => $w->unit_id,
                    'unit_name' => $w->relationLoaded('unit') ? ($w->unit?->name) : null,
                    'unit_abbreviation' => $w->relationLoaded('unit') ? ($w->unit?->abbreviation) : null,
                    'quantity' => (float) $w->quantity,
                    'reason' => $w->reason_code instanceof \BackedEnum ? $w->reason_code->value : $w->reason_code,
                    'reason_label' => $w->reason_code instanceof \BackedEnum ? $w->reason_code->label() : $w->reason_code,
                    'notes' => $w->notes,
                    'reverted' => !empty($w->content['reverted_at']),
                ];
            })->values()->all();
        }

        if ($resource->relationLoaded('packagings')) {
            $item['packagings'] = $resource->packagings->map(function ($p) {
                return [
                    'id' => $p->id,
                    'product_id' => $p->product_id,
                    'product_name' => $p->relationLoaded('product') ? ($p->product?->name) : null,
                    'product_code' => $p->relationLoaded('product') ? ($p->product?->code) : null,
                    'unit_id' => $p->unit_id,
                    'unit_name' => $p->relationLoaded('unit') ? ($p->unit?->name) : null,
                    'unit_abbreviation' => $p->relationLoaded('unit') ? ($p->unit?->abbreviation) : null,
                    'quantity' => (float) $p->quantity,
                    'source_product_id' => $p->source_product_id,
                    'source_product_name' => $p->relationLoaded('sourceProduct') ? ($p->sourceProduct?->name) : null,
                    'source_unit_id' => $p->source_unit_id,
                    'source_unit_name' => $p->relationLoaded('sourceUnit') ? ($p->sourceUnit?->name) : null,
                    'source_unit_abbreviation' => $p->relationLoaded('sourceUnit') ? ($p->sourceUnit?->abbreviation) : null,
                    'source_quantity' => $p->source_quantity !== null ? (float) $p->source_quantity : null,
                    'packed_at' => optional($p->packed_at)->toISOString(),
                    'notes' => $p->notes,
                    'materials' => $p->relationLoaded('materials')
                        ? $p->materials->map(fn($m) => [
                            'id' => $m->id,
                            'product_id' => $m->product_id,
                            'product_name' => $m->relationLoaded('product') ? ($m->product?->name) : null,
                            'unit_id' => $m->unit_id,
                            'unit_name' => $m->relationLoaded('unit') ? ($m->unit?->name) : null,
                            'unit_abbreviation' => $m->relationLoaded('unit') ? ($m->unit?->abbreviation) : null,
                            'quantity' => (float) $m->quantity,
                        ])->values()->all()
                        : [],
                ];
            })->values();
        }

        // Rendimiento por insumo principal (litros de agua por coco…). Sólo si
        // ya vienen los renglones: no se cargan relaciones extra por orden.
        if ($resource->relationLoaded('consumptions') && $resource->relationLoaded('outputs')) {
            $item['yields'] = app(ProductionOrderService::class)->yields($resource);
        }

        $item['summary'] = [
            'consumption_count' => $resource->relationLoaded('consumptions') ? $resource->consumptions->count() : null,
            'output_count' => $resource->relationLoaded('outputs') ? $resource->outputs->count() : null,
            'waste_count' => $resource->relationLoaded('wastes') ? $resource->getRelation('wastes')->count() : null,
            'packaging_count' => $resource->relationLoaded('packagings') ? $resource->packagings->count() : null,
            'packaged_quantity' => $resource->relationLoaded('packagings') ? (float) $resource->packagings->sum('quantity') : null,
            'waste_percentage' => $resource->waste_percentage !== null ? (float) $resource->waste_percentage : null,
        ];

        return $item;
    }

    /** Un consumo o una salida, con nombre y unidad. */
    private function line($row): array
    {
        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'product_name' => $row->relationLoaded('product') ? ($row->product?->name) : null,
            'product_code' => $row->relationLoaded('product') ? ($row->product?->code) : null,
            'unit_id' => $row->unit_id,
            'unit_name' => $row->relationLoaded('unit') ? ($row->unit?->name) : null,
            'unit_abbreviation' => $row->relationLoaded('unit') ? ($row->unit?->abbreviation) : null,
            'quantity' => (float) $row->quantity,
            'expected_quantity' => $row->expected_quantity !== null ? (float) $row->expected_quantity : null,
            'variance_pct' => $this->variancePct($row->quantity, $row->expected_quantity),
            'notes' => $row->notes,
        ];
    }

    /** Las mermas de un borrador, con el nombre del producto para mostrarlas. */
    private function plannedWastes(array $wastes): array
    {
        if (!$wastes) {
            return [];
        }

        $products = Product::with('unit')->whereIn('id', array_column($wastes, 'product_id'))->get()->keyBy('id');

        return array_values(array_map(fn($w) => $w + [
            'product_name' => $products->get($w['product_id'])?->name,
            'unit_abbreviation' => $products->get($w['product_id'])?->unit?->abbreviation,
        ], $wastes));
    }

    /** Lo que se va a envasar al registrar el borrador. */
    private function plannedPackagings(array $lines): array
    {
        if (!$lines) {
            return [];
        }

        $products = Product::with(['unit', 'packagingSourceProduct', 'packagingSourceUnit'])
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        return array_values(array_map(function ($line) use ($products) {
            $product = $products->get($line['product_id']);

            return [
                'product_id' => (int) $line['product_id'],
                'product_name' => $product?->name,
                'quantity' => (float) $line['quantity'],
                'unit_id' => $line['unit_id'] ?? $product?->unit_id,
                'unit_abbreviation' => $product?->unit?->abbreviation,
                'source_product_id' => $product?->packaging_source_product_id,
                'source_product_name' => $product?->packagingSourceProduct?->name,
            ];
        }, $lines));
    }

    private function variancePct($actual, $expected): ?float
    {
        if ($expected === null || (float) $expected <= 0) {
            return null;
        }
        return round((((float) $actual - (float) $expected) / (float) $expected) * 100, 2);
    }
}
