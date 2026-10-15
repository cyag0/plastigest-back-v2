<?php

namespace App\Services\Replenishment;

use App\Models\InventoryTransfer;
use App\Models\Operations\ProductionOrder;
use App\Reports\ProductCosts;
use App\Reports\QuantityConverter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sugerencias de reabastecimiento: qué necesita cada sucursal para lo que
 * queda de hoy y los días que se quieren cubrir, de dónde mandarlo y qué hay
 * que comprar o producir porque no alcanza entre todas.
 *
 * Por sucursal y producto:
 *
 *   necesita  = lo que falta de vender hoy + el pronóstico de los días a cubrir + margen
 *   tiene     = existencia − apartado + lo que viene en camino − lo que ya se comprometió a mandar
 *   le falta  = necesita − tiene  (si es positivo)
 *   le sobra  = tiene − necesita  (si es positivo; es lo que puede mandar a otras)
 *
 * El margen cubre la variación normal de la venta (una desviación estándar
 * por la raíz de los días a cubrir), pero nunca es mayor que lo que se espera
 * vender: en lo que se vende poco y disparejo un margen enorme termina en
 * merma. Lo que casi no se vende no se sugiere. El mínimo/máximo de
 * existencia, si se capturaron, se respetan.
 *
 * Lo que falta se cubre primero con traspasos desde quien suele surtir a esa
 * sucursal, luego desde el centro de distribución y luego desde cualquier
 * sucursal a la que le sobre. Lo que ni así alcanza se sugiere comprar
 * (agrupado por proveedor) o producir, en la sucursal que suele hacerlo.
 *
 * Todo va en la unidad base del producto, como el libro de existencias.
 */
final class ReplenishmentPlanner
{
    /** Margen en desviaciones estándar: 1 ≈ el 84 % de los días alcanza. */
    private const SAFETY_Z = 1.0;

    /**
     * Lo que salió en menos de estos días (y en menos del 10 % del historial)
     * se vende tan de vez en cuando que no se pronostica: se pide a mano.
     */
    private const MIN_SELLING_DAYS = 3;

    /** Historial para saber quién surte, compra o produce cada producto. */
    private const ROUTES_LOOKBACK_DAYS = 180;

    /** Temporada: días antes y después de las fechas a cubrir, el año pasado. */
    private const SEASON_WINDOW_DAYS = 7;

    /** Temporada: semanas previas del año pasado contra las que se compara. */
    private const SEASON_BASE_DAYS = 28;

    /** Temporada: días con venta que hacen falta en cada ventana para usarla. */
    private const SEASON_MIN_DAYS = 7;

    private const OPEN_TRANSFER_STATUSES = ['pending', 'approved', 'in_transit'];

    private const OPEN_PURCHASE_STATUSES = ['ordered', 'in_transit'];

    private const EPSILON = 0.0001;

    private readonly CarbonImmutable $today;

    private readonly QuantityConverter $converter;

    /** @var array<int, object> */
    private array $locations = [];

    /** @var array<int, object> */
    private array $products = [];

    /** @var array<int, bool> producto => se mueve en fracciones */
    private array $fractional = [];

    public function __construct(
        private readonly int $companyId,
        ?CarbonInterface $now = null,
        private readonly int $coverageDays = 1,
        private readonly DemandForecaster $forecaster = new DemandForecaster(),
    ) {
        $this->today = CarbonImmutable::instance($now ?? now())->startOfDay();
        $this->converter = new QuantityConverter();
    }

    /**
     * @return array{
     *   today: string, coverage_days: int, horizon: array{from: string, to: string}, history_days: int,
     *   transfers: array, purchases: array, production: array, shortages: array
     * }
     */
    public function plan(): array
    {
        $this->loadCatalog();

        $history = $this->loadHistory();
        $firstDays = $this->loadFirstDays($history);
        [$incoming, $committed] = $this->loadOpenOrders();
        $season = $this->loadSeasonFactors();
        $routes = $this->loadRoutes();

        $positions = $this->positions($history, $firstDays, $incoming, $committed, $season);

        return $this->allocate($positions, $routes);
    }

    // ─── Datos ──────────────────────────────────────────────────────────────

