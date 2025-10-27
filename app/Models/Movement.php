<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

class Movement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'movement_type',
        'movement_reason',
        'document_number',
        'reference_id',
        'reference_type',
        'total_amount',
        'user_id',
        'movement_date',
        'notes'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'movement_date' => 'datetime'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(MovementDetail::class);
    }

    public function kardexRecords(): HasMany
    {
        return $this->hasMany(ProductKardex::class);
    }

    // Scopes
    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    public function scopeEntries($query)
    {
        return $query->where('movement_type', 'entry');
    }

    public function scopeExits($query)
    {
        return $query->where('movement_type', 'exit');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('movement_type', 'adjustment');
    }
}
