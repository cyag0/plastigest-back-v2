<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Inventario valorizado: existencia de cada producto por sucursal con su
 * costo y su valor, y los agotados y bajo mínimo resaltados.
 *
 * Se puede pedir a una fecha de corte (el cierre de mes o de año que pide el
 * contador): la existencia de ese día sale del libro de movimientos, que
 * registra cada cambio de stock con la existencia anterior y la nueva. El
 * costo es el de la última compra recibida hasta ese día (ver ProductCosts).
 * El mínimo es siempre el configurado hoy; no tiene historial.
 *
 * Quedan fuera los productos sin existencia y sin mínimo: nadie espera
 * tenerlos y llenarían el reporte de ceros.
 */
final class InventoryValuationReport implements BusinessReport
{
    public const STATUS_OK = 'ok';
    public const STATUS_LOW = 'low';
    public const STATUS_OUT = 'out';

    public const STATUS_LABELS = [
        self::STATUS_OK => '',
        self::STATUS_LOW => 'Bajo mínimo',
        self::STATUS_OUT => 'Agotado',
    ];

    private readonly CarbonImmutable $asOf;
    private readonly bool $historical;
    private ?array $data = null;

    public function __construct(
        private readonly ReportScope $scope,
        ?string $asOf = null,
    ) {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $date = $asOf ? CarbonImmutable::parse($asOf, config('app.timezone'))->startOfDay() : $today;
        $this->asOf = $date->greaterThan($today) ? $today : $date;
        $this->historical = $this->asOf->lessThan($today);
    }

    public function title(): string
    {
        return 'Inventario valorizado';
    }

    public function fileName(): string
    {
        return 'inventario-valorizado-' . $this->asOf->toDateString();
    }

    public function view(): string
    {
        return 'pdf.reports.inventory-valuation';
    }

    public function asOfLabel(): string
    {
        return $this->historical
            ? 'Al cierre del ' . $this->asOf->format('d/m/Y')
            : 'Existencia actual · ' . now()->format('d/m/Y H:i');
    }

    public function paper(): string
    {
        return 'landscape';
    }

    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $costs = new ProductCosts($this->scope->companyId(), $this->historical ? $this->asOf : null);

        $rows = [];
        foreach ($this->stockRows() as $r) {
            $stock = (float) $r->stock;
            $minimum = (float) $r->minimum_stock;
            if (abs($stock) < 0.00005 && $minimum <= 0) {
                continue;
            }

            $cost = $costs->resolve((int) $r->product_id);
            $status = $stock <= 0 ? self::STATUS_OUT : ($minimum > 0 && $stock < $minimum ? self::STATUS_LOW : self::STATUS_OK);

            $rows[] = [
                'location_id' => (int) $r->location_id,
                'location' => $r->location,
                'code' => $r->code,
                'name' => $r->name,
                'category' => $r->category ?? 'Sin categoría',
                'type' => $r->type,
                'unit' => $r->unit,
                'stock' => $stock,
                'minimum' => $minimum,
                'cost' => $cost['cost'],
                'cost_source' => ProductCosts::sourceLabel($cost['source'], $cost['complete']),
                'cost_complete' => $cost['complete'],
                // Una existencia negativa (venta sin stock) no es inventario.
                'value' => $cost['cost'] !== null ? max($stock, 0) * $cost['cost'] : null,
                'status' => $status,
            ];
        }

        usort($rows, fn($a, $b) => [$a['location'], $a['category'], $a['name']] <=> [$b['location'], $b['category'], $b['name']]);

