<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class PurchaseV2 extends Model
{
    use HasFactory;

    protected $table = 'purchases';

    protected $fillable = [
        'company_id',
        'location_id',
        'supplier_id',
        'purchase_number',
        'purchase_date',
        'expected_delivery_date',
        'delivery_date',
        'status',
        'total',
        'notes',
        'document_number',
        'user_id',
        'received_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expected_delivery_date' => 'date',
        'delivery_date' => 'date',
        'total' => 'decimal:2',
    ];

    // Estados posibles
    const STATUS_DRAFT = 'draft';
    const STATUS_ORDERED = 'ordered';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_RECEIVED = 'received';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Relaciones
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Admin\Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Admin\Location::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseDetailV2::class, 'purchase_id');
    }

    /**
     * Scopes
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ORDERED);
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_TRANSIT);
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Generar número de compra automático
     */
    public static function generatePurchaseNumber(): string
    {
        $lastPurchase = self::orderBy('id', 'desc')->first();
        $nextNumber = $lastPurchase ? (int) substr($lastPurchase->purchase_number, 3) + 1 : 1;

        return 'PUR' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Calcular total basado en detalles
     */
    public function calculateTotal(): float
    {
        return $this->details()->sum('total');
    }

    /**
     * Actualizar total
     */
    public function updateTotal(): void
    {
        $this->total = $this->calculateTotal();
        $this->save();
    }
}
