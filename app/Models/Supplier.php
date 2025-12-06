<?php

namespace App\Models;

use App\Models\Admin\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperSupplier
 */
class Supplier extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'name',
        'business_name',
        'social_reason',
        'rfc',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the company that owns the supplier.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope a query to only include active suppliers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by company.
     */
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
