<?php

namespace App\Models\Operations;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperProductionOrderOutput
 */
class ProductionOrderOutput extends Model
{
    protected $table = 'production_order_outputs';

    protected $fillable = [
        'production_order_id',
        'product_id',
        'unit_id',
        'quantity',
        'expected_quantity',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'expected_quantity' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
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
