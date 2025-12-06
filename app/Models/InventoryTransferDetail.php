<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryTransferDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'product_id',
        'quantity_requested',
        'quantity_shipped',
        'quantity_received',
        'unit_cost',
        'total_cost',
        'batch_number',
        'expiry_date',
        'notes',
        'damage_report',
        'has_difference',
        'difference',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:3',
        'quantity_shipped' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'expiry_date' => 'date',
        'has_difference' => 'boolean',
        'difference' => 'decimal:3',
    ];

    /**
     * Relaciones
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relación con los envíos (productos realmente enviados)
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(InventoryTransferShipment::class, 'transfer_detail_id');
    }

    /**
     * Calcular diferencia entre lo enviado y lo recibido
     */
    public function getDifferenceAttribute(): float
    {
        return $this->quantity_shipped - $this->quantity_received;
    }

    /**
     * Verificar si tiene faltante
     */
    public function getHasDifferenceAttribute(): bool
    {
        return $this->difference > 0;
    }

    /**
     * Calcular el total basado en cantidad solicitada
     */
    public function calculateTotal(): void
    {
        $this->total_cost = $this->quantity_requested * $this->unit_cost;
    }

    /**
     * Boot del modelo
     */
    protected static function booted()
    {
        static::saving(function ($model) {
            // Calcular total automáticamente si cambia quantity_requested o unit_cost
            if ($model->isDirty(['quantity_requested', 'unit_cost'])) {
                $model->calculateTotal();
            }
        });
    }
}
