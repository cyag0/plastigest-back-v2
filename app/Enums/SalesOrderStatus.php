<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case PENDING = 'pending';
    case PREPARING = 'preparing';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::PREPARING => 'Preparando',
            self::IN_TRANSIT => 'En tránsito',
            self::DELIVERED => 'Entregado',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#6B7280',
            self::PREPARING => '#D97706',
            self::IN_TRANSIT => '#2563EB',
            self::DELIVERED => '#16A34A',
            self::CANCELLED => '#DC2626',
        };
    }

    public function canTransitionTo(self $next, \App\Enums\SalesOrderServiceMode $serviceMode): bool
    {
        if ($this === self::CANCELLED || $this === self::DELIVERED) {
            return false;
        }

        return match ($serviceMode) {
            \App\Enums\SalesOrderServiceMode::COUNTER => match ($this) {
                self::PENDING => in_array($next, [self::DELIVERED, self::CANCELLED], true),
                default => false,
            },
            \App\Enums\SalesOrderServiceMode::DELIVERY => match ($this) {
                self::PENDING => in_array($next, [self::PREPARING, self::CANCELLED], true),
                self::PREPARING => in_array($next, [self::IN_TRANSIT, self::CANCELLED], true),
                self::IN_TRANSIT => in_array($next, [self::DELIVERED, self::CANCELLED], true),
                default => false,
            },
        };
    }
}