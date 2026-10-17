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
    case PRODUCTION_WASTE = 'production_waste';

    /**
     * Etiqueta del motivo, como la dice la app (en oración; "Caducidad" en vez
     * de "Vencimiento"). La usan la lista, los avisos, el reporte y el kárdex.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOSS => 'Pérdida',
            self::DAMAGE => 'Daño',
            self::COUNT_DIFF => 'Diferencia de conteo',
            self::EXPIRY => 'Caducidad',
            self::THEFT => 'Robo',
            self::FOUND => 'Encontrado',
            self::OTHER => 'Otro',
            self::PRODUCTION_WASTE => 'Merma de producción',
        };
    }

    /**
     * Hacia dónde mueve siempre la existencia este motivo: 'out' (lo que se
     * perdió, dañó, venció o robaron), 'in' (lo que apareció) o null si puede
     * ser cualquiera (diferencia de conteo, otro). El alta de ajustes lo valida
     * aunque la app ya no deje elegir mal: un «Encontrado» que restaba
     * contaba como pérdida en el reporte de mermas.
     */
    public function direction(): ?string
    {
        return match ($this) {
            self::LOSS, self::DAMAGE, self::EXPIRY, self::THEFT, self::PRODUCTION_WASTE => 'out',
            self::FOUND => 'in',
            self::COUNT_DIFF, self::OTHER => null,
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
            self::PRODUCTION_WASTE => 'recycle',
        };
    }
}
