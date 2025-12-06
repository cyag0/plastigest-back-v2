<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_detail_id',
        'product_id',
        'quantity_shipped',
        'unit_cost',
        'batch_number',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'quantity_shipped' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    protected $appends = [
        'total_cost',
    ];

    /**
     * Relación con el detalle de transferencia
     */
    public function transferDetail(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferDetail::class, 'transfer_detail_id');
    }

    /**
     * Relación con el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Costo total del envío
     */
    public function getTotalCostAttribute(): float
    {
        return (float) ($this->quantity_shipped * $this->unit_cost);
    }

    /**
     * Verifica si hay diferencia entre lo enviado y lo solicitado
     */
    public function hasDifference(): bool
    {
        return $this->quantity_shipped != $this->transferDetail->quantity_requested;
    }

    /**
     * Calcula la diferencia entre enviado y solicitado
     */
    public function getDifference(): float
    {
        return (float) ($this->quantity_shipped - $this->transferDetail->quantity_requested);
    }
}
