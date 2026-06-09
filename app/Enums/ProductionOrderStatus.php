<?php

namespace App\Enums;

enum ProductionOrderStatus: string
{
    case DRAFT = 'draft';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::COMPLETED => 'Completada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#9CA3AF',     // gris
            self::COMPLETED => '#10B981', // verde
            self::CANCELLED => '#EF4444', // rojo
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-document-outline',
            self::COMPLETED => 'check-circle',
            self::CANCELLED => 'close-circle',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT => in_array($next, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED => $next === self::CANCELLED,
            self::CANCELLED => false,
        };
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
