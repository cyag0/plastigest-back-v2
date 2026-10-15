<?php

namespace App\Services\Replenishment;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pronóstico de demanda diaria, sin IA: con lo que salió de cada producto en
 * cada sucursal en las últimas semanas (ventas menos devoluciones, más lo que
 * se consumió en producción y empaque).
 *
 * Es el modelo clásico de nivel × día de la semana × temporada:
 *
 *  - Día de la semana: los sábados se vende más que los martes. El factor de
 *    cada día sale de todo el historial y se acerca a 1 cuando hay pocos
 *    datos de ese día, para que un sábado raro no dispare el pedido.
 *  - Nivel: cuánto sale en un día típico, ya sin el efecto del día de la
 *    semana y con más peso a lo reciente (lo de hace una semana pesa el
 *    doble que lo de hace dos).
 *  - Temporada: la calcula el planeador con lo del año pasado y llega aquí
 *    como factor (1 sin un año de historial).
 *
 * Los días en que el producto se agotó no cuentan: ese día se vendió lo que
 * había, no lo que se pedía.
 */
final class DemandForecaster
{
    /** Días de historial (8 semanas). */
    public const LOOKBACK_DAYS = 56;

    /** En cuántos días el peso de un dato baja a la mitad. */
    private const HALF_LIFE_DAYS = 7;

    /** Días "promedio" que se suman a cada día de la semana al sacar su factor. */
    private const WEEKDAY_PRIOR_DAYS = 2;

    private const WEEKDAY_FACTOR_MIN = 0.25;

    private const WEEKDAY_FACTOR_MAX = 3.0;

    /**
     * @param array<string, float> $demand demanda por día ('Y-m-d'); los días que faltan valen 0
     * @param array<string, bool> $excluded días que no cuentan porque se agotó
     * @param CarbonInterface $today el historial son los días anteriores a este
     * @param CarbonInterface|null $firstDay desde cuándo hay registro del producto en la sucursal:
     *        antes de esa fecha no es que no se vendiera, es que no había datos
     */
    public function forecast(
        array $demand,
        array $excluded,
        CarbonInterface $today,
        ?CarbonInterface $firstDay = null,
        float $seasonFactor = 1.0,
    ): DemandForecast {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $start = $today->subDays(self::LOOKBACK_DAYS);
        if ($firstDay !== null && $firstDay->greaterThan($start)) {
            $start = CarbonImmutable::instance($firstDay)->startOfDay();
        }

        $days = [];
        $stockoutDays = [];
        $age = 0;
        for ($day = $today->subDay(); $day->greaterThanOrEqualTo($start); $day = $day->subDay()) {
            $age++;
            $key = $day->toDateString();
            $row = ['weekday' => $day->isoWeekday(), 'age' => $age, 'qty' => max(0.0, (float) ($demand[$key] ?? 0))];
            if (!empty($excluded[$key])) {
                $stockoutDays[] = $row;
                continue;
            }
            $days[] = $row;
        }

        // Si se agotó todos los días, lo vendido es lo único que se sabe: es
        // un mínimo, pero mejor que no pedir nada.
        if ($days === []) {
            $days = $stockoutDays;
        }

        $count = count($days);
        $mean = $count > 0 ? array_sum(array_column($days, 'qty')) / $count : 0.0;
        $sellingDays = count(array_filter($days, fn($row) => $row['qty'] > 0));
        if ($mean <= 0) {
            return new DemandForecast(0.0, array_fill(1, 7, 1.0), $seasonFactor, 0.0, $count, count($stockoutDays), $sellingDays);
        }

        $factors = $this->weekdayFactors($days, $mean);

        // Nivel: promedio con más peso a lo reciente, de la demanda ya sin el
        // efecto del día de la semana.
        $decay = 0.5 ** (1 / self::HALF_LIFE_DAYS);
        $weighted = 0.0;
        $weights = 0.0;
        foreach ($days as $row) {
            $weight = $decay ** ($row['age'] - 1);
            $weighted += $weight * $row['qty'] / $factors[$row['weekday']];
            $weights += $weight;
        }
        $level = $weights > 0 ? $weighted / $weights : 0.0;

        // Qué tanto se aleja un día de lo esperado: de ahí sale el margen.
        $squares = 0.0;
        foreach ($days as $row) {
            $squares += ($row['qty'] - $level * $factors[$row['weekday']]) ** 2;
        }
        $deviation = $count > 1 ? sqrt($squares / ($count - 1)) : $level;

        return new DemandForecast($level, $factors, $seasonFactor, $deviation, $count, count($stockoutDays), $sellingDays);
    }

    /**
     * Factor de cada día de la semana: su promedio contra el promedio general,
     * ajustado para que una semana típica sume 7 días de nivel.
     *
     * Se acerca a 1 en proporción a lo disparejo que es ese día: si los
     * sábados siempre se vende parecido se les cree; si un sábado se vendió
     * 30 y el siguiente 2, el factor se modera. Con menos de 3 datos del día
     * se modera siempre.
     *
     * @param array<int, array{weekday: int, qty: float}> $days
     * @return array<int, float>
     */
    private function weekdayFactors(array $days, float $mean): array
    {
        $values = array_fill(1, 7, []);
        foreach ($days as $row) {
            $values[$row['weekday']][] = $row['qty'];
        }

        $factors = [];
        foreach ($values as $weekday => $list) {
            $count = count($list);
            if ($count === 0) {
                $factors[$weekday] = 1.0;
                continue;
            }

            $dayMean = array_sum($list) / $count;
            $raw = $dayMean / $mean;

            $prior = self::WEEKDAY_PRIOR_DAYS;
            if ($count >= 3 && $dayMean > 0) {
                $variance = array_sum(array_map(fn($value) => ($value - $dayMean) ** 2, $list)) / ($count - 1);
                $prior *= min(1.0, $variance / ($dayMean ** 2));
            }

            $shrunk = ($count * $raw + $prior) / ($count + $prior);
            $factors[$weekday] = min(self::WEEKDAY_FACTOR_MAX, max(self::WEEKDAY_FACTOR_MIN, $shrunk));
        }

        $average = array_sum($factors) / 7;

        return array_map(fn($factor) => $factor / $average, $factors);
    }
}