    /**
     * Demanda de un renglón del libro mayor (alias sle, con la orden de
     * producción que lo originó como po): ventas y consumos de producción y
     * empaque, menos sus reversas.
     *
     * - Lo de una producción cancelada no cuenta (ni el consumo ni su
     *   reversa): la producción no ocurrió. Antes el consumo quedaba como
     *   demanda y la reversa no se restaba.
     * - Las reversas que no son de una producción cancelada (una venta
     *   cancelada, un empaque revertido) se restan el día en que ocurren.
     */
    private const DEMAND_SQL = "CASE
        WHEN po.status = 'cancelled' AND sle.reason IN ('production_consumption', 'packaging_consumption') THEN 0
        WHEN sle.direction = 'out' AND sle.reason IN ('sale', 'production_consumption', 'packaging_consumption') THEN sle.quantity
        WHEN sle.direction = 'in' AND sle.reason IN ('sale_return', 'production_consumption', 'packaging_consumption') THEN -sle.quantity
        ELSE 0 END";

    /**
     * Libro mayor de la empresa (alias sle) con la orden de producción de
     * cada renglón (alias po, si la hay), para DEMAND_SQL.
     */
    private function ledgerWithProductionOrders()
    {
        return DB::table('stock_ledger_entries as sle')
            ->leftJoin('production_orders as po', function ($join) {
                $join->on('po.id', '=', 'sle.reference_id')
                    ->where('sle.reference_type', '=', ProductionOrder::class);
            })
            ->where('sle.company_id', $this->companyId);
    }

