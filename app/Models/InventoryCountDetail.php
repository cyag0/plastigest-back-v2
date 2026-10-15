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

    // Tres decimales, como product_location.current_stock. Con decimal:0 las
    // cantidades se redondeaban a enteros al leerlas: contar 9 kg de un
    // producto con 10.4 kg aplicaba una diferencia de -1 en vez de -1.4.
    protected $casts = [
        'system_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'difference' => 'decimal:3',
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
                $detail->difference = round((float) $detail->counted_quantity - (float) $detail->system_quantity, 3);
            }
        });
    }
}
