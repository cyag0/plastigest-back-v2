<?php

namespace App\Enums;

/**
 * Estados de una orden de producción.
 *
 * El empaque es un paso posterior: hasta que la producción no se completa el
 * granel no existe en inventario, así que no hay nada que empacar. De ahí que
 * PACKAGING y PACKED sólo se alcancen desde COMPLETED.
 *
 *   draft ──► completed ──► packaging ──► packed
 *     │            │             │           │
 *     └────────────┴─────────────┴───────────┴──► cancelled
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
            self::DRAFT => 'Borrador',
            self::COMPLETED => 'Producción terminada',
            self::PACKAGING => 'En empaque',
            self::PACKED => 'Empacada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#9CA3AF',     // gris
            self::COMPLETED => '#10B981', // verde
            self::PACKAGING => '#3B82F6', // azul
            self::PACKED => '#6A1B9A',    // morado
            self::CANCELLED => '#EF4444', // rojo
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-document-outline',
            self::COMPLETED => 'check-circle',
            self::PACKAGING => 'package-variant',
            self::PACKED => 'package-check',
            self::CANCELLED => 'close-circle',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT => in_array($next, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED => in_array($next, [self::PACKAGING, self::PACKED, self::CANCELLED], true),
            self::PACKAGING => in_array($next, [self::PACKED, self::CANCELLED], true),
            self::PACKED => $next === self::CANCELLED,
            self::CANCELLED => false,
        };
    }

    /**
     * La producción ya afectó inventario: el granel existe y se puede empacar.
     */
    public function isProduced(): bool
    {
        return in_array($this, [self::COMPLETED, self::PACKAGING, self::PACKED], true);
    }

    /**
     * Se pueden registrar (o revertir) empaques en este estado.
     */
    public function allowsPackaging(): bool
    {
        return in_array($this, [self::COMPLETED, self::PACKAGING], true);
    }

    /**
     * Devuelve un array listo para selects en frontend.
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
            self::cases()
        );
    }
}
