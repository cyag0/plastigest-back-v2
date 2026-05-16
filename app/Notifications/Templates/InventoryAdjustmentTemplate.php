<?php

namespace App\Notifications\Templates;

class InventoryAdjustmentTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'inventory_adjustment';
    }

    public function getDefaultPermission(): string
    {
        return 'inventory_manage';
    }

    public function getTitle(): string
    {
        $product = $this->context['product'] ?? null;
        $name    = $product?->name ?? 'Producto';
        return "🔧 Ajuste de Inventario: {$name}";
    }

    public function getMessage(): string
    {
        $product      = $this->context['product']        ?? null;
        $location     = $this->context['location']       ?? null;
        $qty          = $this->context['adjustment_qty'] ?? 0;
        $reason       = $this->context['reason']         ?? 'Sin motivo';
        $adjustedBy   = $this->context['adjusted_by']   ?? 'Sistema';
        $newStock     = $this->context['new_stock']      ?? 0;
        $productName  = $product?->name  ?? 'Producto';
        $locationName = $location?->name ?? 'Ubicación';
        $sign         = $qty >= 0 ? '+' : '';

        return "{$adjustedBy} ajustó '{$productName}' en '{$locationName}': {$sign}{$qty} (stock actual: {$newStock}). Motivo: {$reason}.";
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.inventory-adjustment';
    }

    public function getPushData(): array
    {
        return [
            'event_type'     => $this->getEventType(),
            'product_id'     => $this->context['product']?->id  ?? null,
            'location_id'    => $this->context['location']?->id ?? null,
            'adjustment_qty' => $this->context['adjustment_qty'] ?? null,
            'new_stock'      => $this->context['new_stock']      ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['product'] ?? null;
    }
}
