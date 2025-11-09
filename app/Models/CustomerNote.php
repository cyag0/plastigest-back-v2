<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNote extends Model
{
    protected $fillable = [
        'customer_id',
        'description',
        'amount',
        'status',
        'due_date',
        'company_id',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Relación con Customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relación con Company
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
