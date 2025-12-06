<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Adjustment Model - Wrapper para movements con movement_reason = 'adjustment'
 * Representa ajustes de inventario (mermas, extravíos, ajustes, etc.)
 *
 * @mixin IdeHelperAdjustment
 */
class Adjustment extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'adjustment_date' => 'date',
        'content' => 'json'
    ];

    /**
     * Configurar automáticamente el tipo de movimiento como adjustment
     */
    protected static function booted()
    {
        parent::booted();

        // Automáticamente filtrar ajustes (incluye adjustment, return, damage, loss, shrinkage)
        static::addGlobalScope('adjustment_scope', function (Builder $builder) {
            $builder->whereIn('movement_reason', ['adjustment', 'return', 'damage', 'loss', 'shrinkage']);
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            // NO sobrescribir movement_reason si ya está definido
            if (!$model->movement_reason) {
                $model->movement_reason = 'adjustment';
            }
            $model->reference_type = 'adjustment';
            $model->status = 'closed'; // Siempre cerrado, afecta stock inmediatamente
        });
    }

    /**
     * Obtener los detalles del ajuste
     */
    public function details(): HasMany
    {
        return $this->hasMany(AdjustmentDetail::class, 'movement_id');
    }

    /**
     * Scope para usos por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de ajuste
     */
    public function getAdjustmentNumberAttribute(): string
    {
        return $this->document_number ?? 'ADJ-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de ajuste
     */
    public function getAdjustmentDateAttribute(): string
    {
        return $this->movement_date;
    }

    /**
     * Accessor para el total del ajuste
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total_cost ?? 0;
    }

    /**
     * Accessor para obtener el tipo de ajuste
     */
    public function getAdjustmentTypeAttribute(): ?string
    {
        // Determinar tipo basado en movement_reason
        return in_array($this->movement_reason, ['adjustment']) ? 'increment' : 'decrement';
    }

    /**
     * Accessor para obtener el motivo del ajuste
     */
    public function getReasonAttribute(): ?string
    {
        return $this->movement_reason;
    }

    /**
     * Accessor para obtener comentarios
     */
    public function getCommentsAttribute(): ?string
    {
        return $this->content['comments'] ?? null;
    }
}
