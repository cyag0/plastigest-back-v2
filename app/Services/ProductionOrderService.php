<?php

namespace App\Services;

use App\Enums\AdjustmentReasonCode;
use App\Enums\ProductionOrderStatus;
use App\Enums\StockLedgerReason;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryType;
use App\Models\Operations\Formula;
use App\Models\Operations\FormulaOutput;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderConsumption;
use App\Models\Operations\ProductionOrderOutput;
use App\Support\StockContext;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Servicio central de producción.
 *
 * Una orden es una producción (`process`: cocos → agua y pulpa, y en el mismo
 * registro su envasado) o un envasado posterior (`packaging`: el agua que
 * quedó en la mañana se embotella en la tarde). Concentra la lógica
 * transaccional: borradores ("Pendiente"), registro (que mueve stock y kárdex),
 * cancelación (que lo revierte) y el rendimiento de cada producción.
 */
class ProductionOrderService
{
    /** Motivos de merma que se capturan en la orden. */
    public const WASTE_REASONS = [
        'damage' => 'Producto dañado',
        'quality' => 'Defecto de calidad',
        'expired' => 'Caducado / Vencido',
        'process_loss' => 'Merma de proceso',
        'spill' => 'Derrame / Desperdicio',
        'other' => 'Otro',
    ];

    public function __construct(
        private readonly MovementService $movementService,
        private readonly ProductionPackagingService $packagingService,
    ) {}

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

            $kind = ($data['kind'] ?? null) === ProductionOrder::KIND_PACKAGING
                ? ProductionOrder::KIND_PACKAGING
                : ProductionOrder::KIND_PROCESS;
            $isPackaging = $kind === ProductionOrder::KIND_PACKAGING;

            $order = ProductionOrder::create([
                'company_id' => $company?->id,
                'location_id' => $data['location_id'] ?? $location?->id,
                // Un envasado no corre un proceso: no lleva fórmula.
                'formula_id' => $isPackaging ? null : ($data['formula_id'] ?? null),
                'kind' => $kind,
                'folio' => ProductionOrder::nextFolio($company?->id),
                'production_date' => $data['production_date'] ?? Carbon::today()->toDateString(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $userId,
                'status' => ProductionOrderStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
                'wastes' => $this->normalizeWastes($data['wastes'] ?? []),
                'planned_packagings' => $this->packagingService->normalize($data['packagings'] ?? []) ?: null,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            // Un envasado sólo tiene empaques: el granel y el material salen de
            // la receta de cada presentación.
            $this->syncConsumptions($order, $isPackaging ? [] : ($data['consumptions'] ?? []));
            $this->syncOutputs($order, $isPackaging ? [] : ($data['outputs'] ?? []));

            $this->recalculateTotals($order);

            return $order;
        });
    }

