<?php

namespace App\Enums;

enum SaleStatus: string
{
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::CLOSED => 'Cerrada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CLOSED => 'green',
            self::CANCELLED => 'red',
        };
    }
}
