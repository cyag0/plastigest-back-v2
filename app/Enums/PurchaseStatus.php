<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case DRAFT = 'draft';           // Borrador - puede editarse
    case ORDERED = 'ordered';       // Pedido - enviado al proveedor
    case IN_TRANSIT = 'in_transit'; // En transporte - mercancía en camino
    case RECEIVED = 'received';     // Recibido - mercancía recibida, afecta stock

    /**
     * Obtener el siguiente estado en el flujo
     */
    public function next(): ?self
    {
        return match ($this) {
            self::DRAFT => self::ORDERED,
            self::ORDERED => self::IN_TRANSIT,
            self::IN_TRANSIT => self::RECEIVED,
            self::RECEIVED => null, // Estado final
        };
    }

    /**
     * Obtener el estado anterior en el flujo
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::DRAFT => null, // Estado inicial
            self::ORDERED => self::DRAFT,
            self::IN_TRANSIT => self::ORDERED,
            self::RECEIVED => self::IN_TRANSIT,
        };
    }

    /**
     * Verificar si puede transicionar al estado dado
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::DRAFT => in_array($newStatus, [self::ORDERED]),
            self::ORDERED => in_array($newStatus, [self::DRAFT, self::IN_TRANSIT]),
            self::IN_TRANSIT => in_array($newStatus, [self::ORDERED, self::RECEIVED]),
            self::RECEIVED => false, // No puede retroceder desde recibido
        };
    }

    /**
     * Obtener descripción legible del estado
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::ORDERED => 'Pedido',
            self::IN_TRANSIT => 'En Transporte',
            self::RECEIVED => 'Recibido',
        };
    }

    /**
     * Obtener descripción del estado
     */
    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Compra en borrador, puede editarse',
            self::ORDERED => 'Pedido enviado al proveedor',
            self::IN_TRANSIT => 'Mercancía en transporte',
            self::RECEIVED => 'Mercancía recibida, stock actualizado',
        };
    }

    /**
     * Obtener icono del estado
     */
    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => '📝',
            self::ORDERED => '📋',
            self::IN_TRANSIT => '🚚',
            self::RECEIVED => '📦',
        };
    }

    /**
     * Verificar si el estado permite edición
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Verificar si el estado afecta el stock
     */
    public function affectsStock(): bool
    {
        return $this === self::RECEIVED;
    }

    /**
     * Obtener todos los estados como array
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::ORDERED,
            self::IN_TRANSIT,
            self::RECEIVED,
        ];
    }

    /**
     * Obtener estados como opciones para select
     */
    public static function options(): array
    {
        return collect(self::all())->map(fn($status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'description' => $status->description(),
            'icon' => $status->icon(),
        ])->toArray();
    }
}
