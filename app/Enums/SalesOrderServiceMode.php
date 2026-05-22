<?php

namespace App\Enums;

enum SalesOrderServiceMode: string
{
    case COUNTER = 'counter';
    case DELIVERY = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::COUNTER => 'Mostrador',
            self::DELIVERY => 'Entrega',
        };
    }
}