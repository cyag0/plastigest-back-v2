<?php

namespace App\Models\Operations;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material de empaque realmente consumido en una operación de empaque.
 *
 * Se copia de la receta del producto al momento de empacar, para que el
 * histórico no cambie si después se edita el producto.
 *
 * @mixin IdeHelperProductionOrderPackagingMaterial
 */
class ProductionOrderPackagingMaterial extends Model
{
    protected $table = 'production_order_packaging_materials';

    protected $fillable = [
        'production_order_packaging_id',
        'product_id',
        'unit_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderPackaging::class, 'production_order_packaging_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
