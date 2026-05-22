<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderDetail extends Model
{
    use HasFactory;

    protected $table = 'sales_order_details';

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'package_id',
        'unit_id',
        'requested_quantity',
        'prepared_quantity',
        'delivered_quantity',
        'reserved_quantity_base',
        'delivered_quantity_base',
        'unit_price',
        'line_subtotal',
        'line_total',
        'content',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:3',
        'prepared_quantity' => 'decimal:3',
        'delivered_quantity' => 'decimal:3',
        'reserved_quantity_base' => 'decimal:3',
        'delivered_quantity_base' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'line_total' => 'decimal:2',
        'content' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $subtotal = (float) $detail->requested_quantity * (float) $detail->unit_price;

            if (!$detail->line_subtotal) {
                $detail->line_subtotal = $subtotal;
            }

            if (!$detail->line_total) {
                $detail->line_total = $subtotal;
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class, 'package_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}