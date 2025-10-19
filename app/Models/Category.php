<?php

namespace App\Models;

use App\Models\Admin\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relaciones
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
