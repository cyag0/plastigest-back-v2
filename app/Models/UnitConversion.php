<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperUnitConversion
 */
class UnitConversion extends Model
{
    protected $fillable = [
        'from_unit_id',
        'to_unit_id',
        'factor',
    ];

    protected $casts = [
        'factor' => 'decimal:2',
    ];

    /**
     * Unidad de origen
     */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    /**
     * Unidad de destino
     */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }
}
