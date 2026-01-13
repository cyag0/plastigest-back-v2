<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @mixin IdeHelperUnit
 */
class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abbreviation',
        'company_id',
        'is_base',
        'type',
        'factor_to_base',
    ];

    protected $casts = [
        'factor_to_base' => 'decimal:2',
    ];

    /**
     * Relación con la compañía
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
