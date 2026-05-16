<?php

namespace App\Notifications\Services;

use App\Notifications\Contracts\NotificationTemplateInterface;
use App\Notifications\Templates\LowStockTemplate;
use App\Notifications\Templates\InventoryAdjustmentTemplate;
use App\Notifications\Templates\InventoryCountDiscrepancyTemplate;
use App\Notifications\Templates\PurchaseUpdateTemplate;
use App\Notifications\Templates\TaskEventTemplate;

class TemplateResolver
{
    private array $map = [
        'low_stock'                    => LowStockTemplate::class,
        'inventory_adjustment'         => InventoryAdjustmentTemplate::class,
        'inventory_count_discrepancy'  => InventoryCountDiscrepancyTemplate::class,
        'purchase_update'              => PurchaseUpdateTemplate::class,
        'task_event'                   => TaskEventTemplate::class,
    ];

    public function resolve(string $eventType, array $contextData): NotificationTemplateInterface
    {
        $class = $this->map[$eventType]
            ?? throw new \InvalidArgumentException("Unknown notification event type: '{$eventType}'");

        return new $class($contextData);
    }

    public function validEventTypes(): array
    {
        return array_keys($this->map);
    }
}