        $locations = [];
        foreach ($rows as $row) {
            $locations[$row['location_id']] ??= [
                'name' => $row['location'],
                'rows' => [],
                'value' => 0.0,
                'products' => 0,
                'out' => 0,
                'low' => 0,
                'without_cost' => 0,
            ];
            $loc = &$locations[$row['location_id']];
            $loc['rows'][] = $row;
            $loc['value'] += $row['value'] ?? 0;
            $loc['products'] += $row['stock'] > 0 ? 1 : 0;
            $loc['out'] += $row['status'] === self::STATUS_OUT ? 1 : 0;
            $loc['low'] += $row['status'] === self::STATUS_LOW ? 1 : 0;
            $loc['without_cost'] += $row['stock'] > 0 && $row['cost'] === null ? 1 : 0;
            unset($loc);
        }

        $categories = [];
        foreach ($rows as $row) {
            $categories[$row['category']] ??= ['name' => $row['category'], 'value' => 0.0, 'products' => 0];
            $categories[$row['category']]['value'] += $row['value'] ?? 0;
            $categories[$row['category']]['products'] += $row['stock'] > 0 ? 1 : 0;
        }
        $categories = array_values($categories);
        usort($categories, fn($a, $b) => $b['value'] <=> $a['value']);

        $locations = array_values($locations);

