<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UsageDetail Model - Detalles de uso interno de productos
 * Usa la tabla movements_details
 */
class UsageDetail extends Model
{
    /**
     * La tabla asociada al modelo
     */
    protected $table = 'movements_details';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
    ];

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    /**
     * Obtener el uso al que pertenece este detalle
     */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(Usage::class, 'movement_id');
    }

    /**
     * Obtener el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
