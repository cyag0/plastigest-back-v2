<?php

namespace App\Enums;

enum SaleStatus: string
{
    case DRAFT = 'draft';           // Borrador - creado pero no procesado
    case PROCESSED = 'processed';   // Procesado - venta confirmada, esperando entrega
    case CLOSED = 'closed';         // Cerrado - entregado y stock actualizado
    case CANCELLED = 'cancelled';   // Cancelado

    /**
     * Obtener etiqueta legible
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::PROCESSED => 'Procesado',
            self::CLOSED => 'Cerrado',
            self::CANCELLED => 'Cancelado',
        };
    }

    /**
     * Obtener color para UI
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PROCESSED => 'blue',
            self::CLOSED => 'green',
            self::CANCELLED => 'red',
        };
    }

    /**
     * Obtener el siguiente estado en el flujo
     */
    public function next(): ?self
    {
        return match ($this) {
            self::DRAFT => self::PROCESSED,
            self::PROCESSED => self::CLOSED,
            self::CLOSED => null, // Estado final
            self::CANCELLED => null, // Estado terminal
        };
    }

    /**
     * Obtener el estado anterior en el flujo
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::DRAFT => null, // Estado inicial
            self::PROCESSED => self::DRAFT,
            self::CLOSED => self::PROCESSED,
            self::CANCELLED => null, // No se puede retroceder desde cancelado
        };
    }

    /**
     * Verificar si se puede transicionar a otro estado
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::DRAFT => in_array($newStatus, [self::PROCESSED, self::CANCELLED]),
            self::PROCESSED => in_array($newStatus, [self::CLOSED, self::CANCELLED, self::DRAFT]),
            self::CLOSED => in_array($newStatus, [self::PROCESSED]), // Permitir revertir
            self::CANCELLED => false, // No se puede salir del estado cancelado
        };
    }

    /**
     * Obtener todos los estados disponibles
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::PROCESSED,
            self::CLOSED,
            self::CANCELLED,
        ];
    }

    /**
     * Obtener estados activos (no cancelados)
     */
    public static function active(): array
    {
        return [
            self::DRAFT,
            self::PROCESSED,
            self::CLOSED,
        ];
    }
}
