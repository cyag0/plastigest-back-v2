<?php

namespace App\Notifications\Templates;

use App\Models\PurchaseV2;
use App\Notifications\NotificationEngine;

class PurchaseUpdateTemplate extends BaseNotificationTemplate
{
    /**
     * Avisa a quienes administran compras en la sucursal de la compra.
     * Mismo contexto en los tres puntos: en tránsito (app o respuesta del
     * proveedor por WhatsApp) y recibida.
     */
    public static function dispatchFor(PurchaseV2 $purchase, string $subType, ?int $excludeUserId = null): void
    {
        $purchase->loadMissing(['supplier', 'details.product']);

        NotificationEngine::dispatch('purchase_update', (int) $purchase->company_id, [
            'purchase'        => $purchase,
            'supplier_name'   => $purchase->supplier?->name ?? '',
            'sub_type'        => $subType,
            'products'        => $purchase->details->map(fn($detail) => [
                'name'     => $detail->product?->name ?? '',
                'quantity' => $subType === 'received' ? $detail->quantity_received : $detail->quantity,
            ])->toArray(),
            'exclude_user_id' => $excludeUserId,
        ]);
    }

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
            'in_transit' => "Compra en camino — {$supplierName}",
            'received'   => "Compra recibida — {$supplierName}",
            default      => "Actualización de compra — {$supplierName}",
        };
    }

    public function getMessage(): string
    {
        $purchase     = $this->context['purchase']      ?? null;
        $supplierName = $this->context['supplier_name'] ?? 'Proveedor';
        $subType      = $this->context['sub_type']      ?? '';
        $products     = $this->context['products']      ?? collect();
        $purchaseRef  = $purchase?->purchase_number ?: ($purchase?->id ? "#{$purchase->id}" : '');
        $productCount = is_countable($products) ? count($products) : 0;
        $productsLabel = $productCount === 1 ? '1 producto' : "{$productCount} productos";

        return match ($subType) {
            'in_transit' => "La compra {$purchaseRef} de {$supplierName} ({$productsLabel}) está en camino.",
            'received'   => "La compra {$purchaseRef} de {$supplierName} ({$productsLabel}) fue recibida.",
            default      => "La compra {$purchaseRef} de {$supplierName} fue actualizada.",
        };
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.purchase-update';
    }

    public function getEmailData(): array
    {
        $purchase     = $this->context['purchase'] ?? null;
        $subType      = $this->context['sub_type'] ?? '';
        $supplierName = $this->context['supplier_name'] ?? 'Proveedor';
        $products     = $this->context['products'] ?? [];

        return array_merge(parent::getEmailData(), [
            'purchase'        => $purchase,
            'purchase_id'     => $purchase?->id,
            'purchase_number' => $purchase?->purchase_number,
            'supplier_name'   => $supplierName,
            'sub_type'        => $subType,
            'status_label'    => match ($subType) {
                'in_transit' => 'En tránsito',
                'received'   => 'Recibida',
                default      => 'Actualizada',
            },
            'products'        => is_array($products) ? $products : [],
            'products_count'  => is_countable($products) ? count($products) : 0,
            'total'           => $purchase?->total,
        ]);
    }

    public function getPushData(): array
    {
        $purchase = $this->context['purchase'] ?? null;

        return [
            'event_type'  => $this->getEventType(),
            'purchase_id' => $purchase?->id,
            // Sucursal de la compra: a quien no entra a todas y está en otra,
            // la app le ofrece cambiarse a ella (sin el dato solo veía un 404).
            'location_id' => $purchase?->location_id,
            'sub_type'    => $this->context['sub_type'] ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['purchase'] ?? null;
    }
}
