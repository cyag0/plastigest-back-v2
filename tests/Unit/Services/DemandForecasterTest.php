<?php

namespace Tests\Unit\Services;

use App\Services\Replenishment\DemandForecaster;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pronóstico de demanda: nivel reciente × día de la semana, sin los días en
 * que se agotó.
 */
class DemandForecasterTest extends TestCase
{
    /** Jueves. */
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-10-15');
    }

    /**
     * @param callable(CarbonImmutable): float $perDay
     * @return array<string, float>
     */
    private function history(callable $perDay, int $days = DemandForecaster::LOOKBACK_DAYS): array
    {
        $demand = [];
        for ($i = 1; $i <= $days; $i++) {
            $day = $this->today->subDays($i);
            $demand[$day->toDateString()] = $perDay($day);
        }

        return $demand;
    }

    public function test_constant_demand_is_forecast_as_is_with_no_margin(): void
    {
        $forecast = (new DemandForecaster())->forecast($this->history(fn() => 10.0), [], $this->today);

        $this->assertEqualsWithDelta(10.0, $forecast->forDate($this->today->addDay()), 0.001);
        $this->assertEqualsWithDelta(0.0, $forecast->dailyDeviation, 0.001);
        $this->assertSame(DemandForecaster::LOOKBACK_DAYS, $forecast->historyDays);
    }

    public function test_saturdays_sell_more_than_weekdays(): void
    {
        $demand = $this->history(fn(CarbonImmutable $day) => $day->isSaturday() ? 20.0 : 5.0);
        $forecast = (new DemandForecaster())->forecast($demand, [], $this->today);

        $saturday = $this->today->next(CarbonImmutable::SATURDAY);
        $monday = $this->today->next(CarbonImmutable::MONDAY);

        // Los sábados siempre venden parecido: se les cree.
        $this->assertEqualsWithDelta(20.0, $forecast->forDate($saturday), 0.01);
        $this->assertEqualsWithDelta(5.0, $forecast->forDate($monday), 0.01);
    }

    public function test_an_erratic_weekday_is_moderated(): void
    {
        // Los sábados alternan 40 y 0 (promedio 20); el resto, 5 parejo.
        $demand = $this->history(function (CarbonImmutable $day) {
            if (!$day->isSaturday()) {
                return 5.0;
            }

            return intdiv((int) $this->today->diffInDays($day, true), 7) % 2 === 0 ? 40.0 : 0.0;
        });
        $forecast = (new DemandForecaster())->forecast($demand, [], $this->today);

        $saturday = $forecast->forDate($this->today->next(CarbonImmutable::SATURDAY));
        $this->assertLessThan(20.0, $saturday, 'un día disparejo se modera');
        $this->assertGreaterThan(10.0, $saturday);
    }

    public function test_recent_weeks_weigh_more_than_older_ones(): void
    {
        // Las primeras 6 semanas vendía 4 al día; las últimas 2, 12.
        $demand = $this->history(fn(CarbonImmutable $day) => $day->greaterThanOrEqualTo($this->today->subDays(14)) ? 12.0 : 4.0);
        $forecast = (new DemandForecaster())->forecast($demand, [], $this->today);

        $tomorrow = $forecast->forDate($this->today->addDay());
        $this->assertGreaterThan(9.0, $tomorrow, 'pesa más lo reciente');
        $this->assertLessThan(12.0, $tomorrow);
    }

    public function test_days_without_stock_do_not_pull_the_forecast_down(): void
    {
        // Vende 10 al día, pero los últimos 5 días se agotó y solo vendió 2.
        $demand = $this->history(fn(CarbonImmutable $day) => $day->greaterThanOrEqualTo($this->today->subDays(5)) ? 2.0 : 10.0);
        $excluded = [];
        for ($i = 1; $i <= 5; $i++) {
            $excluded[$this->today->subDays($i)->toDateString()] = true;
        }

        $forecast = (new DemandForecaster())->forecast($demand, $excluded, $this->today);

        $this->assertEqualsWithDelta(10.0, $forecast->forDate($this->today->addDay()), 0.01);
        $this->assertSame(5, $forecast->stockoutDays);
    }

    public function test_days_before_the_product_existed_are_not_zero_sales(): void
    {
        // Producto nuevo: lleva 10 días vendiendo 6 al día.
        $demand = $this->history(fn() => 6.0, 10);

        $withStart = (new DemandForecaster())->forecast($demand, [], $this->today, $this->today->subDays(10));
        $withoutStart = (new DemandForecaster())->forecast($demand, [], $this->today);

        $this->assertEqualsWithDelta(6.0, $withStart->forDate($this->today->addDay()), 0.01);
        $this->assertSame(10, $withStart->historyDays);
        $this->assertLessThan(6.0, $withoutStart->forDate($this->today->addDay()));
    }

    public function test_season_factor_scales_the_forecast(): void
    {
        $forecast = (new DemandForecaster())->forecast($this->history(fn() => 10.0), [], $this->today, null, 1.5);

        $this->assertEqualsWithDelta(15.0, $forecast->forDate($this->today->addDay()), 0.001);
    }

    public function test_no_sales_means_no_demand(): void
    {
        $forecast = (new DemandForecaster())->forecast([], [], $this->today);

        $this->assertFalse($forecast->hasDemand());
        $this->assertSame(0.0, $forecast->forDate($this->today->addDay()));
    }
}
