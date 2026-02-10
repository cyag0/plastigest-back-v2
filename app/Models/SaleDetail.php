<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaleDetail Model - Modelo independiente para detalles de venta
 *
 * @mixin IdeHelperSaleDetail
 */
class SaleDetail extends Model
{
    /**
     * La tabla asociada al modelo
     */
    protected $table = 'sales_details';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'sale_id',
        'product_id',
        'package_id',
        'unit_id',
        'quantity',
        'unit_price',
        'subtotal',
        'tax',
        'discount',
        'total',
        'content',
    ];

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'content' => 'json',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Configurar eventos del modelo
     */
    protected static function booted()
    {
        static::saving(function ($detail) {
            // Calcular automáticamente subtotal y total si no están definidos
            if (!$detail->subtotal) {
                $detail->subtotal = $detail->quantity * $detail->unit_price;
            }
            if (!$detail->total) {
                $detail->total = $detail->subtotal - $detail->discount + $detail->tax;
            }
        });
    }

    /**
     * Relación con la venta
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /**
     * Relación con el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relación con el paquete
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class, 'package_id');
    }

    /**
     * Relación con la unidad
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
