<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashMovementResource;
use App\Models\CashMovement;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CashMovementController extends CrudController
{
    protected string $resource = CashMovementResource::class;
    protected string $model = CashMovement::class;
    protected ?string $permissionPrefix = 'cash_movements';
    protected string $dateColumn = 'movement_date';

    // -------------------------------------------------------------------------
    // Relaciones
    // -------------------------------------------------------------------------

    protected function indexRelations(): array
    {
        return ['user', 'location'];
    }

    protected function getShowRelations(): array
    {
        return ['user', 'location'];
    }

    // -------------------------------------------------------------------------
    // Filtros personalizados
    // -------------------------------------------------------------------------

    protected function handleQuery($query, array $params): void
    {
        $companyId  = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        // Scope obligatorio a empresa y sucursal actual
        $query->where('company_id', $companyId);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        // Filtro por tipo
        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        // Filtro por método de pago
        if (!empty($params['payment_method'])) {
            $query->where('payment_method', $params['payment_method']);
        }

        // Búsqueda por concepto
        if (!empty($params['search'])) {
            $query->where('concept', 'like', '%' . $params['search'] . '%');
        }

        // Ordenar por fecha descendente por defecto
        if (empty($params['sort_by'])) {
            $query->orderBy('movement_date', 'desc')
                ->orderBy('created_at', 'desc');
        }
    }

    // -------------------------------------------------------------------------
    // Validaciones
    // -------------------------------------------------------------------------

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'type'           => 'required|in:income,expense,adjustment',
            'amount'         => 'required|numeric|min:0.01',
            'concept'        => 'required|string|max:255',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'movement_date'  => 'required|date',
            'source_type'    => 'nullable|string|max:50',
            'source_id'      => 'nullable|integer',
            'source_url'     => 'nullable|string|max:500',
            'notes'          => 'nullable|string|max:2000',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'type'           => 'sometimes|in:income,expense,adjustment',
            'amount'         => 'sometimes|numeric|min:0.01',
            'concept'        => 'sometimes|string|max:255',
            'payment_method' => 'sometimes|in:cash,card,transfer,other',
            'movement_date'  => 'sometimes|date',
            'source_type'    => 'nullable|string|max:50',
            'source_id'      => 'nullable|integer',
            'source_url'     => 'nullable|string|max:500',
            'notes'          => 'nullable|string|max:2000',
        ]);
    }

    // -------------------------------------------------------------------------
    // Hooks de store/update
    // -------------------------------------------------------------------------

    protected function processStoreData(array $validatedData, Request $request): array
    {
        $validatedData['company_id']  = CurrentCompany::id();
        $validatedData['location_id'] = CurrentLocation::id();
        $validatedData['user_id']     = Auth::id();

        return $validatedData;
    }

    // -------------------------------------------------------------------------
    // Reglas de negocio para eliminar
    // -------------------------------------------------------------------------

    protected function canDelete(Model $model): array
    {
        // Solo el creador o usuarios con permiso pueden eliminar
        return ['can_delete' => true];
    }

    // -------------------------------------------------------------------------
    // Endpoint de estadísticas
    // -------------------------------------------------------------------------

    public function stats(Request $request)
    {
        if (!$this->canIndex()) {
            return $this->forbiddenResponse('ver estadísticas de caja');
        }

        $companyId  = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        $baseQuery = CashMovement::where('company_id', $companyId);
        if ($locationId) {
            $baseQuery->where('location_id', $locationId);
        }

        $now   = now();
        $month = $now->format('Y-m');

        // Balance actual (total histórico de la sucursal)
        $balanceRaw = (clone $baseQuery)->selectRaw("
            SUM(CASE WHEN type IN ('income', 'adjustment') THEN amount ELSE 0 END)
            - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS balance
        ")->value('balance');

        $balanceActual = (float) ($balanceRaw ?? 0);

        // Totales del mes actual
        $monthQuery = (clone $baseQuery)->whereRaw("DATE_FORMAT(movement_date, '%Y-%m') = ?", [$month]);

        $monthTotals = (clone $monthQuery)->selectRaw("
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS total_expense
        ")->first();

        // Totales de hoy
        $todayTotals = (clone $baseQuery)->whereDate('movement_date', $now->toDateString())
            ->selectRaw("
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS today_income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS today_expense,
                COUNT(*) AS today_count
            ")->first();

        // Desglose del balance por método de pago (total histórico)
        $byPaymentMethod = (clone $baseQuery)
            ->selectRaw("
                payment_method,
                SUM(CASE WHEN type IN ('income', 'adjustment') THEN amount ELSE 0 END)
                - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS balance,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS total_expense
            ")
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method')
            ->map(fn($row) => [
                'balance'       => (float) $row->balance,
                'total_income'  => (float) $row->total_income,
                'total_expense' => (float) $row->total_expense,
                'label'         => CashMovement::PAYMENT_METHODS[$row->payment_method] ?? $row->payment_method,
            ]);

        // Tendencia mensual (últimos 6 meses)
        $monthlyTrend = (clone $baseQuery)
            ->selectRaw("
                DATE_FORMAT(movement_date, '%Y-%m') AS month,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense,
                SUM(CASE WHEN type IN ('income', 'adjustment') THEN amount ELSE 0 END)
                - SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS net
            ")
            ->where('movement_date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->groupBy(DB::raw("DATE_FORMAT(movement_date, '%Y-%m')"))
            ->orderBy('month')
            ->get();

        // Estadísticas por fecha específica (para cierres de caja)
        $dateStats = null;
        if ($request->filled('date')) {
            $dateQuery = (clone $baseQuery)->whereDate('movement_date', $request->input('date'));

            $dateTotals = (clone $dateQuery)->selectRaw("
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS date_income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS date_expense,
                COUNT(*) AS date_count
            ")->first();

            $dateByMethod = (clone $dateQuery)
                ->selectRaw("
                    payment_method,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS total_income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS total_expense
                ")
                ->groupBy('payment_method')
                ->get()
                ->keyBy('payment_method')
                ->map(fn ($row) => [
                    'total_income'  => (float) $row->total_income,
                    'total_expense' => (float) $row->total_expense,
                ]);

            $dateStats = [
                'date'              => $request->input('date'),
                'date_income'       => (float) ($dateTotals->date_income ?? 0),
                'date_expense'      => (float) ($dateTotals->date_expense ?? 0),
                'date_count'        => (int)   ($dateTotals->date_count ?? 0),
                'by_payment_method' => $dateByMethod,
            ];
        }

        return response()->json([
            'balance_actual'    => $balanceActual,
            'total_income'      => (float) ($monthTotals->total_income ?? 0),
            'total_expense'     => (float) ($monthTotals->total_expense ?? 0),
            'today_income'      => (float) ($todayTotals->today_income ?? 0),
            'today_expense'     => (float) ($todayTotals->today_expense ?? 0),
            'today_count'       => (int)   ($todayTotals->today_count ?? 0),
            'by_payment_method' => $byPaymentMethod,
            'monthly_trend'     => $monthlyTrend,
            'date_stats'        => $dateStats,
        ]);
    }
}
