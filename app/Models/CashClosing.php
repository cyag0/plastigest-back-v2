<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

class CashClosing extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'closing_date',
        'opening_balance',
        'total_income',
        'total_expense',
        'expected_balance',
        'physical_count',
        'difference',
        'total_cash',
        'total_card',
        'total_transfer',
        'total_other',
        'movements_count',
        'notes',
        'status',
    ];

    protected $casts = [
        'closing_date'     => 'date',
        'opening_balance'  => 'decimal:2',
        'total_income'     => 'decimal:2',
        'total_expense'    => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'physical_count'   => 'decimal:2',
        'difference'       => 'decimal:2',
        'total_cash'       => 'decimal:2',
        'total_card'       => 'decimal:2',
        'total_transfer'   => 'decimal:2',
        'total_other'      => 'decimal:2',
        'movements_count'  => 'integer',
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
}
