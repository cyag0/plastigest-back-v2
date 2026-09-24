<?php

namespace App\Models;

use App\Enums\StockLedgerReason;
use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un renglón del libro mayor de existencias: un cambio de stock ya ocurrido.
 *
 * Es de sólo lectura por diseño. Un movimiento equivocado no se edita ni se
 * borra: se corrige con otro movimiento, para que el historial siga cuadrando
 * con las existencias.
 */
class StockLedgerEntry extends Model
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    protected $table = 'stock_ledger_entries';

    protected $fillable = [
        'company_id',
        'location_id',
        'product_id',
        'direction',
        'reason',
        'quantity',
        'previous_stock',
        'new_stock',
        'unit_cost',
        'reference_type',
        'reference_id',
        'counterpart_location_id',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'reason' => StockLedgerReason::class,
        'quantity' => 'decimal:4',
        'previous_stock' => 'decimal:4',
        'new_stock' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function counterpartLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'counterpart_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** Cantidad con signo: lo que hay que sumar al stock. */
    public function getSignedQuantityAttribute(): float
    {
        $quantity = (float) $this->quantity;

        return $this->direction === self::DIRECTION_OUT ? -$quantity : $quantity;
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForLocation(Builder $query, ?int $locationId): Builder
    {
        return $locationId ? $query->where('location_id', $locationId) : $query;
    }
}
