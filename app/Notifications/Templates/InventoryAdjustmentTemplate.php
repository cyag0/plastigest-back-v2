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
        return "Ajuste de inventario: {$name}";
    }

    public function getMessage(): string
    {
        $product      = $this->context['product']        ?? null;
        $location     = $this->context['location']       ?? null;
        $qty          = (float) ($this->context['adjustment_qty'] ?? 0);
        $reason       = $this->context['reason']         ?? 'Sin motivo';
        $adjustedBy   = $this->context['adjusted_by']   ?? 'Sistema';
        $unit         = $this->context['unit']           ?? null;
        $productName  = $product?->name  ?? 'Producto';
        $locationName = $location?->name ?? 'la sucursal';
        $sign         = $qty >= 0 ? '+' : '−';
        $change       = $sign . self::quantity(abs($qty), $unit);
        $newStock     = self::quantity($this->context['new_stock'] ?? 0, $unit);

        return "{$adjustedBy} ajustó '{$productName}' en {$locationName}: {$change} ({$reason}). Existencia: {$newStock}.";
    }

    /** 3.000 → "3 pz"; 2.500 → "2.5 kg". */
    private static function quantity(float|int|string $value, ?string $unit): string
    {
        $number = rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');

        return $unit ? "{$number} {$unit}" : $number;
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.inventory-adjustment';
    }

    public function getPushData(): array
    {
        return [
            'event_type'     => $this->getEventType(),
            'adjustment_id'  => $this->context['adjustment']?->id ?? null,
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
