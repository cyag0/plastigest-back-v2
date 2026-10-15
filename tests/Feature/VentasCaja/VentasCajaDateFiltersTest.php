<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Filtros de fecha que usan los índices: las columnas DATE (sale_date,
 * movement_date, expense_date, closing_date) se comparan directo y opened_at
 * por rangos, en lugar de envolverlas en DATE()/DATE_FORMAT(), que obligaba a
 * recorrer todo el historial de la sucursal en pantallas que se abren a cada
 * rato (Ventas, Caja). Los resultados son los mismos.
 */
class VentasCajaDateFiltersTest extends VentasCajaTestCase
{
    private function sale(float $total, string $date, ?Location $location = null): Sale
    {
        $location ??= $this->location;

        return Sale::create([
            'company_id' => $location->company_id, 'location_id' => $location->id, 'user_id' => $this->user->id,
            'sale_date' => $date, 'status' => 'closed',
            'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total, 'paid_amount' => $total,
            'payment_method' => 'card', 'payment_status' => 'paid',
        ]);
    }

    private function movement(string $type, float $amount, string $date): CashMovement
    {
        return CashMovement::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'type' => $type, 'amount' => $amount, 'concept' => 'Prueba', 'payment_method' => 'cash',
            'movement_date' => $date,
        ]);
    }

    /** Ninguna consulta envuelve una columna de fecha en DATE()/DATE_FORMAT() para filtrar. */
    private function assertNoDateWrappedFilters(callable $request): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/where.*(date\(`?(sales`?\.`?)?(sale_date|movement_date|expense_date|closing_date|opened_at)`?\)|DATE_FORMAT\(movement_date, \'%Y-%m\'\) = )/i',
                $sql,
            );
        }
    }

    public function test_the_sales_list_filters_by_sale_date(): void
    {
        $this->sale(10, now()->subDays(2)->toDateString());
        $this->sale(20, now()->subDay()->toDateString());
        $this->sale(30, now()->toDateString());
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $this->assertNoDateWrappedFilters(function () use ($from, $to) {
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales?date_from={$from}&date_to={$to}")
                ->assertOk()
                ->assertJsonCount(2, 'data');
        });
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales?start_date={$from}T00:00:00&end_date={$from}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_stats_default_to_the_last_30_days(): void
    {
        $this->sale(100, now()->subDays(40)->toDateString());
        $this->sale(50, now()->toDateString());

        $this->assertNoDateWrappedFilters(function () {
            $response = $this->actingInBranch($this->user)->getJson('/api/auth/admin/sales/stats')
                ->assertOk()
                ->assertJsonPath('data.overview.total_amount', 50)
                ->assertJsonPath('data.overview.today_amount', 50)
                ->assertJsonPath('data.period.start_date', now()->subDays(29)->toDateString())
                ->assertJsonPath('data.period.end_date', now()->toDateString());

            // La tendencia sigue mostrando los últimos 6 meses.
            $this->assertEquals(150, collect($response->json('data.sales_trend'))->sum('total'));
        });

        // Con rango pedido, el rango.
        $this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/sales/stats?start_date=' . now()->subDays(60)->toDateString() . '&end_date=' . now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 150);
    }

    public function test_cash_stats_use_date_ranges_with_the_same_totals(): void
    {
        // A media quincena: hay días del mes antes de hoy.
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $this->movement('income', 100, now()->toDateString());
        $this->movement('expense', 30, now()->toDateString());
        $this->movement('income', 70, now()->startOfMonth()->toDateString());
        $this->movement('income', 500, now()->subMonthNoOverflow()->startOfMonth()->toDateString());
        CashClosing::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'closing_date' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'physical_count' => 500,
        ]);

        $today = now()->toDateString();
        $this->assertNoDateWrappedFilters(function () use ($today) {
            $response = $this->actingInBranch($this->user)->getJson("/api/auth/admin/cash-movements/stats?date={$today}")->assertOk();

            $this->assertEquals(170, $response->json('total_income'), 'ingresos del mes');
            $this->assertEquals(30, $response->json('total_expense'));
            $this->assertEquals(100, $response->json('today_income'));
            $this->assertEquals(30, $response->json('today_expense'));
            $this->assertEquals(100, $response->json('date_stats.date_income'));
            // Apertura: el conteo del último corte más el efectivo desde entonces (antes de hoy).
            $this->assertEquals(570, $response->json('date_stats.opening_balance'));
        });
    }

    public function test_closings_and_shifts_lists_filter_by_date(): void
    {
        foreach ([3, 1, 0] as $daysAgo) {
            CashClosing::create([
                'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
                'closing_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $this->assertNoDateWrappedFilters(function () use ($from, $to) {
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/cash-closings?date_from={$from}&date_to={$to}")
                ->assertOk()
                ->assertJsonCount(2, 'data');
        });

        $this->cashier();
        $this->assertNoDateWrappedFilters(function () use ($to) {
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/cash-register-sessions?date_from={$to}&date_to={$to}")
                ->assertOk()
                ->assertJsonCount(1, 'data');
        });
        $this->actingInBranch($this->user)->getJson('/api/auth/admin/cash-register-sessions?date_to=' . now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
