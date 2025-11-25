<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_count_id',
        'product_id',
        'location_id',
        'system_quantity',
        'counted_quantity',
        'difference',
        'notes',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:0',
        'counted_quantity' => 'decimal:0',
        'difference' => 'decimal:0',
    ];

    /**
     * Relación con el conteo de inventario
     */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    /**
     * Relación con el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relación con la ubicación
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Calcular la diferencia automáticamente
     */
    protected static function booted()
    {
        static::saving(function ($detail) {
            if ($detail->counted_quantity !== null) {
                $detail->difference = $detail->counted_quantity - $detail->system_quantity;
            }
        });
    }
}
