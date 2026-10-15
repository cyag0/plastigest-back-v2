<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Rango de fechas de un reporte (días completos, en la hora de la app) y el
 * periodo anterior con el que se compara.
 *
 * El anterior de un mes calendario completo es el mes calendario anterior
 * (octubre contra septiembre, aunque no tengan los mismos días); cualquier
 * otro rango se compara con los mismos días inmediatamente antes.
 */
final class ReportPeriod
{
    /** Tope del rango: más de un año ya no se lee en un PDF. */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function between(string $from, string $to): self
    {
        $start = CarbonImmutable::parse($from, config('app.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($to, config('app.timezone'))->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['date_to' => ['La fecha final no puede ser anterior a la inicial.']]);
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['date_from' => ['El periodo no puede pasar de un año.']]);
        }

        return new self($start, $end);
    }

    /** Sin fechas: el mes en curso. */
    public static function fromInput(?string $from, ?string $to): self
    {
        if (!$from || !$to) {
            $today = CarbonImmutable::now(config('app.timezone'));

            return new self($today->startOfMonth(), $today->startOfDay());
        }

        return self::between($from, $to);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function isWholeMonth(): bool
    {
        return $this->from->day === 1
            && $this->from->isSameMonth($this->to)
            && $this->to->day === $this->to->daysInMonth;
    }

    public function previous(): self
    {
        if ($this->isWholeMonth()) {
            $start = $this->from->subMonthNoOverflow()->startOfMonth();

            return new self($start, $start->endOfMonth()->startOfDay());
        }

        return new self($this->from->subDays($this->days()), $this->from->subDay());
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Límites para columnas de fecha y hora (created_at, closed_at…). */
    public function fromDateTime(): string
    {
        return $this->from->startOfDay()->toDateTimeString();
    }

    public function toDateTime(): string
    {
        return $this->to->endOfDay()->toDateTimeString();
    }

    /** "Octubre 2026", "01/10/2026 al 14/10/2026" o "14/10/2026". */
    public function label(): string
    {
        if ($this->isWholeMonth()) {
            return ucfirst($this->from->locale('es')->translatedFormat('F Y'));
        }
        if ($this->from->isSameDay($this->to)) {
            return $this->from->format('d/m/Y');
        }

        return $this->from->format('d/m/Y') . ' al ' . $this->to->format('d/m/Y');
    }
}
