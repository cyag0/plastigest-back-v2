<?php

namespace App\Notifications\Templates;

class InventoryCountDiscrepancyTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'inventory_count_discrepancy';
    }

    public function getSeverity(): string
    {
        return 'alert';
    }

    public function getDefaultPermission(): string
    {
        return 'inventory_manage';
    }

    public function getTitle(): string
    {
        $count = $this->context['discrepancies_count'] ?? 0;
        return "🔴 Discrepancias en Conteo ({$count} productos)";
    }

    public function getMessage(): string
    {
        $location     = $this->context['location']            ?? null;
        $count        = $this->context['discrepancies_count'] ?? 0;
        $locationName = $location?->name ?? 'Ubicación';

        return "Se encontraron {$count} discrepancias en el conteo de inventario de '{$locationName}'. Revisión requerida.";
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.inventory-count-discrepancy';
    }

    public function getPushData(): array
    {
        return [
            'event_type'          => $this->getEventType(),
            'inventory_count_id'  => $this->context['inventory_count']?->id ?? null,
            'location_id'         => $this->context['location']?->id        ?? null,
            'discrepancies_count' => $this->context['discrepancies_count']   ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['inventory_count'] ?? null;
    }
}
