<?php

namespace App\Reports;

use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\DB;

/**
 * Diferencias de caja: los turnos cerrados en el periodo con lo esperado, lo
 * contado y la diferencia, y por cajero cuántas veces le sobró o le faltó.
 *
 * Una auditoría sencilla sobre lo que ya guarda el cierre de turno
 * (CashRegisterSessionService::close). Se marca a quien le falta seguido:
 * dos faltantes o más en el periodo, o en al menos la mitad de sus turnos.
 */
final class CashDifferencesReport implements BusinessReport
{
    /** Faltantes en el periodo a partir de los cuales se marca al cajero. */
    public const REPEATED_SHORTAGES = 2;

    private ?array $data = null;

    public function __construct(
        private readonly ReportScope $scope,
        private readonly ReportPeriod $period,
    ) {}

    public function title(): string
    {
        return 'Diferencias de caja';
    }

    public function fileName(): string
    {
        return 'diferencias-caja-' . $this->period->fromDate() . '-a-' . $this->period->toDate();
    }

    public function view(): string
    {
        return 'pdf.reports.cash-differences';
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

        $sessions = DB::table('cash_register_sessions')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('locations', 'locations.id', '=', 'cash_register_sessions.location_id')
            ->leftJoin('users AS cashiers', 'cashiers.id', '=', 'cash_register_sessions.user_id')
            ->leftJoin('users AS closers', 'closers.id', '=', 'cash_register_sessions.closed_by')
            ->where('cash_register_sessions.company_id', $this->scope->companyId())
            ->when($this->scope->locationId(), fn($q, $id) => $q->where('cash_register_sessions.location_id', $id))
            ->where('cash_register_sessions.status', CashRegisterSession::STATUS_CLOSED)
            ->whereBetween('cash_register_sessions.closed_at', [$this->period->fromDateTime(), $this->period->toDateTime()])
            ->orderBy('cash_register_sessions.closed_at')
            ->get([
                'cash_register_sessions.id', 'cash_register_sessions.user_id', 'cash_register_sessions.opened_at',
                'cash_register_sessions.closed_at', 'cash_register_sessions.opening_amount', 'cash_register_sessions.expected_cash',
                'cash_register_sessions.counted_cash', 'cash_register_sessions.difference', 'cash_register_sessions.notes',
                'cash_registers.name AS register', 'locations.name AS location',
                'cashiers.name AS cashier', 'closers.name AS closed_by',
            ])
            ->map(fn($s) => [
                'id' => (int) $s->id,
                'user_id' => (int) $s->user_id,
                'opened_at' => $s->opened_at,
                'closed_at' => $s->closed_at,
                'location' => $s->location,
                'register' => $s->register,
                'cashier' => $s->cashier ?? 'Sin usuario',
                'closed_by' => $s->closed_by,
                'opening' => (float) $s->opening_amount,
                'expected' => (float) $s->expected_cash,
                'counted' => (float) $s->counted_cash,
                'difference' => round((float) $s->difference, 2),
                'notes' => $s->notes,
            ])
            ->all();

        $cashiers = [];
        foreach ($sessions as $s) {
            $c = &$cashiers[$s['user_id']];
            $c ??= [
                'name' => $s['cashier'], 'sessions' => 0, 'short_count' => 0, 'over_count' => 0,
                'short_total' => 0.0, 'over_total' => 0.0, 'worst_short' => 0.0,
            ];
            $c['sessions']++;
            if ($s['difference'] < 0) {
                $c['short_count']++;
                $c['short_total'] += $s['difference'];
                $c['worst_short'] = min($c['worst_short'], $s['difference']);
            } elseif ($s['difference'] > 0) {
                $c['over_count']++;
                $c['over_total'] += $s['difference'];
            }
            unset($c);
        }

        $cashiers = array_map(fn($c) => $c + [
            'net' => $c['short_total'] + $c['over_total'],
            'with_difference' => $c['sessions'] > 0 ? ($c['short_count'] + $c['over_count']) / $c['sessions'] : 0.0,
            'flagged' => $c['short_count'] >= self::REPEATED_SHORTAGES
                || ($c['short_count'] > 0 && $c['short_count'] * 2 >= $c['sessions']),
        ], array_values($cashiers));

        // Primero a quien más le falta.
        usort($cashiers, fn($a, $b) => [$a['short_total'], -$a['short_count']] <=> [$b['short_total'], -$b['short_count']]);

        $short = array_filter($sessions, fn($s) => $s['difference'] < 0);
        $over = array_filter($sessions, fn($s) => $s['difference'] > 0);

        return $this->data = [
            'scope' => $this->scope,
            'period' => $this->period,
            'sessions' => $sessions,
            'cashiers' => $cashiers,
            'totals' => [
                'sessions' => count($sessions),
                'exact' => count($sessions) - count($short) - count($over),
                'short_count' => count($short),
                'short_total' => array_sum(array_column($short, 'difference')),
                'over_count' => count($over),
                'over_total' => array_sum(array_column($over, 'difference')),
                'net' => array_sum(array_column($sessions, 'difference')),
                'flagged' => count(array_filter($cashiers, fn($c) => $c['flagged'])),
            ],
        ];
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

        return [
            [
                'name' => 'Por cajero',
                'title' => $title('Por cajero'),
                'columns' => [
                    ['label' => 'Cajero', 'width' => 26],
                    ['label' => 'Turnos', 'type' => Spreadsheet::INT, 'width' => 9],
                    ['label' => 'Con faltante', 'type' => Spreadsheet::INT, 'width' => 12],
                    ['label' => 'Total faltante', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Mayor faltante', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Con sobrante', 'type' => Spreadsheet::INT, 'width' => 12],
                    ['label' => 'Total sobrante', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Neto', 'type' => Spreadsheet::MONEY, 'width' => 13],
                    ['label' => 'Turnos con diferencia', 'type' => Spreadsheet::PCT, 'width' => 13],
                    ['label' => 'Atención', 'width' => 18],
                ],
                'rows' => array_map(fn($c) => Spreadsheet::row([
                    $c['name'], $c['sessions'], $c['short_count'], $c['short_total'], $c['worst_short'] ?: null,
                    $c['over_count'], $c['over_total'], $c['net'], $c['with_difference'],
                    $c['flagged'] ? 'Faltantes repetidos' : '',
                ], $c['flagged'] ? 'danger' : null), $d['cashiers']),
                'totals' => ['Total', $t['sessions'], $t['short_count'], $t['short_total'], null, $t['over_count'], $t['over_total'], $t['net'], null, ''],
            ],
            [
                'name' => 'Turnos',
                'title' => $title('Turnos cerrados'),
                'columns' => [
                    ['label' => 'Turno', 'type' => Spreadsheet::INT, 'width' => 8],
                    ['label' => 'Abrió', 'type' => Spreadsheet::DATETIME, 'width' => 17],
                    ['label' => 'Cerró', 'type' => Spreadsheet::DATETIME, 'width' => 17],
                    ['label' => 'Sucursal', 'width' => 20],
                    ['label' => 'Caja', 'width' => 14],
                    ['label' => 'Cajero', 'width' => 24],
                    ['label' => 'Fondo inicial', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Esperado', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Contado', 'type' => Spreadsheet::MONEY, 'width' => 14],
                    ['label' => 'Diferencia', 'type' => Spreadsheet::MONEY, 'width' => 13],
                    ['label' => 'Cerrado por', 'width' => 22],
                    ['label' => 'Notas', 'width' => 36],
                ],
                'rows' => array_map(fn($s) => Spreadsheet::row([
                    $s['id'], $s['opened_at'], $s['closed_at'],
                    $s['location'], $s['register'], $s['cashier'], $s['opening'], $s['expected'], $s['counted'],
                    $s['difference'], $s['closed_by'], $s['notes'],
                ], $s['difference'] < 0 ? 'danger' : ($s['difference'] > 0 ? 'warning' : null)), $d['sessions']),
                'totals' => ['', '', '', '', '', 'Neto', null, null, null, $t['net'], '', ''],
            ],
        ];
    }
}
