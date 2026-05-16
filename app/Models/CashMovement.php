<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'type',
        'amount',
        'concept',
        'payment_method',
        'source_type',
        'source_id',
        'source_url',
        'notes',
        'movement_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'movement_date' => 'date',
    ];

    // -------------------------------------------------------------------------
    // Constantes
    // -------------------------------------------------------------------------

    public const TYPES = [
        'income'     => 'Ingreso',
        'expense'    => 'Egreso',
        'adjustment' => 'Ajuste',
    ];

    public const PAYMENT_METHODS = [
        'cash'     => 'Efectivo',
        'card'     => 'Tarjeta',
        'transfer' => 'Transferencia',
        'other'    => 'Otro',
    ];

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    // -------------------------------------------------------------------------
    // Relaciones
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }
}
