<?php

namespace App\Services\Replenishment;

use Carbon\CarbonInterface;

/**
 * Pronóstico de demanda diaria de un producto en una sucursal (ver
 * DemandForecaster). Todo va en la unidad base del producto.
 */
final class DemandForecast
{
    /**
     * @param float $dailyLevel lo que sale en un día típico reciente, sin el efecto del día de la semana
     * @param array<int, float> $weekdayFactors día ISO (1 = lunes … 7 = domingo) => factor
     * @param float $seasonFactor temporada (1 = sin ajuste)
     * @param float $dailyDeviation qué tanto se aleja un día de su pronóstico (desviación estándar)
     * @param int $historyDays días de historial que se usaron
     * @param int $stockoutDays días que no se usaron porque se agotó
     * @param int $sellingDays días del historial en que sí salió algo
     */
    public function __construct(
        public readonly float $dailyLevel,
        public readonly array $weekdayFactors,
        public readonly float $seasonFactor,
        public readonly float $dailyDeviation,
        public readonly int $historyDays,
        public readonly int $stockoutDays,
        public readonly int $sellingDays = 0,
    ) {}

    public static function none(): self
    {
        return new self(0.0, array_fill(1, 7, 1.0), 1.0, 0.0, 0, 0, 0);
    }

    public function weekdayFactor(CarbonInterface $date): float
    {
        return $this->weekdayFactors[$date->isoWeekday()] ?? 1.0;
    }

    /** Lo que se espera que salga ese día. */
    public function forDate(CarbonInterface $date): float
    {
        return $this->dailyLevel * $this->weekdayFactor($date) * $this->seasonFactor;
    }

    public function hasDemand(): bool
    {
        return $this->dailyLevel > 0;
    }
}
