<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * PurchaseDetail Model - Wrapper para movements_details relacionados con compras
 *
 * @mixin IdeHelperPurchaseDetail
 */
class PurchaseDetail extends MovementDetail
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements_details';
    /**
     * Configurar automáticamente para filtrar solo detalles de compras
     */
    protected static function booted()
    {
        parent::booted();

        // Filtrar solo detalles que pertenecen a movimientos de compra
        static::addGlobalScope('purchase_detail_scope', function (Builder $builder) {
            $builder->whereHas('movement', function (Builder $query) {
                $query->where('movement_type', 'entry')
                    ->where('movement_reason', 'purchase');
            });
        });
    }

    /**
     * Relación con la compra (movement)
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'movement_id');
    }

    /**
     * Accessor para la cantidad comprada
     */
    public function getPurchaseQuantityAttribute(): float
    {
        return $this->quantity;
    }

    /**
     * Accessor para el precio unitario
     */
    public function getUnitPriceAttribute(): float
    {
        return $this->unit_cost;
    }

    /**
     * Accessor para el subtotal del detalle
     */
    public function getSubtotalAttribute(): float
    {
        return $this->total_cost;
    }
}
