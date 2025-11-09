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

        // Automáticamente filtrar solo ajustes
        static::addGlobalScope('adjustment_scope', function (Builder $builder) {
            $builder->where('movement_reason', 'adjustment');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_reason = 'adjustment';
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
        return $this->content['adjustment_type'] ?? null;
    }

    /**
     * Accessor para obtener el motivo del ajuste
     */
    public function getReasonAttribute(): ?string
    {
        return $this->content['reason'] ?? null;
    }

    /**
     * Accessor para obtener el usuario que realizó el ajuste
     */
    public function getAdjustedByAttribute(): ?string
    {
        return $this->content['adjusted_by'] ?? null;
    }

    /**
     * Validar stock y actualizar cuando se registra el ajuste
     */
    public function validateAndUpdateStock(): void
    {
        $adjustmentType = $this->content['adjustment_type'] ?? 'decrease';

        foreach ($this->details as $detail) {
            // Buscar la relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->first();

            if (!$productLocation) {
                throw new Exception(
                    "El producto '{$detail->product->name}' no existe en la ubicación seleccionada"
                );
            }

            if ($adjustmentType === 'decrease') {
                // Validar que hay suficiente stock para decrementar
                if ($productLocation->current_stock < $detail->quantity) {
                    throw new Exception(
                        "Stock insuficiente para el producto '{$detail->product->name}'. " .
                            "Stock disponible: {$productLocation->current_stock}, " .
                            "Cantidad a decrementar: {$detail->quantity}"
                    );
                }

                // Decrementar stock
                DB::table('product_location')
                    ->where('product_id', $detail->product_id)
                    ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                    ->decrement('current_stock', $detail->quantity);

                // Establecer movement_type como exit
                $this->movement_type = 'exit';
            } else {
                // Incrementar stock
                DB::table('product_location')
                    ->where('product_id', $detail->product_id)
                    ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                    ->increment('current_stock', $detail->quantity);

                // Establecer movement_type como entry
                $this->movement_type = 'entry';
            }
        }
    }
}
