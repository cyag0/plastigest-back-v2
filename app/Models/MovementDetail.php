<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MovementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'previous_stock',
        'new_stock',
        'batch_number',
        'expiry_date',
        'notes'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'previous_stock' => 'decimal:3',
        'new_stock' => 'decimal:3',
        'expiry_date' => 'date'
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function kardexRecords(): HasMany
    {
        return $this->hasMany(ProductKardex::class);
    }

    // Scopes
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeWithStock($query)
    {
        return $query->where('new_stock', '>', 0);
    }

    public function scopeByBatch($query, $batchNumber)
    {
        return $query->where('batch_number', $batchNumber);
    }
}
