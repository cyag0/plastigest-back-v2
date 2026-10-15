<?php

namespace App\Reports;

use App\Enums\AdjustmentReasonCode;
use Illuminate\Support\Facades\DB;

/**
 * Mermas y ajustes de un periodo: lo que se perdió por motivo (merma, daño,
 * caducidad, robo, diferencia de conteo…), por producto, sucursal y usuario,
 * con su costo.
 *
 * Junta las tres fuentes de ajustes, que viven en la misma tabla: los
 * ajustes manuales, las mermas de producción y las diferencias de los
 * conteos físicos. Las salidas son pérdidas; las entradas (producto
 * encontrado, conteo que sobró) se muestran aparte y se restan en el neto.
 *
 * El costo es el de la última compra hasta el fin del periodo (ver
 * ProductCosts), llevado a la unidad en que se capturó el ajuste.
 */
final class AdjustmentsReport implements BusinessReport
{
    private ?array $data = null;

    public function __construct(
        private readonly ReportScope $scope,
        private readonly ReportPeriod $period,
    ) {}

    public function title(): string
    {
        return 'Mermas y ajustes';
    }

    public function fileName(): string
    {
        return 'mermas-ajustes-' . $this->period->fromDate() . '-a-' . $this->period->toDate();
    }

    public function view(): string
    {
        return 'pdf.reports.adjustments';
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

        $records = DB::table('inventory_adjustment_details')
            ->join('products', 'products.id', '=', 'inventory_adjustment_details.product_id')
            ->leftJoin('locations', 'locations.id', '=', 'inventory_adjustment_details.location_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_adjustment_details.created_by')
            ->leftJoin('units', 'units.id', '=', 'inventory_adjustment_details.unit_id')
            ->leftJoin('units AS product_units', 'product_units.id', '=', 'products.unit_id')
            ->where('inventory_adjustment_details.company_id', $this->scope->companyId())
            ->when($this->scope->locationId(), fn($q, $id) => $q->where('inventory_adjustment_details.location_id', $id))
            ->whereRaw('COALESCE(inventory_adjustment_details.applied_at, inventory_adjustment_details.created_at) BETWEEN ? AND ?', [
                $this->period->fromDateTime(), $this->period->toDateTime(),
            ])
            ->orderByRaw('COALESCE(inventory_adjustment_details.applied_at, inventory_adjustment_details.created_at)')
            ->orderBy('inventory_adjustment_details.id')
            ->get([
                'inventory_adjustment_details.id', 'inventory_adjustment_details.product_id', 'inventory_adjustment_details.unit_id',
                'inventory_adjustment_details.direction', 'inventory_adjustment_details.quantity', 'inventory_adjustment_details.reason_code',
                'inventory_adjustment_details.notes', 'inventory_adjustment_details.applied_at', 'inventory_adjustment_details.created_at',
                'inventory_adjustment_details.location_id', 'locations.name AS location', 'users.name AS user',
                'products.code', 'products.name AS product', 'units.abbreviation AS unit', 'product_units.abbreviation AS product_unit',
            ]);

        $costs = new ProductCosts($this->scope->companyId(), $this->period->to);
        $converter = $costs->converter();

        $detail = [];
        foreach ($records as $r) {
            $productId = (int) $r->product_id;
            $baseQty = $converter->toProductUnit($productId, $r->unit_id ? (int) $r->unit_id : null, (float) $r->quantity);
            $unitCost = $costs->cost($productId);
            $reason = AdjustmentReasonCode::tryFrom((string) $r->reason_code);

            $detail[] = [
                'date' => $r->applied_at ?? $r->created_at,
                'location' => $r->location ?? 'Sin sucursal',
                'code' => $r->code,
                'product_id' => $productId,
                'product' => $r->product,
                'reason' => $reason?->value ?? (string) $r->reason_code,
                'reason_label' => $reason?->label() ?? (string) $r->reason_code,
                'direction' => $r->direction,
                'quantity' => (float) $r->quantity,
                'unit' => $r->unit,
                'base_quantity' => $baseQty,
                'product_unit' => $r->product_unit,
                'cost' => $baseQty !== null && $unitCost !== null ? $baseQty * $unitCost : null,
                'user' => $r->user ?? 'Sistema',
                'notes' => $r->notes,
            ];
        }

        $losses = array_values(array_filter($detail, fn($d) => $d['direction'] === 'out'));
        $gains = array_values(array_filter($detail, fn($d) => $d['direction'] === 'in'));
        $lossTotal = array_sum(array_map(fn($d) => $d['cost'] ?? 0, $losses));
        $gainTotal = array_sum(array_map(fn($d) => $d['cost'] ?? 0, $gains));

        return $this->data = [
            'scope' => $this->scope,
            'period' => $this->period,
            'totals' => [
                'loss_count' => count($losses),
                'loss_cost' => $lossTotal,
                'gain_count' => count($gains),
                'gain_cost' => $gainTotal,
                'net' => $gainTotal - $lossTotal,
                'without_cost' => count(array_filter($detail, fn($d) => $d['cost'] === null)),
            ],
            'by_reason' => $this->group($losses, fn($d) => $d['reason'], fn($d) => ['name' => $d['reason_label']], $lossTotal),
            'gains_by_reason' => $this->group($gains, fn($d) => $d['reason'], fn($d) => ['name' => $d['reason_label']], $gainTotal),
            'by_product' => $this->group($losses, fn($d) => $d['product_id'], fn($d) => [
                'code' => $d['code'], 'name' => $d['product'], 'unit' => $d['product_unit'], 'quantity' => 0.0,
            ], $lossTotal, function (array &$g, array $d) {
                $g['quantity'] += $d['base_quantity'] ?? 0;
            }),
            'by_location' => $this->byLocation($detail),
            'by_user' => $this->group($losses, fn($d) => $d['user'], fn($d) => ['name' => $d['user']], $lossTotal),
            'detail' => $detail,
        ];
    }

