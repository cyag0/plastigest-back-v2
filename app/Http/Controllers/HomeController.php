<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Services\CashRegisterSessionService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Inicio: "el día de la sucursal" en una sola llamada.
 *
 * Cada bloque sale sólo si el usuario tiene el permiso de ese módulo (antes
 * Inicio pedía estadísticas que el usuario no podía ver y mostraba $0). Las
 * ventas se comparan con ayer *a la misma hora*: comparar el día en curso
 * contra el día completo de ayer daba "-100%" cada mañana.
 */
class HomeController extends Controller
{
    public function __construct(private readonly CashRegisterSessionService $sessions) {}

    public function summary(): JsonResponse
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        return response()->json([
            'location' => CurrentLocation::get()?->only(['id', 'name']),
            'sales' => CurrentWorker::hasPermission('sales_list') ? $this->sales($companyId, $locationId) : null,
            'cash' => $this->cash($companyId, $locationId),
            'alerts' => $this->alerts($companyId, $locationId),
            'production_today' => CurrentWorker::hasPermission('production_orders_list')
                ? DB::table('production_orders')
                    ->where('company_id', $companyId)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->whereDate('production_date', today())
                    ->whereIn('status', ['completed', 'packaging', 'packed'])
                    ->count()
                : null,
        ]);
    }

    /** Ventas de hoy, ayer a esta hora y por hora (para la gráfica). */
    private function sales(?int $companyId, ?int $locationId): array
    {
        $base = fn() => DB::table('sales')
            ->where('company_id', $companyId)
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at');

        $now = now();
        $today = (clone $base())->whereBetween('created_at', [$now->copy()->startOfDay(), $now])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')->first();
        $yesterdayNow = $now->copy()->subDay();
        $yesterdaySoFar = (float) (clone $base())->whereBetween('created_at', [$yesterdayNow->copy()->startOfDay(), $yesterdayNow])->sum('total');
        $yesterdayTotal = (float) (clone $base())->whereDate('created_at', $yesterdayNow->toDateString())->sum('total');

        $byHour = fn($day) => (clone $base())->whereDate('created_at', $day)
            ->selectRaw('HOUR(created_at) as h, SUM(total) as t')->groupBy('h')->pluck('t', 'h');
        $hoursToday = $byHour($now->toDateString());
        $hoursYesterday = $byHour($yesterdayNow->toDateString());

        return [
            'today_total' => round((float) $today->total, 2),
            'today_count' => (int) $today->count,
            'average_ticket' => $today->count > 0 ? round((float) $today->total / $today->count, 2) : 0,
            'yesterday_same_time' => round($yesterdaySoFar, 2),
            'yesterday_total' => round($yesterdayTotal, 2),
            'by_hour' => collect(range(0, 23))->map(fn($h) => [
                'hour' => $h,
                'today' => round((float) ($hoursToday[$h] ?? 0), 2),
                'yesterday' => round((float) ($hoursYesterday[$h] ?? 0), 2),
            ])->all(),
        ];
    }

    /** Mi turno abierto y las cajas abiertas de la sucursal. */
    private function cash(?int $companyId, ?int $locationId): ?array
    {
        $canPos = CurrentWorker::hasPermission('sales_pos');
        $canSee = CurrentWorker::hasPermission('cash_register_sessions_list');
        if (!$canPos && !$canSee) {
            return null;
        }

        $mine = null;
        if ($canPos) {
            $session = CashRegisterSession::with('cashRegister')
                ->where('company_id', $companyId)
                ->where('user_id', Auth::id())
                ->where('status', 'open')
                ->first();
            if ($session) {
                $summary = $this->sessions->summary($session);
                $mine = [
                    'id' => $session->id,
                    'register' => $session->cashRegister?->name,
                    'location' => $session->location_id !== $locationId ? \App\Models\Admin\Location::find($session->location_id)?->name : null,
                    'stale' => $session->opened_at !== null && $session->opened_at->lt(today()),
                    'opened_at' => $session->opened_at?->toISOString(),
                    'sales_count' => $summary['sales_count'],
                    'sales_total' => $summary['sales_total'],
                    'expected_cash' => $summary['expected_cash'],
                ];
            }
        }

        $open = $canSee && $locationId
            ? CashRegisterSession::with(['cashRegister', 'user'])
                ->where('company_id', $companyId)
                ->where('location_id', $locationId)
                ->where('status', 'open')
                ->orderBy('opened_at')
                ->get()
                ->map(fn($s) => [
                    'id' => $s->id,
                    'register' => $s->cashRegister?->name,
                    'user' => $s->user?->name,
                    'opened_at' => $s->opened_at?->toISOString(),
                    // Abierto desde otro día: casi siempre un turno que se
                    // olvidó cerrar y que descuadra el corte.
                    'stale' => $s->opened_at !== null && $s->opened_at->lt(today()),
                ])->all()
            : [];

        return ['mine' => $mine, 'open_in_branch' => $open, 'can_sell' => $canPos];
    }

    /**
     * Lo que hay que atender, sólo lo que el usuario puede resolver. Cada
     * aviso trae a dónde ir.
     */
    private function alerts(?int $companyId, ?int $locationId): array
    {
        $alerts = [];

        if (CurrentWorker::hasPermission('inventory_list') && $locationId) {
            $stock = DB::table('product_location')
                ->join('products', 'products.id', '=', 'product_location.product_id')
                ->where('products.company_id', $companyId)
                ->where('product_location.location_id', $locationId)
                ->where('product_location.active', true)
                ->selectRaw('SUM(product_location.current_stock <= 0) as out_of_stock')
                ->selectRaw('SUM(product_location.minimum_stock > 0 AND product_location.current_stock > 0 AND product_location.current_stock < product_location.minimum_stock) as low_stock')
                ->first();

            $alerts[] = ['key' => 'out_of_stock', 'count' => (int) ($stock->out_of_stock ?? 0), 'tone' => 'error'];
            $alerts[] = ['key' => 'low_stock', 'count' => (int) ($stock->low_stock ?? 0), 'tone' => 'warning'];
        }

        if (CurrentWorker::hasPermission('transfers_list') && $locationId) {
            $transfers = DB::table('inventory_transfers')->where('company_id', $companyId);
            $alerts[] = ['key' => 'transfers_to_receive', 'tone' => 'info',
                'count' => (clone $transfers)->where('to_location_id', $locationId)->where('status', 'in_transit')->count()];
            $alerts[] = ['key' => 'transfers_to_approve', 'tone' => 'warning',
                'count' => (clone $transfers)->where('from_location_id', $locationId)->where('status', 'pending')->count()];
            $alerts[] = ['key' => 'transfers_to_ship', 'tone' => 'warning',
                'count' => (clone $transfers)->where('from_location_id', $locationId)->where('status', 'approved')->count()];
        }

        if (CurrentWorker::hasPermission('purchases_list') && $locationId) {
            $alerts[] = ['key' => 'purchases_in_transit', 'tone' => 'info',
                'count' => DB::table('purchases')->where('company_id', $companyId)
                    ->where('location_id', $locationId)->where('status', 'in_transit')->count()];
        }

        $alerts[] = ['key' => 'tasks_overdue', 'tone' => 'error',
            'count' => DB::table('tasks')->where('company_id', $companyId)
                ->where('assigned_to', Auth::id())
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereNotNull('due_date')->where('due_date', '<', now())
                ->count()];

        return array_values(array_filter($alerts, fn($a) => $a['count'] > 0));
    }
}
