<?php

namespace App\Reports;

use Carbon\Carbon;

/**
 * Formatos de los PDF de reportes (pesos, cantidades, porcentajes, fechas).
 */
final class Format
{
    public static function money(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value < 0 ? '-$' : '$') . number_format(abs($value), 2);
    }

    /** Hasta 3 decimales, sin ceros de sobra: 12, 12.5, 0.125. */
    public static function qty(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }

    /** Fracción a porcentaje: 0.256 → "25.6 %". */
    public static function pct(?float $fraction, int $decimals = 1): string
    {
        return $fraction === null ? '—' : number_format($fraction * 100, $decimals) . ' %';
    }

    /** Cambio contra el periodo anterior: "▲ 12.5 %", "▼ 3.0 %" o "—" si antes no hubo. */
    public static function change(float $now, float $before): string
    {
        if ($before <= 0) {
            return $now > 0 ? 'nuevo' : '—';
        }

        $change = ($now - $before) / $before;
        if (abs($change) < 0.0005) {
            return '= 0.0 %';
        }

        return ($change > 0 ? '▲ ' : '▼ ') . number_format(abs($change) * 100, 1) . ' %';
    }

    public static function changeClass(float $now, float $before, bool $higherIsBetter = true): string
    {
        if ($before <= 0 || abs($now - $before) < 0.005) {
            return '';
        }

        return ($now > $before) === $higherIsBetter ? 'up' : 'down';
    }

    public static function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i') : '—';
    }

    public static function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '—';
    }
}
