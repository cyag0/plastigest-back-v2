<?php

namespace App\Enums;

enum NotificationEventType: string
{
    case LowStock                  = 'low_stock';
    case InventoryAdjustment       = 'inventory_adjustment';
    case InventoryCountDiscrepancy = 'inventory_count_discrepancy';
    case PurchaseUpdate            = 'purchase_update';
    case TaskEvent                 = 'task_event';
    case TransferUpdate            = 'transfer_update';
    case Reminder                  = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::LowStock                  => 'Stock bajo',
            self::InventoryAdjustment       => 'Ajuste de inventario',
            self::InventoryCountDiscrepancy => 'Discrepancia en conteo',
            self::PurchaseUpdate            => 'Actualización de compra',
            self::TaskEvent                 => 'Tareas',
            self::TransferUpdate            => 'Transferencias',
            self::Reminder                  => 'Recordatorios',
        };
    }
}