        return $this->data = [
            'scope' => $this->scope,
            'as_of_label' => $this->asOfLabel(),
            'historical' => $this->historical,
            'locations' => $locations,
            'categories' => $categories,
            'totals' => [
                'value' => array_sum(array_column($locations, 'value')),
                'products' => array_sum(array_column($locations, 'products')),
                'out' => array_sum(array_column($locations, 'out')),
                'low' => array_sum(array_column($locations, 'low')),
                'without_cost' => array_sum(array_column($locations, 'without_cost')),
            ],
        ];
    }

    /**
     * Existencia por producto y sucursal: la actual, o la del día de corte
     * reconstruida con el libro de movimientos.
     */
    private function stockRows(): \Illuminate\Support\Collection
    {
        $companyId = $this->scope->companyId();
        $locationId = $this->scope->locationId();

        $rows = DB::table('product_location')
            ->join('products', 'products.id', '=', 'product_location.product_id')
            ->join('locations', 'locations.id', '=', 'product_location.location_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('inventory_types', 'inventory_types.id', '=', 'products.inventory_type_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('products.company_id', $companyId)
            ->where('locations.company_id', $companyId)
            ->when($locationId, fn($q, $id) => $q->where('product_location.location_id', $id))
            // Un producto que no existía el día de corte no estaba en inventario.
            ->when($this->historical, fn($q) => $q->where(fn($w) => $w
                ->whereNull('products.created_at')
                ->orWhere('products.created_at', '<=', $this->asOf->endOfDay()->toDateTimeString())))
            ->get([
                'product_location.product_id', 'product_location.location_id', 'product_location.current_stock',
                'product_location.minimum_stock', 'locations.name AS location', 'products.code', 'products.name',
                'categories.name AS category', 'inventory_types.name AS type', 'units.abbreviation AS unit',
            ]);

        if (!$this->historical) {
            return $rows->map(function ($r) {
                $r->stock = $r->current_stock;

                return $r;
            });
        }

        $end = $this->asOf->endOfDay()->toDateTimeString();
        $ledger = fn(string $aggregate, string $op) => DB::table('stock_ledger_entries')
            ->whereIn('id', DB::table('stock_ledger_entries')
                ->where('company_id', $companyId)
                ->when($locationId, fn($q, $id) => $q->where('location_id', $id))
                ->where('created_at', $op, $end)
                ->groupBy('product_id', 'location_id')
                ->selectRaw("{$aggregate}(id)"))
            ->get(['product_id', 'location_id', 'previous_stock', 'new_stock'])
            ->keyBy(fn($e) => $e->product_id . ':' . $e->location_id);

        // Lo que quedó tras el último movimiento de ese día o antes; si no lo
        // hubo, lo que había antes del primero posterior; si tampoco, no se ha
        // movido desde entonces y vale la existencia de hoy.
        $before = $ledger('MAX', '<=');
        $after = $ledger('MIN', '>');

        return $rows->map(function ($r) use ($before, $after) {
            $key = $r->product_id . ':' . $r->location_id;
            $r->stock = isset($before[$key]) ? $before[$key]->new_stock
                : (isset($after[$key]) ? $after[$key]->previous_stock : $r->current_stock);

            return $r;
        });
    }

    // ─── Excel ──────────────────────────────────────────────────────────────

    public function sheets(): array
    {
        $d = $this->data();
        $title = fn(string $what) => [
            "{$this->title()} · {$what}",
            $this->scope->company->name . ' · ' . $this->scope->locationLabel(),
            $d['as_of_label'],
        ];
        $tone = fn(array $r) => match ($r['status']) {
            self::STATUS_OUT => 'danger',
            self::STATUS_LOW => 'warning',
            default => null,
        };

        $rows = [];
        foreach ($d['locations'] as $loc) {
            foreach ($loc['rows'] as $r) {
                $rows[] = Spreadsheet::row([
                    $r['location'], $r['code'], $r['name'], $r['category'], $r['type'], $r['unit'],
                    $r['stock'], $r['minimum'] ?: null, $r['cost'], $r['cost_source'], $r['value'],
                    self::STATUS_LABELS[$r['status']],
                ], $tone($r));
            }
        }

        return [
            [
                'name' => 'Existencias',
                'title' => $title('Existencias'),
                'columns' => [
                    ['label' => 'Sucursal', 'width' => 20],
                    ['label' => 'Código', 'width' => 14],
                    ['label' => 'Producto', 'width' => 34],
                    ['label' => 'Categoría', 'width' => 18],
                    ['label' => 'Tipo', 'width' => 16],
                    ['label' => 'Unidad', 'width' => 9],
                    ['label' => 'Existencia', 'type' => Spreadsheet::QTY, 'width' => 12],
                    ['label' => 'Mínimo', 'type' => Spreadsheet::QTY, 'width' => 10],
                    ['label' => 'Costo unitario', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Origen del costo', 'width' => 20],
                    ['label' => 'Valor', 'type' => Spreadsheet::MONEY, 'width' => 16],
                    ['label' => 'Estado', 'width' => 13],
                ],
                'rows' => $rows,
                'totals' => ['Total', '', '', '', '', '', null, null, null, '', $d['totals']['value'], ''],
            ],
            [
                'name' => 'Por sucursal',
                'title' => $title('Por sucursal'),
                'columns' => [
                    ['label' => 'Sucursal', 'width' => 24],
                    ['label' => 'Productos con existencia', 'type' => Spreadsheet::INT, 'width' => 14],
                    ['label' => 'Valor', 'type' => Spreadsheet::MONEY, 'width' => 16],
                    ['label' => 'Agotados', 'type' => Spreadsheet::INT, 'width' => 11],
                    ['label' => 'Bajo mínimo', 'type' => Spreadsheet::INT, 'width' => 12],
                    ['label' => 'Sin costo', 'type' => Spreadsheet::INT, 'width' => 11],
                ],
                'rows' => array_map(fn($l) => [$l['name'], $l['products'], $l['value'], $l['out'], $l['low'], $l['without_cost']], $d['locations']),
                'totals' => ['Total', $d['totals']['products'], $d['totals']['value'], $d['totals']['out'], $d['totals']['low'], $d['totals']['without_cost']],
            ],
            [
                'name' => 'Por categoría',
                'title' => $title('Por categoría'),
                'columns' => [
                    ['label' => 'Categoría', 'width' => 26],
                    ['label' => 'Productos con existencia', 'type' => Spreadsheet::INT, 'width' => 14],
                    ['label' => 'Valor', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ],
                'rows' => array_map(fn($c) => [$c['name'], $c['products'], $c['value']], $d['categories']),
                'totals' => ['Total', $d['totals']['products'], $d['totals']['value']],
            ],
        ];
    }
}
