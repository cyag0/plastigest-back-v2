<?php

namespace App\Models\Operations;

use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una operación de empaque dentro de una orden de producción:
 * granel + material de empaque → producto terminado.
 *
 * @mixin IdeHelperProductionOrderPackaging
 */
class ProductionOrderPackaging extends Model
{
    protected $table = 'production_order_packagings';

    protected $fillable = [
        'production_order_id',
        'product_id',
        'unit_id',
        'quantity',
        'source_product_id',
        'source_unit_id',
        'source_quantity',
        'packed_at',
        'packed_by',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'source_quantity' => 'decimal:4',
        'packed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /** Producto terminado obtenido. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Granel consumido. */
    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'source_unit_id');
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    /** Material consumido en esta operación, con las cantidades reales. */
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderPackagingMaterial::class, 'production_order_packaging_id');
    }
}