    /**
     * Agrupa renglones sumando conteo y costo, del más caro al más barato.
     *
     * @param callable(array): (int|string) $key
     * @param callable(array): array $init
     * @param callable(array&, array): void|null $extra
     */
    private function group(array $rows, callable $key, callable $init, float $total, ?callable $extra = null): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $k = $key($row);
            $groups[$k] ??= $init($row) + ['count' => 0, 'cost' => 0.0];
            $groups[$k]['count']++;
            $groups[$k]['cost'] += $row['cost'] ?? 0;
            if ($extra) {
                $extra($groups[$k], $row);
            }
        }

        $groups = array_values($groups);
        usort($groups, fn($a, $b) => [$b['cost'], $b['count']] <=> [$a['cost'], $a['count']]);

        return array_map(fn($g) => $g + ['share' => $total > 0 ? $g['cost'] / $total : 0.0], $groups);
    }

    private function byLocation(array $detail): array
    {
        $groups = [];
        foreach ($detail as $d) {
            $groups[$d['location']] ??= ['name' => $d['location'], 'loss_count' => 0, 'loss_cost' => 0.0, 'gain_cost' => 0.0];
            if ($d['direction'] === 'out') {
                $groups[$d['location']]['loss_count']++;
                $groups[$d['location']]['loss_cost'] += $d['cost'] ?? 0;
            } else {
                $groups[$d['location']]['gain_cost'] += $d['cost'] ?? 0;
            }
        }

        $groups = array_values($groups);
        usort($groups, fn($a, $b) => $b['loss_cost'] <=> $a['loss_cost']);

        return array_map(fn($g) => $g + ['net' => $g['gain_cost'] - $g['loss_cost']], $groups);
    }

    // ─── Excel ──────────────────────────────────────────────────────────────

    public function sheets(): array
    {
        $d = $this->data();
        $t = $d['totals'];
        $title = fn(string $what) => [
            "{$this->title()} · {$what}",
            $this->scope->company->name . ' · ' . $this->scope->locationLabel(),
            'Periodo: ' . $this->period->label(),
        ];
        $shareColumns = [
            ['label' => 'Registros', 'type' => Spreadsheet::INT, 'width' => 11],
            ['label' => 'Costo', 'type' => Spreadsheet::MONEY, 'width' => 15],
            ['label' => '% de las pérdidas', 'type' => Spreadsheet::PCT, 'width' => 13],
        ];

        return [
            [
                'name' => 'Por motivo',
                'title' => $title('Pérdidas por motivo'),
                'columns' => array_merge([['label' => 'Motivo', 'width' => 26]], $shareColumns),
                'rows' => array_map(fn($g) => [$g['name'], $g['count'], $g['cost'], $g['share']], $d['by_reason']),
                'totals' => ['Total pérdidas', $t['loss_count'], $t['loss_cost'], 1],
            ],
            [
                'name' => 'Por producto',
                'title' => $title('Pérdidas por producto'),
                'columns' => array_merge([
                    ['label' => 'Código', 'width' => 14],
                    ['label' => 'Producto', 'width' => 34],
                    ['label' => 'Cantidad', 'type' => Spreadsheet::QTY, 'width' => 12],
                    ['label' => 'Unidad', 'width' => 9],
                ], $shareColumns),
                'rows' => array_map(fn($g) => [$g['code'], $g['name'], $g['quantity'], $g['unit'], $g['count'], $g['cost'], $g['share']], $d['by_product']),
                'totals' => ['Total', '', null, '', $t['loss_count'], $t['loss_cost'], 1],
            ],
            [
                'name' => 'Por sucursal',
                'title' => $title('Por sucursal'),
                'columns' => [
                    ['label' => 'Sucursal', 'width' => 24],
                    ['label' => 'Pérdidas', 'type' => Spreadsheet::INT, 'width' => 11],
                    ['label' => 'Costo perdido', 'type' => Spreadsheet::MONEY, 'width' => 15],
                    ['label' => 'Recuperado', 'type' => Spreadsheet::MONEY, 'width' => 15],
                    ['label' => 'Neto', 'type' => Spreadsheet::MONEY, 'width' => 15],
                ],
                'rows' => array_map(fn($g) => [$g['name'], $g['loss_count'], $g['loss_cost'], $g['gain_cost'], $g['net']], $d['by_location']),
                'totals' => ['Total', $t['loss_count'], $t['loss_cost'], $t['gain_cost'], $t['net']],
            ],
            [
                'name' => 'Por usuario',
                'title' => $title('Pérdidas por usuario que las registró'),
                'columns' => array_merge([['label' => 'Usuario', 'width' => 26]], $shareColumns),
                'rows' => array_map(fn($g) => [$g['name'], $g['count'], $g['cost'], $g['share']], $d['by_user']),
                'totals' => ['Total', $t['loss_count'], $t['loss_cost'], 1],
            ],
            [
                'name' => 'Detalle',
                'title' => $title('Detalle de ajustes'),
                'columns' => [
                    ['label' => 'Fecha', 'type' => Spreadsheet::DATETIME, 'width' => 17],
                    ['label' => 'Sucursal', 'width' => 20],
                    ['label' => 'Código', 'width' => 14],
                    ['label' => 'Producto', 'width' => 30],
                    ['label' => 'Motivo', 'width' => 22],
                    ['label' => 'Tipo', 'width' => 9],
                    ['label' => 'Cantidad', 'type' => Spreadsheet::QTY, 'width' => 11],
                    ['label' => 'Unidad', 'width' => 9],
                    ['label' => 'Costo', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Usuario', 'width' => 22],
                    ['label' => 'Notas', 'width' => 40],
                ],
                'rows' => array_map(fn($r) => Spreadsheet::row([
                    $r['date'], $r['location'], $r['code'], $r['product'], $r['reason_label'],
                    $r['direction'] === 'out' ? 'Salida' : 'Entrada', $r['quantity'], $r['unit'],
                    $r['direction'] === 'out' && $r['cost'] !== null ? -$r['cost'] : $r['cost'], $r['user'], $r['notes'],
                ], $r['direction'] === 'in' ? 'muted' : null), $d['detail']),
                'totals' => ['Neto', '', '', '', '', '', null, '', $t['net'], '', ''],
            ],
        ];
    }
}
