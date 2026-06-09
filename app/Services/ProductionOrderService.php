<?php

namespace App\Services;

use App\Enums\AdjustmentReasonCode;
use App\Enums\ProductionOrderStatus;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Operations\Formula;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderConsumption;
use App\Models\Operations\ProductionOrderOutput;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Servicio central para gestión de Órdenes de Producción.
 *
 * Concentra toda la lógica transaccional: creación de borradores, edición,
 * completación (que impacta stock y kardex), cancelación (que revierte
 * movimientos) y reportería de KPIs en tiempo real.
 */
class ProductionOrderService
{
    public function __construct(private readonly MovementService $movementService) {}

    /**
     * Crea una orden en estado DRAFT sin afectar inventario.
     */
    public function createDraft(array $data): ProductionOrder
    {
        return DB::transaction(function () use ($data) {
            $company = CurrentCompany::get();
            $location = CurrentLocation::get();

            $user = Auth::user();
            $userId = $user?->id ?? $data['responsible_user_id'] ?? null;

            $order = ProductionOrder::create([
                'company_id' => $company?->id,
                'location_id' => $data['location_id'] ?? $location?->id,
                'formula_id' => $data['formula_id'] ?? null,
                'folio' => ProductionOrder::nextFolio($company?->id),
                'production_date' => $data['production_date'] ?? Carbon::today()->toDateString(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $userId,
                'status' => ProductionOrderStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
                'wastes' => $this->normalizeWastes($data['wastes'] ?? []),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $this->syncConsumptions($order, $data['consumptions'] ?? []);
            $this->syncOutputs($order, $data['outputs'] ?? []);

            $this->recalculateTotals($order);

            return $order;
        });
    }

    /**
     * Actualiza una orden en DRAFT. No aplica a estados completed/cancelled.
     */
    public function updateDraft(ProductionOrder $order, array $data): ProductionOrder
    {
        if ($order->status !== ProductionOrderStatus::DRAFT) {
            throw new RuntimeException('Solo se pueden editar órdenes en borrador.');
        }

        return DB::transaction(function () use ($order, $data) {
            $order->fill([
                'formula_id' => array_key_exists('formula_id', $data) ? $data['formula_id'] : $order->formula_id,
                'production_date' => $data['production_date'] ?? $order->production_date->toDateString(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $order->responsible_user_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $order->notes,
                'wastes' => array_key_exists('wastes', $data) ? $this->normalizeWastes($data['wastes']) : $order->wastes,
                'updated_by' => Auth::id(),
            ])->save();

            if (array_key_exists('consumptions', $data)) {
                $this->syncConsumptions($order, $data['consumptions']);
            }
            if (array_key_exists('outputs', $data)) {
                $this->syncOutputs($order, $data['outputs']);
            }

            $this->recalculateTotals($order);

            return $order;
        });
    }

    /**
     * Completa la orden: descuenta consumos, suma productos, registra mermas
     * y marca la orden como COMPLETED. Todo en una sola transacción.
     */
    public function complete(ProductionOrder $order): ProductionOrder
    {
        $order->assertCanTransitionTo(ProductionOrderStatus::COMPLETED);

        return DB::transaction(function () use ($order) {
            $locationId = (int) $order->location_id;

            // 1. Descontar stock por cada consumo
            foreach ($order->consumptions()->with(['product', 'unit'])->get() as $consumption) {
                $this->movementService->decrement(
                    $locationId,
                    (int) $consumption->product_id,
                    (int) $consumption->unit_id,
                    (float) $consumption->quantity
                );
            }

            // 2. Acreditar stock por cada output
            foreach ($order->outputs()->with(['product', 'unit'])->get() as $output) {
                $this->movementService->increment(
                    $locationId,
                    (int) $output->product_id,
                    (int) $output->unit_id,
                    (float) $output->quantity
                );
            }

            // 3. Registrar mermas como ajustes de inventario
            $wastes = $order->wastes ?? [];
            foreach ($wastes as $waste) {
                $this->registerWaste($order, $waste);
            }

            // 4. Recalcular totales y transición de estado
            $this->recalculateTotals($order);
            $order->status = ProductionOrderStatus::COMPLETED;
            $order->completed_at = now();
            $order->updated_by = Auth::id();
            $order->save();

            return $order->fresh(['consumptions.product', 'outputs.product', 'wastes']);
        });
    }

    /**
     * Cancela la producción y revierte el impacto en stock.
     * Si está en DRAFT, simplemente la marca cancelada.
     * Si está en COMPLETED, invierte movimientos y marca ajustes como cancelados.
     */
    public function cancel(ProductionOrder $order): ProductionOrder
    {
        $order->assertCanTransitionTo(ProductionOrderStatus::CANCELLED);

        return DB::transaction(function () use ($order) {
            if ($order->status === ProductionOrderStatus::COMPLETED) {
                $locationId = (int) $order->location_id;

                // Revertir outputs (decrementar)
                foreach ($order->outputs()->get() as $output) {
                    $this->movementService->decrement(
                        $locationId,
                        (int) $output->product_id,
                        (int) $output->unit_id,
                        (float) $output->quantity
                    );
                }

                // Revertir consumptions (incrementar - devolver al stock)
                foreach ($order->consumptions()->get() as $consumption) {
                    $this->movementService->increment(
                        $locationId,
                        (int) $consumption->product_id,
                        (int) $consumption->unit_id,
                        (float) $consumption->quantity
                    );
                }

                // Marcar mermas (inventory_adjustment_details) como canceladas: revertimos stock
                foreach ($order->wastes()->get() as $waste) {
                    // Las mermas originalmente fueron 'out' — para revertir hacemos 'in'
                    $this->movementService->increment(
                        (int) $waste->location_id,
                        (int) $waste->product_id,
                        (int) $waste->unit_id,
                        (float) $waste->quantity
                    );

                    $waste->update([
                        'notes' => trim(($waste->notes ?? '') . "\n[CANCELADA] Ajuste revertido por cancelación de producción"),
                    ]);
                }
            }

            $order->status = ProductionOrderStatus::CANCELLED;
            $order->cancelled_at = now();
            $order->updated_by = Auth::id();
            $order->save();

            return $order->fresh(['consumptions.product', 'outputs.product', 'wastes']);
        });
    }

    /**
     * KPIs del día en una ubicación. Devuelve métricas genéricas
     * basadas en los datos reales de las producciones:
     * - productions_count_today: cantidad de órdenes completadas hoy
     * - total_consumed: total de líneas de consumo registradas
     * - total_produced: total de líneas de output registradas
     * - waste_percentage_today: merma promedio ponderada
     * - top_consumed: top 3 productos más consumidos (id, name, qty, unit)
     * - top_produced: top 3 productos más producidos (id, name, qty, unit)
     */
    public function getTodayStats(?int $companyId, ?int $locationId): array
    {
        $today = Carbon::today()->toDateString();

        $base = ProductionOrder::query()
            ->where('status', ProductionOrderStatus::COMPLETED)
            ->whereDate('production_date', $today)
            ->byCompany($companyId)
            ->byLocation($locationId);

        $orders = (clone $base)->get();
        $orderIds = $orders->pluck('id');

        $count = $orders->count();
        $totalConsumed = (float) $orders->sum('total_consumed_quantity');
        $totalProduced = (float) $orders->sum('total_produced_quantity');

        // El % de merma guardado en la orden sólo es comparable si todos los
        // productos están en la misma unidad base. Mostramos null en caso
        // contrario para evitar valores negativos engañosos.
        $wasteValues = $orders->pluck('waste_percentage')->filter(fn($v) => $v !== null)->values();
        $avgWaste = null;
        if ($wasteValues->isNotEmpty()) {
            $avg = (float) $wasteValues->avg();
            // Si el promedio da negativo (productos de distinta unidad base),
            // descartamos el indicador.
            $avgWaste = $avg < 0 ? null : round($avg, 2);
        }

        $topConsumed = [];
        $topProduced = [];
        $totalConsumptionLines = 0;
        $totalOutputLines = 0;

        if ($orderIds->isNotEmpty()) {
            $topConsumed = DB::table('production_order_consumptions as c')
                ->join('products as p', 'p.id', '=', 'c.product_id')
                ->leftJoin('units as u', 'u.id', '=', 'c.unit_id')
                ->whereIn('c.production_order_id', $orderIds)
                ->select(
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'c.unit_id',
                    'u.name as unit_name',
                    DB::raw('SUM(c.quantity) as total_quantity'),
                    DB::raw('COUNT(*) as lines_count'),
                )
                ->groupBy('p.id', 'p.name', 'p.code', 'c.unit_id', 'u.name')
                ->orderByDesc('total_quantity')
                ->limit(3)
                ->get()
                ->map(function ($r) {
                    return [
                        'product_id' => (int) $r->product_id,
                        'product_name' => $r->product_name,
                        'product_code' => $r->product_code,
                        'unit_id' => (int) $r->unit_id,
                        'unit_name' => $r->unit_name,
                        'quantity' => round((float) $r->total_quantity, 3),
                        'lines_count' => (int) $r->lines_count,
                    ];
                })->all();

            $topProduced = DB::table('production_order_outputs as o')
                ->join('products as p', 'p.id', '=', 'o.product_id')
                ->leftJoin('units as u', 'u.id', '=', 'o.unit_id')
                ->whereIn('o.production_order_id', $orderIds)
                ->select(
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'o.unit_id',
                    'u.name as unit_name',
                    DB::raw('SUM(o.quantity) as total_quantity'),
                    DB::raw('COUNT(*) as lines_count'),
                )
                ->groupBy('p.id', 'p.name', 'p.code', 'o.unit_id', 'u.name')
                ->orderByDesc('total_quantity')
                ->limit(3)
                ->get()
                ->map(function ($r) {
                    return [
                        'product_id' => (int) $r->product_id,
                        'product_name' => $r->product_name,
                        'product_code' => $r->product_code,
                        'unit_id' => (int) $r->unit_id,
                        'unit_name' => $r->unit_name,
                        'quantity' => round((float) $r->total_quantity, 3),
                        'lines_count' => (int) $r->lines_count,
                    ];
                })->all();

            $totalConsumptionLines = (int) DB::table('production_order_consumptions')
                ->whereIn('production_order_id', $orderIds)->count();
            $totalOutputLines = (int) DB::table('production_order_outputs')
                ->whereIn('production_order_id', $orderIds)->count();
        }

        return [
            'productions_count_today' => $count,
            'total_consumed_quantity' => round($totalConsumed, 3),
            'total_produced_quantity' => round($totalProduced, 3),
            'waste_percentage_today' => $avgWaste, // null si no es comparable
            'waste_percentage_available' => $avgWaste !== null,
            'consumption_lines' => $totalConsumptionLines,
            'output_lines' => $totalOutputLines,
            'top_consumed' => $topConsumed,
            'top_produced' => $topProduced,
        ];
    }

    /**
     * Compara lo realmente capturado contra lo esperado en la fórmula.
     */
    public function getVarianceReport(ProductionOrder $order): array
    {
        $order->load(['consumptions.product', 'outputs.product', 'formula.items.product']);
        $formulaItems = $order->formula?->items ?? collect();

        $consumptionVariance = $order->consumptions->map(function (ProductionOrderConsumption $c) use ($formulaItems) {
            $expected = $formulaItems->firstWhere('product_id', $c->product_id)?->expected_quantity;
            $variancePct = null;
            if ($expected !== null && (float) $expected > 0) {
                $variancePct = round((((float) $c->quantity - (float) $expected) / (float) $expected) * 100, 2);
            }
            return [
                'product_id' => $c->product_id,
                'product_name' => $c->product?->name,
                'actual' => (float) $c->quantity,
                'expected' => $expected !== null ? (float) $expected : null,
                'variance_pct' => $variancePct,
            ];
        })->values()->all();

        $outputVariance = $order->outputs->map(function (ProductionOrderOutput $o) {
            $expected = $o->expected_quantity;
            $variancePct = null;
            if ($expected !== null && (float) $expected > 0) {
                $variancePct = round((((float) $o->quantity - (float) $expected) / (float) $expected) * 100, 2);
            }
            return [
                'product_id' => $o->product_id,
                'product_name' => $o->product?->name,
                'actual' => (float) $o->quantity,
                'expected' => $expected !== null ? (float) $expected : null,
                'variance_pct' => $variancePct,
            ];
        })->values()->all();

        return [
            'consumptions' => $consumptionVariance,
            'outputs' => $outputVariance,
        ];
    }

    /**
     * Devuelve catálogos para la pantalla de captura.
     */
    public function getInitialData(?int $locationId): array
    {
        $companyId = CurrentCompany::get()?->id;

        $products = Product::query()
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->whereIn('product_type', ['raw_material', 'processed', 'commercial'])
            ->with('unit')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'product_type', 'unit_id']);

        $formulas = Formula::query()
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'product_id', 'version']);

        $units = Unit::query()
            ->orderBy('name')
            ->get(['id', 'name', 'unit_type', 'factor_to_base', 'base_unit_id']);

        $responsibles = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $wasteReasons = [
            ['value' => 'coconut_no_water', 'label' => 'Coco sin agua'],
            ['value' => 'coconut_no_pulp', 'label' => 'Coco sin pulpa'],
            ['value' => 'damaged_coconut', 'label' => 'Coco dañado'],
            ['value' => 'other', 'label' => 'Otro'],
        ];

        return [
            'statuses' => ProductionOrderStatus::options(),
            'waste_reasons' => $wasteReasons,
            'products' => $products,
            'formulas' => $formulas,
            'units' => $units,
            'responsibles' => $responsibles,
        ];
    }

