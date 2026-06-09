<?php

namespace App\Models\Operations;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperFormulaItem
 */
class FormulaItem extends Model
{
    protected $table = 'formula_items';

    protected $fillable = [
        'formula_id',
        'product_id',
        'unit_id',
        'expected_quantity',
        'expected_output_quantity',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'expected_output_quantity' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
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
