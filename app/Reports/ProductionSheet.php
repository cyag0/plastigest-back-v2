<?php

namespace App\Reports;

use App\Enums\ProductionOrderStatus;
use App\Models\Admin\Company;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\Unit;
use App\Reports\Format as F;
use App\Services\ProductionOrderService;
use Illuminate\Support\Facades\DB;

/**
 * Hoja de producción para la planta, que no trabaja frente a la app.
 *
 * Mientras la orden está pendiente dice qué producir, qué insumos sacar del
 * almacén (con la existencia de la sucursal al imprimir) y qué envasar, y deja
 * los espacios para anotar a mano lo real y la merma. Ya registrada, compara
 * lo producido contra lo esperado del proceso, da el rendimiento por insumo
 * principal (litros de agua por coco) y muestra el envasado y la merma.
 */
final class ProductionSheet
{
    public function __construct(private readonly ProductionOrder $order) {}

    public static function load(int $companyId, int $orderId): self
    {
        $order = ProductionOrder::with([
            'location', 'responsible', 'formula.items', 'formula.outputs', 'createdBy',
            'consumptions.product.unit', 'consumptions.unit',
            'outputs.product.unit', 'outputs.unit',
            'wastes.product', 'wastes.unit',
            'packagings.product', 'packagings.unit', 'packagings.sourceProduct', 'packagings.sourceUnit',
            'packagings.materials.product', 'packagings.materials.unit',
        ])->where('company_id', $companyId)->findOrFail($orderId);

        return new self($order);
    }

    public function fileName(): string
    {
        return 'hoja-produccion-' . $this->order->folio;
    }

    public function data(): array
    {
        $order = $this->order;
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::tryFrom((string) $order->status);
        $closed = in_array($status, [ProductionOrderStatus::COMPLETED, ProductionOrderStatus::PACKAGING, ProductionOrderStatus::PACKED], true);

        $converter = new QuantityConverter();
        $stock = DB::table('product_location')
            ->where('location_id', $order->location_id)
            ->whereIn('product_id', $order->consumptions->pluck('product_id'))
            ->pluck('current_stock', 'product_id');

        $consumptions = $order->consumptions->map(function ($c) use ($converter, $stock, $closed) {
            $quantity = (float) $c->quantity;
            $required = $converter->toProductUnit((int) $c->product_id, (int) $c->unit_id, $quantity);
            $available = isset($stock[$c->product_id]) ? (float) $stock[$c->product_id] : 0.0;

            return [
                'code' => $c->product?->code,
                'name' => $c->product?->name ?? 'Producto',
                'unit' => $c->unit?->abbreviation ?? $c->product?->unit?->abbreviation,
                'quantity' => $quantity,
                'expected' => $c->expected_quantity !== null ? (float) $c->expected_quantity : null,
                'variance' => $this->variance($quantity, $c->expected_quantity),
                'stock' => $available,
                'stock_unit' => $c->product?->unit?->abbreviation,
                // Solo importa antes de producir: después ya se descontó.
                'short' => !$closed && $required !== null && $available + 0.00005 < $required,
                'notes' => $c->notes,
            ];
        })->all();

        $outputs = $order->outputs->map(fn($o) => [
            'code' => $o->product?->code,
            'name' => $o->product?->name ?? 'Producto',
            'unit' => $o->unit?->abbreviation ?? $o->product?->unit?->abbreviation,
            'quantity' => (float) $o->quantity,
            'expected' => $o->expected_quantity !== null ? (float) $o->expected_quantity : null,
            'variance' => $this->variance((float) $o->quantity, $o->expected_quantity),
            'notes' => $o->notes,
        ])->all();

        return [
            'order' => $order,
            'company' => Company::find($order->company_id),
            'status' => $status,
            'closed' => $closed,
            'draft' => $status === ProductionOrderStatus::DRAFT,
            'consumptions' => $consumptions,
            'outputs' => $outputs,
            'wastes' => $closed ? $this->registeredWastes() : $this->plannedWastes(),
            'packagings' => $order->packagings->map(fn($p) => [
                'name' => $p->product?->name ?? 'Producto',
                'quantity' => (float) $p->quantity,
                'unit' => $p->unit?->abbreviation,
                'source' => $p->sourceProduct?->name,
                'source_quantity' => $p->source_quantity !== null ? (float) $p->source_quantity : null,
                'source_unit' => $p->sourceUnit?->abbreviation,
                'materials' => $p->materials
                    ->map(fn($m) => F::qty((float) $m->quantity) . ' ' . ($m->product?->name ?? 'material'))
                    ->implode(', '),
                'packed_at' => $p->packed_at,
            ])->all(),
            'planned_packagings' => $this->plannedPackagings(),
            'is_packaging' => $order->isPackaging(),
            'yields' => $closed ? app(ProductionOrderService::class)->yields($order) : [],
            'short_count' => count(array_filter($consumptions, fn($c) => $c['short'])),
        ];
    }

    /** Diferencia contra lo esperado, como fracción (0.05 = 5 % más). */
    private function variance(float $actual, mixed $expected): ?float
    {
        return $expected !== null && (float) $expected > 0 ? ($actual - (float) $expected) / (float) $expected : null;
    }

    /** Lo que se va a envasar al registrar el pendiente. */
    private function plannedPackagings(): array
    {
        $lines = $this->order->planned_packagings ?? [];
        if (!$lines) {
            return [];
        }

        $products = Product::with(['unit', 'packagingSourceProduct', 'packagingSourceUnit'])
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        return array_map(function ($line) use ($products) {
            $product = $products->get($line['product_id']);

            return [
                'name' => $product?->name ?? 'Producto',
                'quantity' => (float) $line['quantity'],
                'unit' => $product?->unit?->abbreviation,
                'source' => $product?->packagingSourceProduct?->name,
                'source_quantity' => $product ? (float) $product->packaging_source_quantity * (float) $line['quantity'] : null,
                'source_unit' => $product?->packagingSourceUnit?->abbreviation,
            ];
        }, $lines);
    }

    /** Las mermas que se capturaron en el borrador (todavía no afectan stock). */
    private function plannedWastes(): array
    {
        $wastes = $this->order->getAttribute('wastes') ?? [];
        if (!$wastes) {
            return [];
        }

        $products = Product::whereIn('id', array_column($wastes, 'product_id'))->pluck('name', 'id');
        $units = Unit::whereIn('id', array_column($wastes, 'unit_id'))->pluck('abbreviation', 'id');

        return array_map(fn($w) => [
            'name' => $products[$w['product_id']] ?? 'Producto',
            'quantity' => (float) $w['quantity'],
            'unit' => $units[$w['unit_id'] ?? 0] ?? null,
            'reason' => ProductionOrderService::WASTE_REASONS[$w['reason'] ?? 'other'] ?? 'Otro',
            'notes' => $w['notes'] ?? null,
        ], $wastes);
    }

    /** Las mermas que quedaron registradas como ajuste al completar. */
    private function registeredWastes(): array
    {
        return $this->order->getRelation('wastes')->map(fn($w) => [
            'name' => $w->product?->name ?? 'Producto',
            'quantity' => (float) $w->quantity,
            'unit' => $w->unit?->abbreviation,
            'reason' => null,
            // La nota lleva el motivo: "[Merma de producción · Daño · folio …] …".
            'notes' => $w->notes,
        ])->all();
    }
}
