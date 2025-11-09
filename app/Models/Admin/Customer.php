<?php

namespace App\Models\Admin;

use App\Models\CustomerNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
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

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relación con Company
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relación con CustomerNotes
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * Solo notas pendientes
     */
    public function pendingNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->where('status', 'pending');
    }

    /**
     * Calcular total pendiente
     */
    public function getTotalPendingAttribute(): float
    {
        return $this->pendingNotes()->sum('amount');
    }
}
