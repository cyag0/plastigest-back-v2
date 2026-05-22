<?php

namespace App\Enums;

enum SalesOrderChannel: string
{
    case KIOSK = 'kiosk';
    case PHONE = 'phone';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::KIOSK => 'Kiosko',
            self::PHONE => 'Teléfono',
            self::ADMIN => 'Administración',
        };
    }
}