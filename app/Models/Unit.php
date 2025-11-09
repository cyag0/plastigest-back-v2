<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'symbol',
        'description',
        'type',
        'is_base',
        'conversion_rate',
        'company_id',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_base' => 'boolean',
        'conversion_rate' => 'decimal:6',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}