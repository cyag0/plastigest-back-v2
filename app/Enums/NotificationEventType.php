<?php

namespace App\Enums;

enum NotificationEventType: string
{
    case LowStock                  = 'low_stock';
    case InventoryAdjustment       = 'inventory_adjustment';
    case InventoryCountDiscrepancy = 'inventory_count_discrepancy';
    case PurchaseUpdate            = 'purchase_update';
    case TaskEvent                 = 'task_event';

    public function label(): string
    {
        return match ($this) {
            self::LowStock                  => 'Stock Bajo',
            self::InventoryAdjustment       => 'Ajuste de Inventario',
            self::InventoryCountDiscrepancy => 'Discrepancia en Conteo',
            self::PurchaseUpdate            => 'Actualización de Compra',
            self::TaskEvent                 => 'Evento de Tarea',
        };
    }
}
