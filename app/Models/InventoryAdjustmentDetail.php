<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\AdjustmentReasonCode;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

/**
 * @mixin IdeHelperInventoryAdjustmentDetail
 */
class InventoryAdjustmentDetail extends Model
{
    use HasFactory;

    protected $table = 'inventory_adjustment_details';

    protected $fillable = [
        'company_id',
        'location_id',
        'created_by',
        'product_id',
        'direction',
        'quantity',
        'unit_id',
        'previous_stock',
        'new_stock',
        'reason_code',
        'notes',
        'content',
        'applied_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'previous_stock' => 'decimal:3',
        'new_stock' => 'decimal:3',
        'reason_code' => AdjustmentReasonCode::class,
        'content' => 'array',
        'applied_at' => 'datetime',
    ];

    /**
     * Relaciones
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Accesores
     */
    public function getAdjustedQuantityAttribute(): float
    {
        return (float) ($this->new_stock - $this->previous_stock);
    }

    public function getImpactAttribute(): string
    {
        $quantity = $this->adjusted_quantity;
        if ($quantity > 0) {
            return "+{$quantity}";
        }
        return (string) $quantity;
    }
}