    private function loadCatalog(): void
    {
        $this->locations = DB::table('locations')
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'is_warehouse'])
            ->keyBy('id')
            ->all();

        $this->products = DB::table('products')
            ->leftJoin('inventory_types', 'inventory_types.id', '=', 'products.inventory_type_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
            ->where('products.company_id', $this->companyId)
            ->get([
                'products.id', 'products.name', 'products.code', 'products.unit_id', 'products.supplier_id',
                'suppliers.name as supplier_name', 'units.abbreviation as unit_abbreviation', 'units.name as unit_name',
                'inventory_types.is_purchasable', 'inventory_types.is_producible', 'inventory_types.is_stockable',
            ])
            ->keyBy('id')
            ->all();

        $this->converter->loadProducts(array_keys($this->products));
    }

    /**
     * Por sucursal y producto: demanda, movimiento neto y existencia mínima de
     * cada día del historial, y la demanda de hoy hasta ahora.
     *
     * @return array<int, array<int, array{demand: array<string, float>, net: array<string, float>, min: array<string, float>}>>
     */
    private function loadHistory(): array
    {
        $rows = $this->ledgerWithProductionOrders()
            ->where('sle.created_at', '>=', $this->today->subDays(DemandForecaster::LOOKBACK_DAYS))
            ->where('sle.created_at', '<', $this->today->addDay())
            ->groupBy('sle.location_id', 'sle.product_id', DB::raw('DATE(sle.created_at)'))
            ->selectRaw(
                'sle.location_id, sle.product_id, DATE(sle.created_at) as day, SUM(' . self::DEMAND_SQL . ") as demand,
                 SUM(CASE WHEN sle.direction = 'in' THEN sle.quantity ELSE -sle.quantity END) as net,
                 MIN(sle.new_stock) as min_stock"
            )
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $location = (int) $row->location_id;
            $product = (int) $row->product_id;

            $history[$location][$product]['demand'][$day] = (float) $row->demand;
            $history[$location][$product]['net'][$day] = (float) $row->net;
            $history[$location][$product]['min'][$day] = (float) $row->min_stock;

            if (!$this->isWhole((float) $row->demand) || !$this->isWhole((float) $row->net)) {
                $this->fractional[$product] = true;
            }
        }

        return $history;
    }

    /**
     * Desde cuándo hay movimientos de cada producto en cada sucursal: antes de
     * eso no es que no se vendiera, es que no había datos.
     *
     * Solo importa si empezó dentro del historial (DemandForecaster usa el
     * primer día únicamente cuando es posterior al inicio del historial), así
     * que ya no se recorre todo el libro mayor de la empresa en cada consulta:
     * el primer día dentro del historial sale de lo ya cargado, y solo para
     * esos productos se pregunta si había movimientos antes (la primera
     * coincidencia basta, por el índice producto + sucursal).
     *
     * @param array<int, array<int, array>> $history de loadHistory()
     * @return array<int, array<int, CarbonImmutable>>
     */
    private function loadFirstDays(array $history): array
    {
        $windowStart = $this->today->subDays(DemandForecaster::LOOKBACK_DAYS);

        $candidates = [];
        foreach ($history as $location => $products) {
            foreach ($products as $product => $data) {
                $days = array_keys($data['net'] ?? []);
                if ($days === []) {
                    continue;
                }
                $first = CarbonImmutable::parse(min($days))->startOfDay();
                if ($first->greaterThan($windowStart)) {
                    $candidates[] = [(int) $location, (int) $product, $first];
                }
            }
        }

        $older = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            $pairs = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS location_id, ? AS product_id'));
            $bindings = [];
            foreach ($chunk as [$location, $product]) {
                $bindings[] = $location;
                $bindings[] = $product;
            }
            $bindings[] = $windowStart->toDateTimeString();

            $rows = DB::select(
                "SELECT c.location_id, c.product_id FROM ({$pairs}) c
                 WHERE EXISTS (
                     SELECT 1 FROM stock_ledger_entries s
                     WHERE s.product_id = c.product_id AND s.location_id = c.location_id AND s.created_at < ?
                 )",
                $bindings
            );
            foreach ($rows as $row) {
                $older[(int) $row->location_id][(int) $row->product_id] = true;
            }
        }

        $firstDays = [];
        foreach ($candidates as [$location, $product, $first]) {
            if (empty($older[$location][$product])) {
                $firstDays[$location][$product] = $first;
            }
        }

        return $firstDays;
    }

    /**
     * Lo que viene en camino a cada sucursal (traspasos pedidos o enviados y
     * compras pedidas) y lo que cada una ya se comprometió a mandar.
     *
     * @return array{0: array<int, array<int, float>>, 1: array<int, array<int, float>>}
     */
    private function loadOpenOrders(): array
    {
        $incoming = [];
        $committed = [];

        $transferLines = DB::table('inventory_transfer_details as d')
            ->join('inventory_transfers as t', 't.id', '=', 'd.transfer_id')
            ->where('t.company_id', $this->companyId)
            ->whereIn('t.status', self::OPEN_TRANSFER_STATUSES)
            ->get(['t.status', 't.from_location_id', 't.to_location_id', 'd.product_id', 'd.unit_id', 'd.package_id', 'd.quantity_requested', 'd.quantity_shipped']);

        foreach ($transferLines as $line) {
            $product = (int) $line->product_id;
            // Enviado: ya salió del origen y viene lo que se mandó. Pedido o
            // aprobado: sigue en el origen, apartado para el destino.
            $shipped = $line->status === 'in_transit';
            $quantity = $this->converter->toProductUnit(
                $product,
                $line->unit_id ? (int) $line->unit_id : null,
                (float) ($shipped ? $line->quantity_shipped : $line->quantity_requested),
                $line->package_id ? (int) $line->package_id : null,
            ) ?? 0.0;

            $incoming[(int) $line->to_location_id][$product] = ($incoming[(int) $line->to_location_id][$product] ?? 0) + $quantity;
            if (!$shipped) {
                $committed[(int) $line->from_location_id][$product] = ($committed[(int) $line->from_location_id][$product] ?? 0) + $quantity;
            }
        }

        $purchaseLines = DB::table('purchase_details as d')
            ->join('purchases as p', 'p.id', '=', 'd.purchase_id')
            ->where('p.company_id', $this->companyId)
            ->whereIn('p.status', self::OPEN_PURCHASE_STATUSES)
            ->get(['p.location_id', 'd.product_id', 'd.unit_id', 'd.package_id', 'd.quantity', 'd.quantity_received']);

        foreach ($purchaseLines as $line) {
            $pending = max(0.0, (float) $line->quantity - (float) ($line->quantity_received ?? 0));
            if ($pending <= 0) {
                continue;
            }
            $product = (int) $line->product_id;
            $quantity = $this->converter->toProductUnit(
                $product,
                $line->unit_id ? (int) $line->unit_id : null,
                $pending,
                $line->package_id ? (int) $line->package_id : null,
            ) ?? 0.0;

            $incoming[(int) $line->location_id][$product] = ($incoming[(int) $line->location_id][$product] ?? 0) + $quantity;
        }

        return [$incoming, $committed];
    }

    /**
     * Temporada por producto (toda la empresa): el año pasado, lo que se
     * vendió alrededor de las fechas a cubrir contra las 4 semanas previas a
     * "hoy" (las que equivalen al historial de este año). Si ese cambio fue
     * de +30 %, se espera lo mismo ahora. Sin datos suficientes del año
     * pasado vale 1.
     *
     * @return array<int, float>
     */
    private function loadSeasonFactors(): array
    {
        $lastYear = $this->today->subYear();
        $baseFrom = $lastYear->subDays(self::SEASON_BASE_DAYS);
        $targetFrom = $lastYear->addDay()->subDays(self::SEASON_WINDOW_DAYS);
        $targetTo = $lastYear->addDays($this->coverageDays + self::SEASON_WINDOW_DAYS);

        $rows = $this->ledgerWithProductionOrders()
            ->where('sle.created_at', '>=', $baseFrom)
            ->where('sle.created_at', '<', $targetTo->addDay())
            ->groupBy('sle.product_id', DB::raw('DATE(sle.created_at)'))
            ->selectRaw('sle.product_id, DATE(sle.created_at) as day, SUM(' . self::DEMAND_SQL . ') as demand')
            ->get();

        $base = [];
        $target = [];
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse(substr((string) $row->day, 0, 10));
            $demand = (float) $row->demand;
            if ($demand <= 0) {
                continue;
            }
            $product = (int) $row->product_id;
            if ($day->lessThan($lastYear)) {
                $base[$product][] = $demand;
            }
            if ($day->greaterThanOrEqualTo($targetFrom) && $day->lessThanOrEqualTo($targetTo)) {
                $target[$product][] = $demand;
            }
        }

        $baseDays = self::SEASON_BASE_DAYS;
        $targetDays = (int) $targetFrom->diffInDays($targetTo, true) + 1;

        $factors = [];
        foreach ($base as $product => $values) {
            if (count($values) < self::SEASON_MIN_DAYS || count($target[$product] ?? []) < self::SEASON_MIN_DAYS) {
                continue;
            }
            $baseAverage = array_sum($values) / $baseDays;
            $targetAverage = array_sum($target[$product]) / $targetDays;
            if ($baseAverage <= 0) {
                continue;
            }
            $factors[$product] = min(2.0, max(0.5, $targetAverage / $baseAverage));
        }

        return $factors;
    }

    /**
     * Quién suele surtir a quién y dónde se compra o produce cada producto,
     * según los últimos meses.
     *
     * @return array{
     *   sources: array<int, array<int, array<int, int>>>, sources_any: array<int, array<int, int>>,
     *   purchase_hub: array<int, int>, production_hub: array<int, int>, main_hub: ?int
     * }
     */
    private function loadRoutes(): array
    {
        $since = $this->today->subDays(self::ROUTES_LOOKBACK_DAYS);

        // Lo que regresa al origen al cancelar o rechazar una transferencia
        // también es una entrada "desde" el destino, pero no quiere decir que
        // el destino surta al origen: no cuenta.
        $received = $this->ledgerWithTransfers()
            ->where('sle.reason', 'transfer_in')
            ->whereNotNull('sle.counterpart_location_id')
            ->where('sle.created_at', '>=', $since)
            ->where(fn($query) => $query->whereNull('t.id')->orWhereColumn('t.from_location_id', '!=', 'sle.location_id'))
            ->groupBy('sle.location_id', 'sle.product_id', 'sle.counterpart_location_id')
            ->selectRaw('sle.location_id, sle.product_id, sle.counterpart_location_id, COUNT(*) as times')
            ->get();

        $sources = [];
        $sourcesAny = [];
        foreach ($received as $row) {
            $to = (int) $row->location_id;
            $from = (int) $row->counterpart_location_id;
            $sources[$to][(int) $row->product_id][$from] = (int) $row->times;
            $sourcesAny[$to][$from] = ($sourcesAny[$to][$from] ?? 0) + (int) $row->times;
        }

        // Un envío que terminó cancelado o rechazado (y regresó) no hace a
        // nadie centro de distribución.
        $origins = $this->ledgerWithTransfers()
            ->whereIn('sle.reason', ['purchase', 'production_output', 'packaging_output', 'transfer_out'])
            ->where('sle.created_at', '>=', $since)
            ->where(fn($query) => $query->where('sle.reason', '!=', 'transfer_out')
                ->orWhereNull('t.id')
                ->orWhereNotIn('t.status', ['cancelled', 'rejected']))
            ->groupBy('sle.product_id', 'sle.location_id', 'sle.reason')
            ->selectRaw('sle.product_id, sle.location_id, sle.reason, COUNT(*) as times')
            ->get();

        $purchaseCounts = [];
        $productionCounts = [];
        $shipments = [];
        foreach ($origins as $row) {
            $product = (int) $row->product_id;
            $location = (int) $row->location_id;
            match ($row->reason) {
                'purchase' => $purchaseCounts[$product][$location] = ($purchaseCounts[$product][$location] ?? 0) + (int) $row->times,
                'transfer_out' => $shipments[$location] = ($shipments[$location] ?? 0) + (int) $row->times,
                default => $productionCounts[$product][$location] = ($productionCounts[$product][$location] ?? 0) + (int) $row->times,
            };
        }

        $argmax = function (array $counts): ?int {
            if ($counts === []) {
                return null;
            }
            arsort($counts);

            return (int) array_key_first($counts);
        };

        // El centro de distribución: quien más envía, si no el almacén, si
        // no la primera sucursal.
        $mainHub = $argmax(array_intersect_key($shipments, $this->locations));
        if ($mainHub === null) {
            foreach ($this->locations as $location) {
                if ($location->is_warehouse) {
                    $mainHub = (int) $location->id;
                    break;
                }
            }
        }
        $mainHub ??= $this->locations === [] ? null : (int) array_key_first($this->locations);

        return [
            'sources' => $sources,
            'sources_any' => $sourcesAny,
            'purchase_hub' => array_filter(array_map(fn($counts) => $argmax(array_intersect_key($counts, $this->locations)), $purchaseCounts)),
            'production_hub' => array_filter(array_map(fn($counts) => $argmax(array_intersect_key($counts, $this->locations)), $productionCounts)),
            'main_hub' => $mainHub,
        ];
    }

    /**
     * Libro mayor de la empresa (alias sle) con la transferencia de cada
     * renglón (alias t, si la hay).
     */
    private function ledgerWithTransfers()
    {
        return DB::table('stock_ledger_entries as sle')
            ->leftJoin('inventory_transfers as t', function ($join) {
                $join->on('t.id', '=', 'sle.reference_id')
                    ->where('sle.reference_type', '=', InventoryTransfer::class);
            })
            ->where('sle.company_id', $this->companyId);
    }

    // ─── Necesidad por sucursal ─────────────────────────────────────────────

    /**
     * @return array<int, array<int, array>> producto => sucursal => posición
     */
    private function positions(array $history, array $firstDays, array $incoming, array $committed, array $season): array
    {
        $stockRows = DB::table('product_location')
            ->whereIn('location_id', array_keys($this->locations))
            ->get(['location_id', 'product_id', 'current_stock', 'reserved_stock', 'minimum_stock', 'maximum_stock', 'active']);

        $todayKey = $this->today->toDateString();
        $horizonDays = [];
        for ($i = 1; $i <= $this->coverageDays; $i++) {
            $horizonDays[] = $this->today->addDays($i);
        }

        $positions = [];
        foreach ($stockRows as $row) {
            $locationId = (int) $row->location_id;
            $productId = (int) $row->product_id;
            $product = $this->products[$productId] ?? null;
            // Lo que no se maneja en la sucursal o no lleva existencias no se pide.
            if (!$product || !$row->active || (isset($product->is_stockable) && !$product->is_stockable)) {
                continue;
            }

            $current = (float) $row->current_stock;
            $data = $history[$locationId][$productId] ?? null;

            $forecast = DemandForecast::none();
            $soldToday = 0.0;
            $stockoutStreak = 0;
            if ($data !== null) {
                $soldToday = max(0.0, (float) ($data['demand'][$todayKey] ?? 0));
                $demand = $data['demand'];
                unset($demand[$todayKey]);
                $stockouts = $this->stockoutDays($data, $current);

                // Días seguidos agotado, de ayer hacia atrás.
                while (!empty($stockouts[$this->today->subDays($stockoutStreak + 1)->toDateString()])) {
                    $stockoutStreak++;
                }

                $forecast = $this->forecaster->forecast(
                    $demand,
                    $stockouts,
                    $this->today,
                    $firstDays[$locationId][$productId] ?? null,
                    $season[$productId] ?? 1.0,
                );
            }

            // Se vende tan de vez en cuando que no hay qué pronosticar.
            $sporadic = $forecast->hasDemand()
                && $forecast->sellingDays < self::MIN_SELLING_DAYS
                && $forecast->sellingDays < 0.1 * $forecast->historyDays;

            $restOfToday = 0.0;
            $horizon = 0.0;
            if (!$sporadic) {
                $restOfToday = max(0.0, $forecast->forDate($this->today) - $soldToday);
                foreach ($horizonDays as $day) {
                    $horizon += $forecast->forDate($day);
                }
            }
            $expected = $restOfToday + $horizon;
            $safety = min($expected, self::SAFETY_Z * $forecast->dailyDeviation * sqrt(max(1, $this->coverageDays)));

            $minimum = (float) $row->minimum_stock;
            $maximum = (float) $row->maximum_stock;
            $target = $expected + $safety;
            if ($minimum > 0) {
                $target = max($target, $expected + $minimum);
            }

            $onHand = max(0.0, $current - (float) $row->reserved_stock);
            $in = $incoming[$locationId][$productId] ?? 0.0;
            $out = $committed[$locationId][$productId] ?? 0.0;
            $available = $onHand + $in - $out;

            $deficit = max(0.0, $target - $available);
            if ($maximum > 0) {
                $deficit = min($deficit, max(0.0, $maximum - $available));
            }

            if ($deficit <= self::EPSILON && $available <= self::EPSILON && !$forecast->hasDemand()) {
                continue;
            }

            $positions[$productId][$locationId] = [
                'forecast' => $forecast,
                'sporadic' => $sporadic,
                'stockout_streak' => $stockoutStreak,
                'sold_today' => $soldToday,
                'rest_of_today' => $restOfToday,
                'horizon' => $horizon,
                'safety' => $safety,
                'minimum' => $minimum,
                'target' => $target,
                'on_hand' => $onHand,
                'incoming' => $in,
                'committed' => $out,
                'available' => $available,
                'deficit' => $deficit,
                'surplus' => max(0.0, $available - $target),
            ];
        }

        return $positions;
    }

    /**
     * Días en que el producto se agotó en la sucursal: alguna salida lo dejó
     * en cero, o pasó el día entero sin existencia. La existencia al cierre
     * de cada día se reconstruye hacia atrás desde la actual.
     *
     * @return array<string, bool>
     */
    private function stockoutDays(array $data, float $current): array
    {
        $excluded = [];
        $todayKey = $this->today->toDateString();
        $endOfDay = $current - ($data['net'][$todayKey] ?? 0.0);

        for ($i = 1; $i <= DemandForecaster::LOOKBACK_DAYS; $i++) {
            $key = $this->today->subDays($i)->toDateString();
            $moved = array_key_exists($key, $data['min']);

            if (($moved && $data['min'][$key] <= self::EPSILON) || (!$moved && $endOfDay <= self::EPSILON)) {
                $excluded[$key] = true;
            }

            $endOfDay -= $data['net'][$key] ?? 0.0;
        }

        return $excluded;
    }

    // ─── Asignación ─────────────────────────────────────────────────────────

    private function allocate(array $positions, array $routes): array
    {
        $transfers = [];   // "from-to" => [from, to, items]
        $purchases = [];   // "location-supplier" => [...]
        $production = [];  // location => [...]
        $shortages = [];
        $costs = null;

        foreach ($positions as $productId => $byLocation) {
            $product = $this->products[$productId];
            $increment = $this->increment($productId);

            $surplus = array_map(fn($position) => $position['surplus'], $byLocation);
            $needs = array_filter(array_map(fn($position) => $position['deficit'], $byLocation), fn($deficit) => $deficit > self::EPSILON);
            arsort($needs);

            $purchaseHub = $routes['purchase_hub'][$productId] ?? null;
            $productHub = $purchaseHub ?? $routes['production_hub'][$productId] ?? $routes['main_hub'];

            $unmet = [];
            foreach ($needs as $destination => $need) {
                $quantity = $this->roundNeed($need, $increment);
                if ($quantity <= self::EPSILON) {
                    continue;
                }

                // Lo que la sucursal compra por su cuenta no se le manda: lo compra.
                if ($purchaseHub !== $destination) {
                    foreach ($this->sourcesFor($destination, $productId, $productHub, $surplus, $routes) as $source) {
                        $send = min($quantity, $this->roundDown($surplus[$source], $increment));
                        if ($send < $increment - self::EPSILON) {
                            continue;
                        }

                        $key = "{$source}-{$destination}";
                        $transfers[$key] ??= ['from' => $source, 'to' => $destination, 'items' => []];
                        $transfers[$key]['items'][] = [
                            'product' => $this->productPayload($productId),
                            'quantity' => $this->clean($send),
                            'destination' => $this->explain($byLocation[$destination]),
                            'source' => $this->explain($byLocation[$source]),
                        ];

                        $surplus[$source] -= $send;
                        $quantity -= $send;
                        if ($quantity <= self::EPSILON) {
                            break;
                        }
                    }
                }

                if ($quantity > self::EPSILON) {
                    $unmet[$destination] = $quantity;
                }
            }

            if ($unmet === []) {
                continue;
            }

            // Lo que no alcanzó: se compra o se produce donde se acostumbra.
            // Si la sucursal compra el producto por su cuenta, en ella.
            $byHub = [];
            foreach ($unmet as $location => $quantity) {
                $hub = $purchaseHub === $location ? $location : ($productHub ?? $location);
                $byHub[$hub][$location] = $quantity;
            }

            foreach ($byHub as $hub => $contributions) {
                $total = $this->clean(array_sum($contributions));
                $needsList = [];
                foreach ($contributions as $location => $quantity) {
                    $needsList[] = ['location' => $this->locationPayload($location), 'quantity' => $this->clean($quantity)];
                }

                if ($product->is_purchasable) {
                    $costs ??= new ProductCosts($this->companyId);
                    $unitCost = $costs->cost($productId);
                    $supplierId = $product->supplier_id ? (int) $product->supplier_id : 0;
                    $key = "{$hub}-{$supplierId}";
                    $purchases[$key] ??= [
                        'location' => $this->locationPayload($hub),
                        'supplier' => $supplierId ? ['id' => $supplierId, 'name' => $product->supplier_name] : null,
                        'items' => [],
                        'total' => 0.0,
                    ];
                    $line = $unitCost !== null ? round($unitCost * $total, 2) : null;
                    $purchases[$key]['items'][] = [
                        'product' => $this->productPayload($productId),
                        'quantity' => $total,
                        'unit_cost' => $unitCost !== null ? round($unitCost, 4) : null,
                        'total' => $line,
                        'needs' => $needsList,
                    ];
                    $purchases[$key]['total'] += $line ?? 0;
                } elseif ($product->is_producible) {
                    $production[$hub] ??= ['location' => $this->locationPayload($hub), 'items' => []];
                    $production[$hub]['items'][] = [
                        'product' => $this->productPayload($productId),
                        'quantity' => $total,
                        'needs' => $needsList,
                    ];
                } else {
                    $shortages[] = [
                        'product' => $this->productPayload($productId),
                        'quantity' => $total,
                        'needs' => $needsList,
                    ];
                }
            }
        }

        $byProductName = fn($a, $b) => strcmp($a['product']['name'], $b['product']['name']);
        $transfers = array_values(array_map(function ($group) use ($byProductName) {
            usort($group['items'], $byProductName);

            return [
                'from' => $this->locationPayload($group['from']),
                'to' => $this->locationPayload($group['to']),
                'items' => $group['items'],
            ];
        }, $transfers));
        // Por sucursal que recibe: así cada una ve junto todo lo que le llega.
        usort($transfers, fn($a, $b) => [$a['to']['name'], $a['from']['name']] <=> [$b['to']['name'], $b['from']['name']]);

        $purchases = array_values(array_map(function ($group) use ($byProductName) {
            usort($group['items'], $byProductName);
            $group['total'] = round($group['total'], 2);

            return $group;
        }, $purchases));

        $production = array_values(array_map(function ($group) use ($byProductName) {
            usort($group['items'], $byProductName);

            return $group;
        }, $production));

        return [
            'today' => $this->today->toDateString(),
            'coverage_days' => $this->coverageDays,
            'horizon' => [
                'from' => $this->today->addDay()->toDateString(),
                'to' => $this->today->addDays($this->coverageDays)->toDateString(),
            ],
            'history_days' => DemandForecaster::LOOKBACK_DAYS,
            'transfers' => $transfers,
            'purchases' => $purchases,
            'production' => $production,
            'shortages' => $shortages,
        ];
    }

    /**
     * Orden en que se busca quién mande: quien suele surtirle ese producto,
     * quien suele surtirle en general, el centro de distribución del producto
     * y al final cualquier sucursal a la que le sobre.
     *
     * @return int[]
     */
    private function sourcesFor(int $destination, int $productId, ?int $productHub, array $surplus, array $routes): array
    {
        $ranked = [];
        $add = function (?int $location) use (&$ranked, $destination, $surplus) {
            if ($location !== null && $location !== $destination && isset($surplus[$location]) && !in_array($location, $ranked, true)) {
                $ranked[] = $location;
            }
        };

        $usual = $routes['sources'][$destination][$productId] ?? [];
        arsort($usual);
        foreach (array_keys($usual) as $location) {
            $add((int) $location);
        }

        $general = $routes['sources_any'][$destination] ?? [];
        arsort($general);
        foreach (array_keys($general) as $location) {
            $add((int) $location);
        }

        $add($productHub);

        $rest = $surplus;
        arsort($rest);
        foreach (array_keys($rest) as $location) {
            $add((int) $location);
        }

        return $ranked;
    }

    // ─── Formato ────────────────────────────────────────────────────────────

    /** Cómo se llegó al número, para el "¿por qué?" de la app. */
    private function explain(array $position): array
    {
        /** @var DemandForecast $forecast */
        $forecast = $position['forecast'];
        $firstDay = $this->today->addDay();

        return [
            'daily_average' => $this->clean($forecast->dailyLevel * $forecast->seasonFactor, 2),
            'weekday_factor' => round($forecast->weekdayFactor($firstDay), 2),
            'season_factor' => round($forecast->seasonFactor, 2),
            'sold_today' => $this->clean($position['sold_today']),
            'rest_of_today' => $this->clean($position['rest_of_today']),
            'forecast' => $this->clean($position['horizon']),
            'safety' => $this->clean($position['safety']),
            'minimum' => $this->clean($position['minimum']),
            'target' => $this->clean($position['target']),
            'stock' => $this->clean($position['on_hand']),
            'incoming' => $this->clean($position['incoming']),
            'committed' => $this->clean($position['committed']),
            'available' => $this->clean($position['available']),
            'history_days' => $forecast->historyDays,
            'stockout_days' => $forecast->stockoutDays,
            'stockout_streak' => $position['stockout_streak'],
            'selling_days' => $forecast->sellingDays,
            'sporadic' => $position['sporadic'],
        ];
    }

    private function productPayload(int $productId): array
    {
        $product = $this->products[$productId];

        return [
            'id' => $productId,
            'name' => $product->name,
            'code' => $product->code,
            'unit_id' => $product->unit_id ? (int) $product->unit_id : null,
            'unit' => $product->unit_abbreviation ?? $product->unit_name,
        ];
    }

    private function locationPayload(int $locationId): array
    {
        return ['id' => $locationId, 'name' => $this->locations[$locationId]->name ?? "Sucursal {$locationId}"];
    }

    /** Lo que se mueve en piezas enteras se pide en enteros; lo demás, en décimas. */
    private function increment(int $productId): float
    {
        return !empty($this->fractional[$productId]) ? 0.1 : 1.0;
    }

    /**
     * Cantidad a pedir: en enteros se redondea al más cercano (faltar media
     * pieza no justifica pedir una; el margen ya cubre la variación), en
     * fracciones se redondea hacia arriba.
     */
    private function roundNeed(float $need, float $increment): float
    {
        if ($increment >= 1) {
            return (float) round($need);
        }

        return ceil(round($need / $increment, 6)) * $increment;
    }

    private function roundDown(float $quantity, float $increment): float
    {
        return floor(round($quantity / $increment, 6)) * $increment;
    }

    private function isWhole(float $value): bool
    {
        return abs($value - round($value)) < self::EPSILON;
    }

    private function clean(float $value, int $decimals = 3): float
    {
        return round($value, $decimals) + 0.0;
    }
}
