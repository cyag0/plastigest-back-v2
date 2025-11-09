<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Usage Model - Wrapper para movements con movement_type = 'exit' y movement_reason = 'usage'
 * Representa el uso interno de productos en la empresa (no ventas)
 */
class Usage extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'usage_date' => 'date',
        'content' => 'json'
    ];

    /**
     * Configurar automáticamente el tipo de movimiento como usage
     */
    protected static function booted()
    {
        parent::booted();

        // Automáticamente filtrar solo usos
        static::addGlobalScope('usage_scope', function (Builder $builder) {
            $builder->where('movement_type', 'exit')
                ->where('movement_reason', 'usage');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_type = 'exit';
            $model->movement_reason = 'usage';
            $model->reference_type = 'internal_usage';
            $model->status = 'closed'; // Siempre cerrado, afecta stock inmediatamente
        });
    }

    /**
     * Obtener los detalles del uso
     */
    public function details(): HasMany
    {
        return $this->hasMany(UsageDetail::class, 'movement_id');
    }

    /**
     * Scope para usos por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de uso
     */
    public function getUsageNumberAttribute(): string
    {
        return $this->document_number ?? 'USG-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de uso
     */
    public function getUsageDateAttribute(): string
    {
        return $this->movement_date;
    }

    /**
     * Accessor para el total del uso
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total_cost ?? 0;
    }

    /**
     * Accessor para obtener el motivo del uso
     */
    public function getReasonAttribute(): ?string
    {
        return $this->content['reason'] ?? null;
    }

    /**
     * Accessor para obtener el usuario/área que usó los productos
     */
    public function getUsedByAttribute(): ?string
    {
        return $this->content['used_by'] ?? null;
    }

    /**
     * Validar stock y actualizar cuando se registra el uso
     */
    public function validateAndUpdateStock(): void
    {
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

            // Validar que hay suficiente stock
            if ($productLocation->current_stock < $detail->quantity) {
                throw new Exception(
                    "Stock insuficiente para el producto '{$detail->product->name}'. " .
                        "Stock disponible: {$productLocation->current_stock}, " .
                        "Cantidad solicitada: {$detail->quantity}"
                );
            }

            // Decrementar stock
            DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->decrement('current_stock', $detail->quantity);
        }
    }
}
