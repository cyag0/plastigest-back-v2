<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ventas de un periodo: totales contra el periodo anterior, por sucursal, día
 * y hora, productos y categorías, métodos de pago, cajeros, cancelaciones y
 * descuentos.
 *
 * Una venta cancelada no es dinero vendido: queda fuera de todos los totales
 * y se cuenta aparte, en su propia sección. El día es sale_date (el de la
 * venta); la hora sale de created_at, que es cuando se cobró.
 */
final class SalesPeriodReport implements BusinessReport
{
    public const PAYMENT_LABELS = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'mixed' => 'Mixto',
        'credit' => 'Crédito',
        'other' => 'Otro',
    ];

    private const CANCELLED = SaleStatus::CANCELLED->value;

    private ?array $data = null;

    public function __construct(
        private readonly ReportScope $scope,
        private readonly ReportPeriod $period,
    ) {}

    public function title(): string
    {
        return 'Reporte de ventas';
    }

    public function fileName(): string
    {
        return 'ventas-' . $this->period->fromDate() . '-a-' . $this->period->toDate();
    }

    public function view(): string
    {
        return 'pdf.reports.sales';
    }

    public function paper(): string
    {
        return 'portrait';
    }

    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $previous = $this->period->previous();
        $products = $this->products();

        return $this->data = [
            'scope' => $this->scope,
            'period' => $this->period,
            'previous' => $previous,
            'totals' => $this->totals($this->period),
            'previous_totals' => $this->totals($previous),
            'by_location' => $this->byLocation(),
            'by_day' => $byDay = $this->byDay(),
            'by_month' => $this->byMonth($byDay),
            'by_hour' => $this->byHour(),
            'products' => $products,
            'categories' => $this->categories($products),
            'payments' => $this->payments(),
            'cashiers' => $this->cashiers(),
            'cancellations' => $this->cancellations(),
            'discounts' => $this->discounts(),
        ];
    }

    // ─── Consultas ──────────────────────────────────────────────────────────

    private function sales(ReportPeriod $period): Builder
    {
        return DB::table('sales')
            ->where('sales.company_id', $this->scope->companyId())
            ->when($this->scope->locationId(), fn($q, $id) => $q->where('sales.location_id', $id))
            ->whereBetween('sales.sale_date', [$period->fromDate(), $period->toDate()]);
    }

    private function closed(ReportPeriod $period): Builder
    {
        return $this->sales($period)->where('sales.status', '!=', self::CANCELLED);
    }

    private function totals(ReportPeriod $period): array
    {
        $cancelled = self::CANCELLED;
        $row = $this->sales($period)->selectRaw("
            COALESCE(SUM(CASE WHEN status <> '{$cancelled}' THEN total END), 0) AS total,
            COALESCE(SUM(CASE WHEN status <> '{$cancelled}' THEN subtotal END), 0) AS subtotal,
            COALESCE(SUM(CASE WHEN status <> '{$cancelled}' THEN discount END), 0) AS discount,
            COALESCE(SUM(status <> '{$cancelled}'), 0) AS tickets,
            COALESCE(SUM(status <> '{$cancelled}' AND discount > 0), 0) AS discounted,
            COALESCE(SUM(status = '{$cancelled}'), 0) AS cancelled_count,
            COALESCE(SUM(CASE WHEN status = '{$cancelled}' THEN total END), 0) AS cancelled_total
        ")->first();

        $tickets = (int) $row->tickets;

        return [
            'total' => round((float) $row->total, 2),
            'subtotal' => round((float) $row->subtotal, 2),
            'discount' => round((float) $row->discount, 2),
            'tickets' => $tickets,
            'average' => $tickets > 0 ? round((float) $row->total / $tickets, 2) : 0.0,
            'discounted' => (int) $row->discounted,
            'cancelled_count' => (int) $row->cancelled_count,
            'cancelled_total' => round((float) $row->cancelled_total, 2),
        ];
    }

    private function byLocation(): array
    {
        $rows = $this->closed($this->period)
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->groupBy('sales.location_id', 'locations.name')
            ->selectRaw('locations.name AS name, COUNT(*) AS tickets, SUM(sales.total) AS total')
            ->orderByDesc('total')
            ->get();

        $grand = (float) $rows->sum('total');

        return $rows->map(fn($r) => [
            'name' => $r->name ?? 'Sin sucursal',
            'tickets' => (int) $r->tickets,
            'total' => round((float) $r->total, 2),
            'average' => $r->tickets > 0 ? round((float) $r->total / $r->tickets, 2) : 0.0,
            'share' => $grand > 0 ? (float) $r->total / $grand : 0.0,
        ])->all();
    }

    /** Todos los días del periodo, también los que no se vendió. */
    private function byDay(): array
    {
        $rows = $this->closed($this->period)
            ->groupBy('sales.sale_date')
            ->selectRaw('sales.sale_date AS day, COUNT(*) AS tickets, SUM(sales.total) AS total')
            ->get()
            ->keyBy(fn($r) => substr((string) $r->day, 0, 10));

        $days = [];
        for ($day = $this->period->from; $day->lessThanOrEqualTo($this->period->to); $day = $day->addDay()) {
            $row = $rows[$day->toDateString()] ?? null;
            $days[] = [
                'date' => $day->toDateString(),
                'label' => ucfirst($day->locale('es')->translatedFormat('D d/m')),
                'tickets' => (int) ($row->tickets ?? 0),
                'total' => round((float) ($row->total ?? 0), 2),
            ];
        }

        return $days;
    }

    /** Los días agrupados por mes: en un periodo largo es lo que se lee. */
    private function byMonth(array $days): array
    {
        $months = [];
        foreach ($days as $day) {
            $key = substr($day['date'], 0, 7);
            $months[$key] ??= [
                'label' => ucfirst(\Carbon\CarbonImmutable::parse($day['date'])->locale('es')->translatedFormat('F Y')),
                'tickets' => 0,
                'total' => 0.0,
            ];
            $months[$key]['tickets'] += $day['tickets'];
            $months[$key]['total'] += $day['total'];
        }

        return array_values($months);
    }

    /** De la primera a la última hora con ventas, sin huecos. */
    private function byHour(): array
    {
        $rows = $this->closed($this->period)
            ->groupByRaw('HOUR(sales.created_at)')
            ->selectRaw('HOUR(sales.created_at) AS hour, COUNT(*) AS tickets, SUM(sales.total) AS total')
            ->get()
            ->keyBy(fn($r) => (int) $r->hour);

        if ($rows->isEmpty()) {
            return [];
        }

        $grand = (float) $rows->sum('total');
        $hours = [];
        for ($h = (int) $rows->keys()->min(); $h <= (int) $rows->keys()->max(); $h++) {
            $row = $rows[$h] ?? null;
            $hours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00 – %02d:59', $h, $h),
                'tickets' => (int) ($row->tickets ?? 0),
                'total' => round((float) ($row->total ?? 0), 2),
                'share' => $grand > 0 ? (float) ($row->total ?? 0) / $grand : 0.0,
            ];
        }

        return $hours;
    }

    /**
     * Cada producto con su cantidad en la unidad del producto: un mismo
     * producto se vende por pieza, por docena o en paquete.
     */
    private function products(): array
    {
        $lines = DB::table('sales_details')
            ->joinSub($this->closed($this->period)->select('sales.id'), 'closed_sales', 'closed_sales.id', '=', 'sales_details.sale_id')
            ->groupBy('sales_details.product_id', 'sales_details.unit_id', 'sales_details.package_id')
            ->selectRaw('sales_details.product_id, sales_details.unit_id, sales_details.package_id,
                SUM(sales_details.quantity) AS quantity, SUM(sales_details.total) AS total,
                COUNT(DISTINCT sales_details.sale_id) AS tickets')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $info = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('products.id', $lines->pluck('product_id')->unique())
            ->get(['products.id', 'products.code', 'products.name', 'categories.name AS category', 'units.abbreviation AS unit'])
            ->keyBy('id');

        $converter = new QuantityConverter();
        $converter->loadProducts($lines->pluck('product_id')->unique()->all());

        $products = [];
        foreach ($lines as $line) {
            $id = (int) $line->product_id;
            $qty = $converter->toProductUnit($id, $line->unit_id ? (int) $line->unit_id : null, (float) $line->quantity, $line->package_id ? (int) $line->package_id : null);
            $products[$id] ??= [
                'code' => $info[$id]->code ?? null,
                'name' => $info[$id]->name ?? "Producto #{$id}",
                'category' => $info[$id]->category ?? 'Sin categoría',
                'unit' => $info[$id]->unit ?? null,
                'quantity' => 0.0,
                'quantity_complete' => true,
                'tickets' => 0,
                'total' => 0.0,
            ];
            if ($qty === null) {
                $products[$id]['quantity_complete'] = false;
            } else {
                $products[$id]['quantity'] += $qty;
            }
            // Una venta con el producto en dos presentaciones cuenta dos veces
            // aquí; es un tope, no un conteo exacto de tickets.
            $products[$id]['tickets'] += (int) $line->tickets;
            $products[$id]['total'] += (float) $line->total;
        }

        $grand = array_sum(array_column($products, 'total'));
        $products = array_values($products);
        usort($products, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_map(fn($p) => $p + [
            'share' => $grand > 0 ? $p['total'] / $grand : 0.0,
        ], $products);
    }

    private function categories(array $products): array
    {
        $categories = [];
        foreach ($products as $p) {
            $categories[$p['category']] ??= ['name' => $p['category'], 'products' => 0, 'total' => 0.0];
            $categories[$p['category']]['products']++;
            $categories[$p['category']]['total'] += $p['total'];
        }

        $grand = array_sum(array_column($categories, 'total'));
        $categories = array_values($categories);
        usort($categories, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_map(fn($c) => $c + ['share' => $grand > 0 ? $c['total'] / $grand : 0.0], $categories);
    }

    /**
     * Lo cobrado por método. Las ventas del punto de venta guardan cada pago
     * (sale_payments); las anteriores solo traen un método y lo pagado. Lo que
     * falta por cobrar (crédito, anticipo) va en su propio renglón.
     */
    private function payments(): array
    {
        $methods = [];
        $add = function (string $method, int $count, float $amount) use (&$methods) {
            $methods[$method] ??= ['method' => $method, 'label' => self::PAYMENT_LABELS[$method] ?? ucfirst($method), 'count' => 0, 'total' => 0.0];
            $methods[$method]['count'] += $count;
            $methods[$method]['total'] += $amount;
        };

        $recorded = DB::table('sale_payments')
            ->joinSub($this->closed($this->period)->select('sales.id'), 'closed_sales', 'closed_sales.id', '=', 'sale_payments.sale_id')
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, COUNT(DISTINCT sale_payments.sale_id) AS n, SUM(sale_payments.amount) AS amount')
            ->get();
        foreach ($recorded as $r) {
            $add($r->method, (int) $r->n, (float) $r->amount);
        }

        $legacy = $this->closed($this->period)
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id'))
            ->where('sales.paid_amount', '>', 0)
            ->groupBy('sales.payment_method')
            ->selectRaw('sales.payment_method AS method, COUNT(*) AS n, SUM(LEAST(sales.paid_amount, sales.total)) AS amount')
            ->get();
        foreach ($legacy as $r) {
            $add($r->method ?: 'other', (int) $r->n, (float) $r->amount);
        }

        $pending = $this->closed($this->period)
            ->whereColumn('sales.paid_amount', '<', 'sales.total')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(sales.total - sales.paid_amount), 0) AS amount')
            ->first();
        if ((float) $pending->amount > 0) {
            $methods['pending'] = ['method' => 'pending', 'label' => 'Por cobrar', 'count' => (int) $pending->n, 'total' => (float) $pending->amount];
        }

        $grand = array_sum(array_column($methods, 'total'));
        $methods = array_values($methods);
        usort($methods, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_map(fn($m) => $m + ['share' => $grand > 0 ? $m['total'] / $grand : 0.0], $methods);
    }

    private function cashiers(): array
    {
        $cancelled = self::CANCELLED;

        return $this->sales($this->period)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->groupBy('sales.user_id', 'users.name')
            ->selectRaw("users.name AS name,
                COALESCE(SUM(sales.status <> '{$cancelled}'), 0) AS tickets,
                COALESCE(SUM(CASE WHEN sales.status <> '{$cancelled}' THEN sales.total END), 0) AS total,
                COALESCE(SUM(CASE WHEN sales.status <> '{$cancelled}' THEN sales.discount END), 0) AS discount,
                COALESCE(SUM(sales.status = '{$cancelled}'), 0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN sales.status = '{$cancelled}' THEN sales.total END), 0) AS cancelled_total")
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'name' => $r->name ?? 'Sin usuario',
                'tickets' => (int) $r->tickets,
                'total' => round((float) $r->total, 2),
                'average' => $r->tickets > 0 ? round((float) $r->total / $r->tickets, 2) : 0.0,
                'discount' => round((float) $r->discount, 2),
                'cancelled_count' => (int) $r->cancelled_count,
                'cancelled_total' => round((float) $r->cancelled_total, 2),
            ])
            ->all();
    }

    /**
     * Ventas canceladas, con quién y cuándo las canceló (de la bitácora; las
     * canceladas antes de que existiera no lo tienen).
     */
    private function cancellations(): array
    {
        $rows = $this->sales($this->period)
            ->where('sales.status', self::CANCELLED)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->orderBy('sales.sale_date')
            ->orderBy('sales.id')
            ->get(['sales.id', 'sales.sale_number', 'sales.sale_date', 'sales.created_at', 'sales.total', 'sales.updated_at',
                'users.name AS cashier', 'locations.name AS location']);

        $logs = DB::table('activity_logs')
            ->where('subject_type', Sale::class)
            ->whereIn('subject_id', $rows->pluck('id'))
            ->where('event', 'status')
            ->orderBy('id')
            ->get(['subject_id', 'user_name', 'created_at'])
            ->keyBy('subject_id');

        return $rows->map(fn($r) => [
            'number' => $r->sale_number ?? "#{$r->id}",
            'date' => $r->created_at,
            'location' => $r->location,
            'cashier' => $r->cashier,
            'total' => round((float) $r->total, 2),
            'cancelled_by' => $logs[$r->id]->user_name ?? null,
            'cancelled_at' => $logs[$r->id]->created_at ?? $r->updated_at,
        ])->all();
    }

    private function discounts(): array
    {
        return $this->closed($this->period)
            ->where('sales.discount', '>', 0)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->orderByDesc('sales.discount')
            ->get(['sales.id', 'sales.sale_number', 'sales.created_at', 'sales.subtotal', 'sales.discount', 'sales.total',
                'users.name AS cashier', 'locations.name AS location'])
            ->map(fn($r) => [
                'number' => $r->sale_number ?? "#{$r->id}",
                'date' => $r->created_at,
                'location' => $r->location,
                'cashier' => $r->cashier,
                'subtotal' => round((float) $r->subtotal, 2),
                'discount' => round((float) $r->discount, 2),
                'total' => round((float) $r->total, 2),
                'share' => (float) $r->subtotal > 0 ? (float) $r->discount / (float) $r->subtotal : 0.0,
            ])
            ->all();
    }

    // ─── Excel ──────────────────────────────────────────────────────────────

    public function sheets(): array
    {
        $d = $this->data();
        $title = fn(string $what) => [
            "{$this->title()} · {$what}",
            $this->scope->company->name . ' · ' . $this->scope->locationLabel(),
            'Periodo: ' . $this->period->label(),
        ];
        $t = $d['totals'];
        $p = $d['previous_totals'];
        $change = fn($now, $before) => $before > 0 ? ($now - $before) / $before : null;
        $count = fn($n) => Spreadsheet::cell($n, Spreadsheet::INT);
        $discounted = [
            'subtotal' => array_sum(array_column($d['discounts'], 'subtotal')),
            'discount' => array_sum(array_column($d['discounts'], 'discount')),
            'total' => array_sum(array_column($d['discounts'], 'total')),
        ];

        $sheets = [[
            'name' => 'Resumen',
            'title' => array_merge($title('Resumen'), ['Comparado con: ' . $d['previous']->label()]),
            'columns' => [
                ['label' => 'Concepto', 'width' => 28],
                ['label' => 'Este periodo', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => 'Periodo anterior', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => 'Cambio', 'type' => Spreadsheet::PCT, 'width' => 10],
            ],
            'rows' => [
                ['Ventas', $t['total'], $p['total'], $change($t['total'], $p['total'])],
                ['Tickets', $count($t['tickets']), $count($p['tickets']), $change($t['tickets'], $p['tickets'])],
                ['Ticket promedio', $t['average'], $p['average'], $change($t['average'], $p['average'])],
                ['Descuentos', $t['discount'], $p['discount'], $change($t['discount'], $p['discount'])],
                ['Ventas con descuento', $count($t['discounted']), $count($p['discounted']), $change($t['discounted'], $p['discounted'])],
                ['Cancelaciones', $count($t['cancelled_count']), $count($p['cancelled_count']), $change($t['cancelled_count'], $p['cancelled_count'])],
                ['Importe cancelado', $t['cancelled_total'], $p['cancelled_total'], $change($t['cancelled_total'], $p['cancelled_total'])],
            ],
        ]];

        $sheets[] = [
            'name' => 'Por sucursal',
            'title' => $title('Por sucursal'),
            'columns' => [
                ['label' => 'Sucursal', 'width' => 28],
                ['label' => 'Tickets', 'type' => Spreadsheet::INT, 'width' => 10],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => 'Ticket promedio', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => '% del total', 'type' => Spreadsheet::PCT, 'width' => 11],
            ],
            'rows' => array_map(fn($r) => [$r['name'], $r['tickets'], $r['total'], $r['average'], $r['share']], $d['by_location']),
            'totals' => ['Total', $t['tickets'], $t['total'], $t['average'], 1],
        ];

        $sheets[] = [
            'name' => 'Por día',
            'title' => $title('Por día'),
            'columns' => [
                ['label' => 'Fecha', 'type' => Spreadsheet::DATE, 'width' => 12],
                ['label' => 'Día', 'width' => 12],
                ['label' => 'Tickets', 'type' => Spreadsheet::INT, 'width' => 10],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
            ],
            'rows' => array_map(fn($r) => [$r['date'], $r['label'], $r['tickets'], $r['total']], $d['by_day']),
            'totals' => ['Total', '', $t['tickets'], $t['total']],
        ];

        $sheets[] = [
            'name' => 'Por hora',
            'title' => $title('Por hora'),
            'columns' => [
                ['label' => 'Hora', 'width' => 16],
                ['label' => 'Tickets', 'type' => Spreadsheet::INT, 'width' => 10],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => '% del total', 'type' => Spreadsheet::PCT, 'width' => 11],
            ],
            'rows' => array_map(fn($r) => [$r['label'], $r['tickets'], $r['total'], $r['share']], $d['by_hour']),
        ];

        $sheets[] = [
            'name' => 'Productos',
            'title' => $title('Productos'),
            'columns' => [
                ['label' => 'Código', 'width' => 14],
                ['label' => 'Producto', 'width' => 34],
                ['label' => 'Categoría', 'width' => 20],
                ['label' => 'Cantidad', 'type' => Spreadsheet::QTY, 'width' => 12],
                ['label' => 'Unidad', 'width' => 9],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => '% del total', 'type' => Spreadsheet::PCT, 'width' => 11],
            ],
            'rows' => array_map(fn($r) => [$r['code'], $r['name'], $r['category'], $r['quantity'], $r['unit'], $r['total'], $r['share']], $d['products']),
        ];

        $sheets[] = [
            'name' => 'Categorías',
            'title' => $title('Categorías'),
            'columns' => [
                ['label' => 'Categoría', 'width' => 28],
                ['label' => 'Productos', 'type' => Spreadsheet::INT, 'width' => 11],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => '% del total', 'type' => Spreadsheet::PCT, 'width' => 11],
            ],
            'rows' => array_map(fn($r) => [$r['name'], $r['products'], $r['total'], $r['share']], $d['categories']),
        ];

        $sheets[] = [
            'name' => 'Métodos de pago',
            'title' => $title('Métodos de pago'),
            'columns' => [
                ['label' => 'Método', 'width' => 20],
                ['label' => 'Ventas', 'type' => Spreadsheet::INT, 'width' => 10],
                ['label' => 'Importe', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => '% del total', 'type' => Spreadsheet::PCT, 'width' => 11],
            ],
            'rows' => array_map(fn($r) => [$r['label'], $r['count'], $r['total'], $r['share']], $d['payments']),
        ];

        $sheets[] = [
            'name' => 'Cajeros',
            'title' => $title('Ventas por cajero'),
            'columns' => [
                ['label' => 'Cajero', 'width' => 26],
                ['label' => 'Tickets', 'type' => Spreadsheet::INT, 'width' => 10],
                ['label' => 'Ventas', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => 'Ticket promedio', 'type' => Spreadsheet::MONEY, 'width' => 16],
                ['label' => 'Descuentos', 'type' => Spreadsheet::MONEY, 'width' => 14],
                ['label' => 'Cancelaciones', 'type' => Spreadsheet::INT, 'width' => 13],
                ['label' => 'Importe cancelado', 'type' => Spreadsheet::MONEY, 'width' => 16],
            ],
            'rows' => array_map(fn($r) => [$r['name'], $r['tickets'], $r['total'], $r['average'], $r['discount'], $r['cancelled_count'], $r['cancelled_total']], $d['cashiers']),
        ];

        $sheets[] = [
            'name' => 'Cancelaciones',
            'title' => $title('Cancelaciones'),
            'columns' => [
                ['label' => 'Folio', 'width' => 14],
                ['label' => 'Fecha de venta', 'type' => Spreadsheet::DATETIME, 'width' => 17],
                ['label' => 'Sucursal', 'width' => 20],
                ['label' => 'Cajero', 'width' => 22],
                ['label' => 'Importe', 'type' => Spreadsheet::MONEY, 'width' => 14],
                ['label' => 'Canceló', 'width' => 22],
                ['label' => 'Cancelada el', 'type' => Spreadsheet::DATETIME, 'width' => 17],
            ],
            'rows' => array_map(fn($r) => [$r['number'], $r['date'], $r['location'], $r['cashier'], $r['total'], $r['cancelled_by'], $r['cancelled_at']], $d['cancellations']),
            'totals' => ['Total', '', '', '', $t['cancelled_total'], '', ''],
        ];

        $sheets[] = [
            'name' => 'Descuentos',
            'title' => $title('Descuentos'),
            'columns' => [
                ['label' => 'Folio', 'width' => 14],
                ['label' => 'Fecha', 'type' => Spreadsheet::DATETIME, 'width' => 17],
                ['label' => 'Sucursal', 'width' => 20],
                ['label' => 'Cajero', 'width' => 22],
                ['label' => 'Subtotal', 'type' => Spreadsheet::MONEY, 'width' => 14],
                ['label' => 'Descuento', 'type' => Spreadsheet::MONEY, 'width' => 14],
                ['label' => '% descuento', 'type' => Spreadsheet::PCT, 'width' => 11],
                ['label' => 'Total', 'type' => Spreadsheet::MONEY, 'width' => 14],
            ],
            'rows' => array_map(fn($r) => [$r['number'], $r['date'], $r['location'], $r['cashier'], $r['subtotal'], $r['discount'], $r['share'], $r['total']], $d['discounts']),
            'totals' => ['Total', '', '', '', $discounted['subtotal'], $discounted['discount'],
                $discounted['subtotal'] > 0 ? $discounted['discount'] / $discounted['subtotal'] : 0, $discounted['total']],
        ];

        return $sheets;
    }
}
