<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material que consume una unidad del producto al empacarse.
 *
 * No confundir con `ProductPackage`, que es una presentación de venta
 * (caja de 12, bulto de 50). Esto es el insumo físico que se descuenta del
 * inventario al empacar: el frasco, la tapa, la etiqueta.
 *
 * @mixin IdeHelperProductPackagingMaterial
 */
class ProductPackagingMaterial extends Model
{
    protected $table = 'product_packaging_materials';

    protected $fillable = [
        'product_id',
        'material_product_id',
        'unit_id',
        'quantity',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    /** Producto terminado que se empaca. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Material que se consume. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
