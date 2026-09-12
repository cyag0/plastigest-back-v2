<?php

namespace App\Models\Operations;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un resultado de la fórmula: qué producto sale y cuánto rinde el proceso.
 *
 * @mixin IdeHelperFormulaOutput
 */
class FormulaOutput extends Model
{
    protected $table = 'formula_outputs';

    protected $fillable = [
        'formula_id',
        'product_id',
        'unit_id',
        'expected_quantity',
        'is_primary',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'is_primary' => 'boolean',
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
