<?php

namespace App\Enums;

/**
 * Estados de una orden de producción (o de envasado).
 *
 *   draft ──► completed
 *     │            │
 *     └────────────┴──► cancelled
 *
 * Un borrador ("Pendiente") todavía no mueve inventario: se guardó para
 * terminarlo después. Al registrarse se descuentan los insumos, se suman los
 * productos y se aplica el envasado, todo junto.
 *
 * PACKAGING y PACKED son del flujo anterior, en el que el empaque era una
 * etapa aparte de la orden ("En empaque" → "Empacada"). Ya nada transiciona a
 * ellos: el envasado se registra junto con la producción o como una orden de
 * envasado propia. Se conservan para poder leer órdenes viejas.
 */
enum ProductionOrderStatus: string
{
    case DRAFT = 'draft';
    case COMPLETED = 'completed';
    case PACKAGING = 'packaging';
    case PACKED = 'packed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Pendiente',
            self::COMPLETED => 'Registrada',
            self::PACKAGING => 'En empaque',
            self::PACKED => 'Empacada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#C47F12',     // ocre (aviso)
            self::COMPLETED, self::PACKAGING, self::PACKED => '#3F8A3A', // verde
            self::CANCELLED => '#B9412C', // ladrillo
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'progress-clock',
            self::COMPLETED, self::PACKAGING, self::PACKED => 'check-circle-outline',
            self::CANCELLED => 'close-circle-outline',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT => in_array($next, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED, self::PACKAGING, self::PACKED => $next === self::CANCELLED,
            self::CANCELLED => false,
        };
    }

    /**
     * La orden ya afectó inventario.
     */
    public function isProduced(): bool
    {
        return in_array($this, [self::COMPLETED, self::PACKAGING, self::PACKED], true);
    }

    /**
     * Devuelve un array listo para selects en frontend (sin los estados del
     * flujo anterior, que ya no se pueden alcanzar).
     */
    public static function options(): array
    {
        return array_map(
            fn(self $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
                'icon' => $s->icon(),
            ],
            [self::DRAFT, self::COMPLETED, self::CANCELLED]
        );
    }
}
