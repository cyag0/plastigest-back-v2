<?php

namespace App\Enums;

enum TransferStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case IN_TRANSIT = 'in_transit';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Obtener la etiqueta legible del estado
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::APPROVED => 'Aprobada',
            self::REJECTED => 'Rechazada',
            self::IN_TRANSIT => 'En Tránsito',
            self::COMPLETED => 'Completada',
            self::CANCELLED => 'Cancelada',
        };
    }

    /**
     * Obtener el color para el estado
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#FFA726',      // Naranja
            self::APPROVED => '#42A5F5',     // Azul
            self::REJECTED => '#F44336',     // Rojo oscuro
            self::IN_TRANSIT => '#AB47BC',   // Púrpura
            self::COMPLETED => '#66BB6A',    // Verde
            self::CANCELLED => '#EF5350',    // Rojo
        };
    }

    /**
     * Verificar si puede transicionar al siguiente estado
     */
    public function canTransitionTo(TransferStatus $newStatus): bool
    {
        return match ($this) {
            self::PENDING => in_array($newStatus, [self::APPROVED, self::REJECTED, self::CANCELLED]),
            self::APPROVED => in_array($newStatus, [self::IN_TRANSIT, self::CANCELLED]),
            self::REJECTED => false, // Estado final
            self::IN_TRANSIT => in_array($newStatus, [self::COMPLETED, self::CANCELLED]),
            self::COMPLETED => false, // Estado final
            self::CANCELLED => false, // Estado final
        };
    }

    /**
     * Obtener el siguiente estado en el flujo normal
     */
    public function next(): ?TransferStatus
    {
        return match ($this) {
            self::PENDING => self::APPROVED,
            self::APPROVED => self::IN_TRANSIT,
            self::IN_TRANSIT => self::COMPLETED,
            default => null,
        };
    }

    /**
     * Obtener el estado anterior
     */
    public function previous(): ?TransferStatus
    {
        return match ($this) {
            self::APPROVED => self::PENDING,
            self::IN_TRANSIT => self::APPROVED,
            self::COMPLETED => self::IN_TRANSIT,
            default => null,
        };
    }

    /**
     * Verificar si el estado permite edición
     */
    public function canEdit(): bool
    {
        return in_array($this, [self::PENDING]);
    }

    /**
     * Verificar si el estado permite cancelación
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::PENDING, self::APPROVED, self::IN_TRANSIT]);
    }

    /**
     * Obtener todos los valores como array
     */
    public static function toArray(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Obtener opciones para select
     */
    public static function options(): array
    {
        return array_map(
            fn($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases()
        );
    }
}
