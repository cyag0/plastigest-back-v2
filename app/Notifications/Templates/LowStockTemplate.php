<?php

namespace App\Notifications\Templates;

class LowStockTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'low_stock';
    }

    public function getSeverity(): string
    {
        return 'warning';
    }

    public function getDefaultPermission(): string
    {
        return 'inventory_manage';
    }

    public function getTitle(): string
    {
        $product = $this->context['product'] ?? null;
        $name    = $product?->name ?? 'Producto';
        return "⚠️ Stock Bajo: {$name}";
    }

    public function getMessage(): string
    {
        $product      = $this->context['product']       ?? null;
        $location     = $this->context['location']      ?? null;
        $current      = $this->context['current_stock'] ?? 0;
        $minimum      = $this->context['minimum_stock'] ?? 0;
        $productName  = $product?->name   ?? 'Producto';
        $locationName = $location?->name  ?? 'Ubicación';

        return "El producto '{$productName}' en '{$locationName}' tiene {$current} unidades, por debajo del mínimo de {$minimum}.";
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.low-stock';
    }

    public function getPushData(): array
    {
        return [
            'event_type'    => $this->getEventType(),
            'product_id'    => $this->context['product']?->id  ?? null,
            'location_id'   => $this->context['location']?->id ?? null,
            'current_stock' => $this->context['current_stock']  ?? null,
            'minimum_stock' => $this->context['minimum_stock']  ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['product'] ?? null;
    }
}
