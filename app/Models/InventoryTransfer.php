<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'origin_location_id',
        'destination_location_id',
        'transfer_number',
        'transfer_type',
        'status',
        'reason',
        'total_quantity',
        'requested_by',
        'approved_by',
        'confirmed_by',
        'requested_date',
        'approved_date',
        'confirmed_date',
        'notes'
    ];

    protected $casts = [
        'total_quantity' => 'decimal:3',
        'requested_date' => 'datetime',
        'approved_date' => 'datetime',
        'confirmed_date' => 'datetime'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(InventoryTransferDetail::class);
    }

    // Scopes
    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