    /**
     * Actualiza una orden en DRAFT. No aplica a estados completed/cancelled.
     */
    public function updateDraft(ProductionOrder $order, array $data): ProductionOrder
    {
        return DB::transaction(function () use ($order, $data) {
            // Si otra petición completó o canceló la orden mientras tanto, sus
            // renglones ya movieron stock: editarlos dejaría la orden diciendo
            // algo distinto de lo que realmente se descontó.
            $order = $this->lockOrder($order);

            if ($order->status !== ProductionOrderStatus::DRAFT) {
                throw new BusinessRuleException('Solo se pueden editar órdenes en borrador.', 'invalid_status');
            }

            $isPackaging = $order->isPackaging();

            $order->fill([
                'formula_id' => $isPackaging
                    ? null
                    : (array_key_exists('formula_id', $data) ? $data['formula_id'] : $order->formula_id),
                'production_date' => $data['production_date'] ?? $order->production_date->toDateString(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $order->responsible_user_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $order->notes,
                'wastes' => array_key_exists('wastes', $data) ? $this->normalizeWastes($data['wastes'] ?? []) : $order->wastes,
                'planned_packagings' => array_key_exists('packagings', $data)
                    ? ($this->packagingService->normalize($data['packagings'] ?? []) ?: null)
                    : $order->planned_packagings,
                'updated_by' => Auth::id(),
            ])->save();

            if (!$isPackaging && array_key_exists('consumptions', $data)) {
                $this->syncConsumptions($order, $data['consumptions'] ?? []);
            }
            if (!$isPackaging && array_key_exists('outputs', $data)) {
                $this->syncOutputs($order, $data['outputs'] ?? []);
            }

            $this->recalculateTotals($order);

            return $order;
        });
    }

    /**
     * Registra la orden: descuenta consumos, suma productos, registra mermas,
     * aplica el envasado y la marca como COMPLETED. Todo en una sola
     * transacción: si algo no alcanza no se mueve nada.
     */
    public function complete(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            // El estado se valida sobre la fila bloqueada, no sobre el modelo
            // cargado antes: dos "completar" simultáneos (doble toque, reintento
            // tras un timeout) descontaban los insumos y sumaban el producto dos
            // veces. Ahora el segundo espera aquí y ve la orden ya completada.
            $order = $this->lockOrder($order);
            $order->assertCanTransitionTo(ProductionOrderStatus::COMPLETED);
            $this->assertHasSomethingToRegister($order);

            $locationId = (int) $order->location_id;

            // 1. Descontar stock por cada consumo
            foreach ($order->consumptions()->with(['product', 'unit'])->get() as $consumption) {
                $this->movementService->decrement(
                    $locationId,
                    (int) $consumption->product_id,
                    (int) $consumption->unit_id,
                    (float) $consumption->quantity,
                    null,
                    StockContext::for(StockLedgerReason::PRODUCTION_CONSUMPTION, $order)
                );
            }

            // 2. Acreditar stock por cada output
            foreach ($order->outputs()->with(['product', 'unit'])->get() as $output) {
                $this->movementService->increment(
                    $locationId,
                    (int) $output->product_id,
                    (int) $output->unit_id,
                    (float) $output->quantity,
                    null,
                    StockContext::for(StockLedgerReason::PRODUCTION_OUTPUT, $order)
                );
            }

            // 3. Registrar mermas como ajustes de inventario
            $wastes = $order->getAttribute('wastes') ?? [];
            foreach ($wastes as $waste) {
                $this->registerWaste($order, $waste);
            }

            // 4. Envasar: granel + material → producto terminado. En una
            // producción, con el granel que acaba de salir; en un envasado,
            // con el del inventario.
            $this->packagingService->apply($order, $order->planned_packagings ?? []);

            // 5. Recalcular totales y transición de estado
            $this->recalculateTotals($order);
            $order->planned_packagings = null;
            $order->status = ProductionOrderStatus::COMPLETED;
            $order->completed_at = now();
            $order->updated_by = Auth::id();
            $order->save();

            return $order->fresh(['consumptions.product', 'outputs.product', 'wastes', 'packagings']);
        });
    }

    /**
     * Cancela la orden y revierte el impacto en stock.
     * Si está pendiente (borrador), simplemente la marca cancelada.
     * Si ya afectó inventario, deshace primero el envasado, después las mermas
     * y al final lo producido y lo consumido.
     */
    public function cancel(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            // Mismo motivo que en complete(): sin el bloqueo, dos cancelaciones
            // simultáneas revertían el stock dos veces.
            $order = $this->lockOrder($order);
            $order->assertCanTransitionTo(ProductionOrderStatus::CANCELLED);

            if ($order->status->isProduced()) {
                $locationId = (int) $order->location_id;

                // Revertir primero el empaque: devuelve el granel y el material
                // de empaque, y retira el producto terminado. Tiene que ir antes
                // de revertir los outputs para que el granel exista al restarlo.
                $this->packagingService->revertAll($order);

                // Mermas (inventory_adjustment_details): se devuelve su salida y
                // quedan marcadas como revertidas (content.reverted_at). Una merma
                // ya revertida no se vuelve a revertir, y los ajustes generados
                // por producción no se editan ni eliminan desde Ajustes (antes,
                // eliminarla ahí después de cancelar la revertía otra vez).
                // Van antes que los productos: el sobrante de granel que se tiró
                // tiene que volver antes de retirar lo producido, si no faltaría
                // granel para retirarlo.
                foreach ($order->wastes()->lockForUpdate()->get() as $waste) {
                    if (!empty($waste->content['reverted_at'])) {
                        continue;
                    }

                    // Las mermas originalmente fueron 'out' — para revertir hacemos 'in'
                    $this->movementService->increment(
                        (int) $waste->location_id,
                        (int) $waste->product_id,
                        (int) $waste->unit_id,
                        (float) $waste->quantity,
                        null,
                        StockContext::for(StockLedgerReason::ADJUSTMENT, $order, 'Reversa de merma por cancelación')
                    );

                    $waste->update([
                        'notes' => trim(($waste->notes ?? '') . "\n[CANCELADA] Ajuste revertido por cancelación de producción"),
                        'content' => array_merge($waste->content ?? [], [
                            'reverted_at' => now()->toIso8601String(),
                            'reverted_by' => Auth::id(),
                            'reverted_reason' => 'production_order_cancelled',
                        ]),
                    ]);
                }

                // Revertir outputs (decrementar)
                foreach ($order->outputs()->get() as $output) {
                    $this->movementService->decrement(
                        $locationId,
                        (int) $output->product_id,
                        (int) $output->unit_id,
                        (float) $output->quantity,
                        null,
                        StockContext::for(StockLedgerReason::PRODUCTION_OUTPUT, $order, 'Cancelación de la orden')
                    );
                }

                // Revertir consumptions (incrementar - devolver al stock)
                foreach ($order->consumptions()->get() as $consumption) {
                    $this->movementService->increment(
                        $locationId,
                        (int) $consumption->product_id,
                        (int) $consumption->unit_id,
                        (float) $consumption->quantity,
                        null,
                        StockContext::for(StockLedgerReason::PRODUCTION_CONSUMPTION, $order, 'Cancelación de la orden')
                    );
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

        // Una orden en empaque o empacada también se produjo hoy: antes salía
        // de los KPIs en cuanto se empezaba a empacar.
        $produced = array_values(array_filter(
            ProductionOrderStatus::cases(),
            fn(ProductionOrderStatus $status) => $status->isProduced()
        ));

        $base = ProductionOrder::query()
            ->whereIn('status', array_map(fn(ProductionOrderStatus $s) => $s->value, $produced))
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
     * Rendimiento de una producción: cuánto salió de cada producto por unidad
     * del insumo principal (litros de agua por coco, kilos de pulpa por coco),
     * comparado con lo que espera el proceso.
     *
     * El insumo principal es el primer ingrediente del proceso que se haya
     * usado; sin proceso, el primer consumo. Todo se compara en la unidad base
     * de cada producto, así que da igual si se capturó en litros o mililitros.
     * Un envasado no tiene rendimiento.
     *
     * @return array<int, array<string, mixed>>
     */
    public function yields(ProductionOrder $order): array
    {
        if ($order->isPackaging()) {
            return [];
        }

        $order->loadMissing(['consumptions.product.unit', 'outputs.product.unit', 'formula.items', 'formula.outputs']);
        if ($order->consumptions->isEmpty() || $order->outputs->isEmpty()) {
            return [];
        }

        $reference = null;
        $referenceItem = null;
        foreach ($order->formula?->items ?? [] as $item) {
            $reference = $order->consumptions->firstWhere('product_id', $item->product_id);
            if ($reference) {
                $referenceItem = $item;
                break;
            }
        }
        $reference ??= $order->consumptions->first();

        $referenceBase = $this->toBase((int) $reference->product_id, (int) $reference->unit_id, (float) $reference->quantity);
        if ($referenceBase <= 0) {
            return [];
        }
        $referenceItemBase = $referenceItem
            ? $this->toBase((int) $referenceItem->product_id, (int) $referenceItem->unit_id, (float) $referenceItem->expected_quantity)
            : 0.0;

        return $order->outputs->map(function (ProductionOrderOutput $output) use ($order, $reference, $referenceBase, $referenceItemBase) {
            $outputBase = $this->toBase((int) $output->product_id, (int) $output->unit_id, (float) $output->quantity);
            $perInput = $outputBase / $referenceBase;

            $expectedPerInput = null;
            $formulaOutput = $order->formula?->outputs->firstWhere('product_id', $output->product_id);
            if ($formulaOutput && $referenceItemBase > 0 && (float) $formulaOutput->expected_quantity > 0) {
                $expectedPerInput = $this->toBase(
                    (int) $formulaOutput->product_id,
                    (int) $formulaOutput->unit_id,
                    (float) $formulaOutput->expected_quantity
                ) / $referenceItemBase;
            }

            return [
                'product_id' => (int) $output->product_id,
                'product_name' => $output->product?->name,
                'quantity' => round($outputBase, 4),
                'unit_abbreviation' => $output->product?->unit?->abbreviation,
                'input_product_id' => (int) $reference->product_id,
                'input_product_name' => $reference->product?->name,
                'input_quantity' => round($referenceBase, 4),
                'input_unit_abbreviation' => $reference->product?->unit?->abbreviation,
                'per_input' => round($perInput, 4),
                'expected_per_input' => $expectedPerInput !== null ? round($expectedPerInput, 4) : null,
                'variance_pct' => $expectedPerInput ? round((($perInput - $expectedPerInput) / $expectedPerInput) * 100, 1) : null,
            ];
        })->values()->all();
    }

    /**
     * Catálogos de la pantalla de registro: los procesos activos con sus
     * insumos y resultados (y la existencia de la sucursal), los graneles que
     * se envasan con sus presentaciones, los responsables y los motivos de
     * merma.
     */
    public function getInitialData(?int $locationId): array
    {
        $companyId = CurrentCompany::get()?->id;

        $formulas = Formula::query()
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->with([
                'items.product.unit', 'items.product.inventoryType', 'items.unit',
                'outputs.product.unit', 'outputs.product.inventoryType', 'outputs.unit',
            ])
            ->orderBy('name')
            ->get();

        $bulks = $this->packagingService->bulkCatalog($companyId, $locationId);
        $bulkIds = array_column($bulks, 'product_id');

        $productIds = $formulas
            ->flatMap(fn(Formula $f) => $f->items->pluck('product_id')->merge($f->outputs->pluck('product_id')))
            ->unique()
            ->all();
        $stock = $locationId && $productIds
            ? DB::table('product_location')
                ->where('location_id', $locationId)
                ->whereIn('product_id', $productIds)
                ->pluck('current_stock', 'product_id')
                ->map(fn($value) => (float) $value)
                ->all()
            : [];

        $line = fn($row) => [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product?->name,
            'product_code' => $row->product?->code,
            'unit_id' => (int) $row->unit_id,
            'unit_abbreviation' => $row->unit?->abbreviation,
            'unit_type' => $row->unit?->unit_type,
            'expected_quantity' => (float) $row->expected_quantity,
            'stock' => $stock[$row->product_id] ?? 0.0,
            'stock_unit_abbreviation' => $row->product?->unit?->abbreviation,
            'inventory_type_code' => $row->product?->inventoryType?->code,
        ];

        $processes = $formulas->map(fn(Formula $formula) => [
            'id' => (int) $formula->id,
            'name' => $formula->name,
            'description' => $formula->description,
            'items' => $formula->items->map($line)->values()->all(),
            'outputs' => $formula->outputs->map(fn(FormulaOutput $output) => $line($output) + [
                'is_primary' => (bool) $output->is_primary,
                'is_bulk' => $output->product?->inventoryType?->code === InventoryType::SEMI_FINISHED,
                'has_presentations' => in_array((int) $output->product_id, $bulkIds, true),
            ])->values()->all(),
        ])->values()->all();

        $wasteReasons = array_map(
            fn($value, $label) => ['value' => $value, 'label' => $label],
            array_keys(self::WASTE_REASONS),
            self::WASTE_REASONS,
        );

        return [
            'processes' => $processes,
            'bulks' => $bulks,
            'responsibles' => $this->responsibles($companyId, $locationId),
            'waste_reasons' => $wasteReasons,
            'statuses' => ProductionOrderStatus::options(),
        ];
    }

    /**
     * Responsables que se pueden elegir en una orden de la sucursal: usuarios
     * activos de la empresa que pueden entrar a ella (asignados, o con acceso
     * a todas las sucursales). Sin sucursal, todos los de la empresa.
     *
     * Antes salían todos los usuarios activos de todas las empresas, con su
     * correo.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function responsibles(?int $companyId, ?int $locationId): array
    {
        if (!$companyId) {
            return [];
        }

        $users = User::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('user_company')->where('company_id', $companyId)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($locationId) {
            $assigned = DB::table('user_location_roles')
                ->where('location_id', $locationId)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();

            $users = $users->filter(
                fn(User $user) => in_array((int) $user->id, $assigned, true)
                    || CurrentWorker::canAccessLocation($locationId, $user)
            );
        }

        return $users->map(fn(User $user) => ['id' => (int) $user->id, 'name' => $user->name])->values()->all();
    }

    // -----------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------

    /**
     * Relee la orden bloqueando su fila hasta que termine la transacción.
     *
     * Las transiciones que mueven stock tienen que decidir con el estado real
     * de la fila: el modelo que llega se cargó antes de la transacción y otra
     * petición pudo haberlo cambiado. Hay que llamarlo dentro de
     * DB::transaction(), si no el bloqueo se suelta de inmediato.
     */
    private function lockOrder(ProductionOrder $order): ProductionOrder
    {
        return ProductionOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }

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

    /**
     * Totales de la orden. Sólo se suman cantidades de la misma clase (todo
     * en kilos, todo en litros): sumar cocos con litros daba "merma" de
     * −566 %. Con insumos y resultados de clases distintas no hay total ni
     * porcentaje de merma; para eso está el rendimiento (yields()).
     */
    private function recalculateTotals(ProductionOrder $order): void
    {
        $order->load(['consumptions.product.unit', 'outputs.product.unit']);

        [$consumed, $consumedType] = $this->sumInBaseUnits($order->consumptions);
        [$produced, $producedType] = $this->sumInBaseUnits($order->outputs);

        $order->total_consumed_quantity = $consumedType !== null ? round($consumed, 3) : null;
        $order->total_produced_quantity = $producedType !== null ? round($produced, 3) : null;

        $comparable = $consumedType !== null && $consumedType === $producedType && $consumed > 0;
        $order->waste_percentage = $comparable
            ? round(max(-999.99, min(999.99, (($consumed - $produced) / $consumed) * 100)), 2)
            : null;
        $order->save();
    }

    /**
     * Suma de renglones en la unidad base de cada producto y la clase de
     * unidad común (mass, volume, quantity), o null si se mezclan clases.
     *
     * @return array{0: float, 1: ?string}
     */
    private function sumInBaseUnits($rows): array
    {
        if ($rows->isEmpty()) {
            return [0.0, null];
        }

        $sum = 0.0;
        $type = null;
        foreach ($rows as $row) {
            $rowType = $row->product?->unit?->unit_type;
            if ($rowType === null || ($type !== null && $rowType !== $type)) {
                return [0.0, null];
            }
            $type = $rowType;
            $sum += $this->toBase((int) $row->product_id, (int) $row->unit_id, (float) $row->quantity);
        }

        return [$sum, $type];
    }

    /**
     * Una orden sin nada que mover no se registra: una producción necesita al
     * menos un insumo o un resultado, y un envasado al menos una presentación.
     */
    private function assertHasSomethingToRegister(ProductionOrder $order): void
    {
        if ($order->isPackaging()) {
            if (!$this->packagingService->normalize($order->planned_packagings ?? [])) {
                throw new BusinessRuleException('Indica cuánto se envasó de al menos una presentación.', 'nothing_to_pack');
            }

            return;
        }

        if (!$order->consumptions()->exists() && !$order->outputs()->exists()) {
            throw new BusinessRuleException('Indica qué se usó y qué salió antes de registrar la producción.', 'nothing_to_register');
        }
    }

    /** Cantidad en la unidad base del producto; con una unidad incompatible, tal cual. */
    private function toBase(int $productId, int $unitId, float $quantity): float
    {
        if ($quantity <= 0 || !$productId || !$unitId) {
            return 0.0;
        }

        try {
            return $this->movementService->quantityInProductUnit($productId, $unitId, $quantity);
        } catch (\Throwable) {
            return $quantity;
        }
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
        $this->movementService->decrement(
            (int) $order->location_id,
            $productId,
            $unitId,
            $quantity,
            null,
            StockContext::for(StockLedgerReason::ADJUSTMENT, $order, 'Merma de producción')
        );

        $newStock = DB::table('product_location')
            ->where('product_id', $productId)
            ->where('location_id', $order->location_id)
            ->value('current_stock') ?? 0;

        $reasonMap = [
            'damage' => AdjustmentReasonCode::DAMAGE,
            'quality' => AdjustmentReasonCode::DAMAGE,
            'expired' => AdjustmentReasonCode::EXPIRY,
            'process_loss' => AdjustmentReasonCode::LOSS,
            'spill' => AdjustmentReasonCode::LOSS,
            'other' => AdjustmentReasonCode::OTHER,
        ];
        $reasonEnum = $reasonMap[$waste['reason'] ?? 'other'] ?? AdjustmentReasonCode::OTHER;

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
