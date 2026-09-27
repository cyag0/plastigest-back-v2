<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago de una venta. Una venta mixta tiene uno por método.
 */
class SalePayment extends Model
{
    public const METHODS = ['cash', 'card', 'transfer'];

    protected $fillable = [
        'sale_id',
        'method',
        'amount',
        'tendered',
        'change',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tendered' => 'decimal:2',
        'change' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
