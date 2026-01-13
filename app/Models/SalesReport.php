<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

class SalesReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'report_date',
        'total_sales',
        'total_cash',
        'total_card',
        'total_transfer',
        'transactions_count',
        'notes',
    ];

    protected $casts = [
        'report_date' => 'date',
        'total_sales' => 'decimal:2',
        'total_cash' => 'decimal:2',
        'total_card' => 'decimal:2',
        'total_transfer' => 'decimal:2',
        'transactions_count' => 'integer',
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
