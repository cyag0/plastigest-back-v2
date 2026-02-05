<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDetailV2 extends Model
{
    use HasFactory;

    protected $table = 'purchase_details';

    protected $fillable = [
        'purchase_id',
        'product_id',
        'package_id',
        'quantity',
        'unit_id',
        'unit_price',
        'total',
        'quantity_received',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'quantity_received' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    /**
     * Relaciones
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseV2::class, 'purchase_id');
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

    /**
     * Verificar si es un paquete
     */
    public function isPackage(): bool
    {
        return !is_null($this->package_id);
    }

    /**
     * Calcular cantidad en unidad base para actualizar stock
     * Si es paquete: quantity * quantity_per_package
     * Si es producto normal: quantity
     */
    public function getQuantityInBaseUnit(): float
    {
        if ($this->isPackage()) {
            $package = $this->package;
            return $this->quantity * $package->quantity_per_package;
        }

        return $this->quantity;
    }

    /**
     * Calcular total
     */
    public function calculateTotal(): float
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Calcular total automáticamente antes de guardar
        static::saving(function ($detail) {
            $detail->total = $detail->calculateTotal();
        });

        // Actualizar total de la compra después de guardar/eliminar
        static::saved(function ($detail) {
            $detail->purchase->updateTotal();
        });

        static::deleted(function ($detail) {
            $detail->purchase->updateTotal();
        });
    }
}
