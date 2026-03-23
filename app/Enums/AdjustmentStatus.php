<?php

namespace App\Enums;

enum AdjustmentStatus: string
{
    case DRAFT = 'draft';
    case APPLIED = 'applied';
    case CANCELLED = 'cancelled';

    /**
     * Obtener la etiqueta legible del estado
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::APPLIED => 'Aplicado',
            self::CANCELLED => 'Cancelado',
        };
    }

    /**
     * Obtener el color para el estado
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'info',
            self::APPLIED => 'success',
            self::CANCELLED => 'danger',
        };
    }
}
