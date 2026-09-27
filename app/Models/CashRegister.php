<?php

namespace App\Models;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Caja de cobro de una sucursal (catálogo por compañía).
 */
class CashRegister extends Model
{
    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'code',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    /** Turno abierto en esta caja, si lo hay. */
    public function openSession(): HasOne
    {
        return $this->hasOne(CashRegisterSession::class)->where('status', CashRegisterSession::STATUS_OPEN);
    }
}
