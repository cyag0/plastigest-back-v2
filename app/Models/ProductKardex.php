<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

/**
 * @mixin IdeHelperProductKardex
 */
class ProductKardex extends Model
{
    use HasFactory;

    protected $table = 'product_kardex';

    protected $fillable = [
        'company_id',
        'location_id',
        'product_id',
        'movement_id',
        'movement_detail_id',
        'operation_type',
        'operation_reason',
        'quantity',
        'unit_cost',
        'total_cost',
        'previous_stock',
        'new_stock',
        'running_average_cost',
        'document_number',
        'batch_number',
        'expiry_date',
        'user_id',
        'operation_date'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'previous_stock' => 'decimal:3',
        'new_stock' => 'decimal:3',
        'running_average_cost' => 'decimal:2',
        'expiry_date' => 'date',
        'operation_date' => 'datetime'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    public function movementDetail(): BelongsTo
    {
        return $this->belongsTo(MovementDetail::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByOperationType($query, $type)
    {
        return $query->where('operation_type', $type);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('operation_date', [$startDate, $endDate]);
    }

    public function scopeEntries($query)
    {
        return $query->where('operation_type', 'entry');
    }

    public function scopeExits($query)
    {
        return $query->where('operation_type', 'exit');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('operation_type', 'adjustment');
    }
}
