<?php

namespace App\Enums;

enum AdjustmentReasonCode: string
{
    case LOSS = 'loss';
    case DAMAGE = 'damage';
    case COUNT_DIFF = 'count_diff';
    case EXPIRY = 'expiry';
    case THEFT = 'theft';
    case FOUND = 'found';
    case OTHER = 'other';

    /**
     * Obtener la etiqueta legible de la razón
     */
    public function label(): string
    {
        return match ($this) {
            self::LOSS => 'Pérdida',
            self::DAMAGE => 'Daño',
            self::COUNT_DIFF => 'Diferencia de Conteo',
            self::EXPIRY => 'Vencimiento',
            self::THEFT => 'Robo',
            self::FOUND => 'Encontrado',
            self::OTHER => 'Otro',
        };
    }

    /**
     * Obtener el icono para la razón
     */
    public function icon(): string
    {
        return match ($this) {
            self::LOSS => 'alert-circle',
            self::DAMAGE => 'alert-triangle',
            self::COUNT_DIFF => 'clipboard-list',
            self::EXPIRY => 'calendar-times',
            self::THEFT => 'lock-alert',
            self::FOUND => 'check-circle',
            self::OTHER => 'help-circle',
        };
    }
}
