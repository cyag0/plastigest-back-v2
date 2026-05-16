<?php

namespace App\Notifications\Templates;

class PurchaseUpdateTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'purchase_update';
    }

    public function getDefaultPermission(): string
    {
        return 'purchases_manage';
    }

    public function getTitle(): string
    {
        $subType      = $this->context['sub_type']      ?? '';
        $supplierName = $this->context['supplier_name'] ?? 'Proveedor';

        return match ($subType) {
            'in_transit' => "🚚 Compra en Tránsito — {$supplierName}",
            'received'   => "✅ Compra Recibida — {$supplierName}",
            default      => "📦 Actualización de Compra — {$supplierName}",
        };
    }

    public function getMessage(): string
    {
        $purchase     = $this->context['purchase']      ?? null;
        $supplierName = $this->context['supplier_name'] ?? 'Proveedor';
        $subType      = $this->context['sub_type']      ?? '';
        $products     = $this->context['products']      ?? collect();
        $purchaseRef  = $purchase?->id ? "#{$purchase->id}" : '';
        $productCount = is_countable($products) ? count($products) : 0;

        return match ($subType) {
            'in_transit' => "La compra {$purchaseRef} de {$supplierName} ({$productCount} productos) está en camino.",
            'received'   => "La compra {$purchaseRef} de {$supplierName} ({$productCount} productos) fue recibida.",
            default      => "La compra {$purchaseRef} de {$supplierName} fue actualizada.",
        };
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.purchase-update';
    }

    public function getPushData(): array
    {
        return [
            'event_type'  => $this->getEventType(),
            'purchase_id' => $this->context['purchase']?->id ?? null,
            'sub_type'    => $this->context['sub_type']      ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['purchase'] ?? null;
    }
}
