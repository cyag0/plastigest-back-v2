<?php

namespace App\Notifications\Templates;

class LowStockTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'low_stock';
    }

    public function getDefaultPermission(): string
    {
        return 'inventory_manage';
    }

    public function getSeverity(): string
    {
        return $this->isOutOfStock() ? 'error' : 'warning';
    }

    public function getTitle(): string
    {
        $product = $this->context['product'] ?? null;
        $name    = $product?->name ?? 'Producto';

        return $this->isOutOfStock() ? "Agotado: {$name}" : "Stock bajo: {$name}";
    }

    public function getMessage(): string
    {
        $product      = $this->context['product']       ?? null;
        $location     = $this->context['location']      ?? null;
        $productName  = $product?->name   ?? 'Producto';
        $locationName = $location?->name  ?? 'la sucursal';
        $unit         = $this->context['unit'] ?? null;

        if ($this->isOutOfStock()) {
            return "Se agotó '{$productName}' en {$locationName}.";
        }

        $current = self::quantity($this->context['current_stock'] ?? 0, $unit);
        $minimum = self::quantity($this->context['minimum_stock'] ?? 0, $unit);

        return "Quedan {$current} de '{$productName}' en {$locationName}; el mínimo es {$minimum}.";
    }

    private function isOutOfStock(): bool
    {
        return (float) ($this->context['current_stock'] ?? 0) <= 0;
    }

    /** 12.000 → "12 pz"; 2.500 → "2.5 kg" (sin ceros de sobra). */
    private static function quantity(float|int|string $value, ?string $unit): string
    {
        $number = rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');

        return $unit ? "{$number} {$unit}" : $number;
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
