<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * SaleDetail Model - Wrapper para movement_details relacionados con ventas
 */
class SaleDetail extends MovementDetail
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements_details';

    /**
     * Configurar scope automático
     */
    protected static function booted()
    {
        parent::booted();

        // Filtrar solo detalles de ventas
        static::addGlobalScope('sale_detail_scope', function (Builder $builder) {
            $builder->whereHas('movement', function ($query) {
                $query->where('movement_type', 'exit')
                    ->where('movement_reason', 'sale');
            });
        });
    }

    /**
     * Obtener la venta asociada
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'movement_id');
    }

    /**
     * Obtener el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Accessor para el subtotal
     */
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->unit_price;
    }
}