    // -----------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------

    private function syncConsumptions(ProductionOrder $order, array $rows): void
    {
        $order->consumptions()->delete();
        foreach (array_values($rows) as $i => $row) {
            if (empty($row['product_id']) || !isset($row['quantity'])) {
                continue;
            }
            ProductionOrderConsumption::create([
                'production_order_id' => $order->id,
                'product_id' => (int) $row['product_id'],
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'quantity' => (float) $row['quantity'],
                'expected_quantity' => isset($row['expected_quantity']) ? (float) $row['expected_quantity'] : null,
                'sort_order' => $i,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    private function syncOutputs(ProductionOrder $order, array $rows): void
    {
        $order->outputs()->delete();
        foreach (array_values($rows) as $i => $row) {
            if (empty($row['product_id']) || !isset($row['quantity'])) {
                continue;
            }
            ProductionOrderOutput::create([
                'production_order_id' => $order->id,
                'product_id' => (int) $row['product_id'],
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'quantity' => (float) $row['quantity'],
                'expected_quantity' => isset($row['expected_quantity']) ? (float) $row['expected_quantity'] : null,
                'sort_order' => $i,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    private function recalculateTotals(ProductionOrder $order): void
    {
        $order->load(['consumptions', 'outputs']);
        $totalConsumed = (float) $order->consumptions->sum('quantity');
        $totalProduced = (float) $order->outputs->sum('quantity');

        $wastePct = 0.0;
        if ($totalConsumed > 0) {
            $wastePct = (($totalConsumed - $totalProduced) / $totalConsumed) * 100;
        }

        $order->total_consumed_quantity = $totalConsumed;
        $order->total_produced_quantity = $totalProduced;
        $order->waste_percentage = round($wastePct, 2);
        $order->save();
    }

    private function registerWaste(ProductionOrder $order, array $waste): void
    {
        if (empty($waste['product_id']) || !isset($waste['quantity'])) {
            return;
        }

        $productId = (int) $waste['product_id'];
        $unitId = (int) ($waste['unit_id'] ?? 0);
        $quantity = (float) $waste['quantity'];

        $previousStock = DB::table('product_location')
            ->where('product_id', $productId)
            ->where('location_id', $order->location_id)
            ->value('current_stock') ?? 0;

        // Descontar stock
        $this->movementService->decrement((int) $order->location_id, $productId, $unitId, $quantity);

        $newStock = DB::table('product_location')
            ->where('product_id', $productId)
            ->where('location_id', $order->location_id)
            ->value('current_stock') ?? 0;

        $reasonMap = [
            'coconut_no_water' => 'loss',
            'coconut_no_pulp' => 'loss',
            'damaged_coconut' => 'damage',
            'other' => 'other',
        ];
        $internalReason = $reasonMap[$waste['reason'] ?? 'other'] ?? 'other';
        $reasonEnum = $internalReason === 'loss'
            ? AdjustmentReasonCode::LOSS
            : ($internalReason === 'damage' ? AdjustmentReasonCode::DAMAGE : AdjustmentReasonCode::OTHER);

        InventoryAdjustmentDetail::create([
            'company_id' => $order->company_id,
            'location_id' => $order->location_id,
            'created_by' => Auth::id(),
            'product_id' => $productId,
            'direction' => 'out',
            'quantity' => $quantity,
            'unit_id' => $unitId,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'reason_code' => AdjustmentReasonCode::PRODUCTION_WASTE,
            'notes' => sprintf(
                '[Merma de producción · %s · folio %s] %s',
                $reasonEnum->label(),
                $order->folio,
                $waste['notes'] ?? ''
            ),
            'reference_id' => $order->id,
            'reference_type' => 'production_order',
            'applied_at' => now(),
        ]);
    }

    /**
     * Limpia y normaliza el array de mermas que viene del frontend.
     */
    private function normalizeWastes(array $wastes): array
    {
        return array_values(array_filter(array_map(function ($w) {
            if (empty($w['product_id']) || empty($w['quantity']) || (float) $w['quantity'] <= 0) {
                return null;
            }
            return [
                'product_id' => (int) $w['product_id'],
                'unit_id' => (int) ($w['unit_id'] ?? 0),
                'quantity' => (float) $w['quantity'],
                'reason' => $w['reason'] ?? 'other',
                'notes' => $w['notes'] ?? null,
            ];
        }, $wastes)));
    }
}
