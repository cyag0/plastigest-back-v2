<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @mixin IdeHelperInventoryTransferDetail
 */
class InventoryTransferDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'product_id',
        'package_id',
        'unit_id',
        'quantity_requested',
        'quantity_approved',
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
        'quantity_approved' => 'decimal:3',
        'quantity_shipped' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'expiry_date' => 'date:Y-m-d',
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
     * Relación con el paquete (opcional)
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ProductPackage::class, 'package_id');
    }

    /**
     * Relación con la unidad
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
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
     * Lo que se va a mandar: lo aprobado, o lo pedido si todavía no se aprueba.
     */
    public function approvedQuantity(): float
    {
        return (float) ($this->quantity_approved ?? $this->quantity_requested);
    }

    /**
     * Total con lo aprobado (o lo pedido mientras no se aprueba).
     */
    public function calculateTotal(): void
    {
        $this->total_cost = $this->approvedQuantity() * $this->unit_cost;
    }

    /**
     * Boot del modelo
     */
    protected static function booted()
    {
        static::saving(function ($model) {
            // Calcular total automáticamente si cambia la cantidad o unit_cost
            if ($model->isDirty(['quantity_requested', 'quantity_approved', 'unit_cost'])) {
                $model->calculateTotal();
            }
        });
    }
}
