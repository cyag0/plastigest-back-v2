<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * De dónde salió un ajuste (origin.type del recurso y filtro "origin" del
     * listado). Los de una orden de producción y un conteo llevan el documento
     * en reference_type/reference_id; los de una transferencia, en content.
     */
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_PRODUCTION_ORDER = 'production_order';
    public const ORIGIN_INVENTORY_COUNT = 'inventory_count';
    public const ORIGIN_TRANSFER = 'transfer';

    public const ORIGINS = [
        self::ORIGIN_MANUAL,
        self::ORIGIN_PRODUCTION_ORDER,
        self::ORIGIN_INVENTORY_COUNT,
        self::ORIGIN_TRANSFER,
    ];

    /**
     * Merma o daño registrado al recibir una transferencia, en SQL (mismo
     * criterio que originType()). Nunca da NULL, para poder negarlo: el
     * content de la mayoría de los ajustes es NULL o no trae estas llaves.
     */
    private const FROM_TRANSFER_SQL = "(COALESCE(JSON_EXTRACT(inventory_adjustment_details.content, '$.created_from_transfer') = true, false)"
        . " OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(inventory_adjustment_details.content, '$.source')), '') = 'inventory_transfer_receive_difference')";

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
        'reference_id',
        'reference_type',
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

    /**
     * content como arreglo. Algunos ajustes viejos lo traen como texto JSON
     * (doblemente codificado) y el cast devuelve ese texto.
     *
     * @return array<string, mixed>
     */
    public function contentArray(): array
    {
        $content = $this->content;
        if (is_string($content)) {
            $content = json_decode($content, true);
        }

        return is_array($content) ? $content : [];
    }

    /**
     * De dónde salió: manual (registrado en Ajustes), production_order (merma
     * de una orden), inventory_count (diferencia de un conteo) o transfer
     * (merma o daño al recibir una transferencia). Un reference_type que no
     * se conoce se devuelve tal cual: tampoco es manual.
     */
    public function originType(): string
    {
        $content = $this->contentArray();

        if (!empty($content['created_from_transfer']) || ($content['source'] ?? null) === 'inventory_transfer_receive_difference') {
            return self::ORIGIN_TRANSFER;
        }

        $reference = (string) $this->reference_type;

        return $reference === '' ? self::ORIGIN_MANUAL : $reference;
    }

    /** Id del documento de origen (null en los manuales). */
    public function originId(): ?int
    {
        $id = match ($this->originType()) {
            self::ORIGIN_MANUAL => null,
            self::ORIGIN_TRANSFER => $this->contentArray()['transfer_id'] ?? null,
            default => $this->reference_id,
        };

        return $id ? (int) $id : null;
    }

    public function isManual(): bool
    {
        return $this->originType() === self::ORIGIN_MANUAL;
    }

    /**
     * Se deshizo (o, si es una merma de producción, se revirtió al cancelar
     * la orden): su existencia ya regresó. Queda en content.reverted_at.
     */
    public function isReverted(): bool
    {
        return !empty($this->contentArray()['reverted_at']);
    }

    /** Usuario que lo deshizo o revirtió (content.reverted_by). */
    public function revertedById(): ?int
    {
        $id = $this->contentArray()['reverted_by'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Se puede deshacer, sin contar quién lo pide (permiso y sucursal) ni si
     * todavía hay existencia para regresar una entrada: solo los registrados
     * a mano, sin deshacer y del mismo día. Los generados se corrigen desde su
     * documento, y uno de otro día ya pasó por cortes, reportes y conteos: se
     * corrige con un ajuste nuevo.
     */
    public function isUndoable(): bool
    {
        return $this->isManual() && !$this->isReverted() && (bool) $this->created_at?->isToday();
    }

    /**
     * Scopes
     */

    /** Solo los de un origen (ver originType()). */
    public function scopeOrigin(Builder $query, string $origin): Builder
    {
        $referenceType = $query->qualifyColumn('reference_type');

        return match ($origin) {
            self::ORIGIN_TRANSFER => $query->whereRaw(self::FROM_TRANSFER_SQL),
            self::ORIGIN_MANUAL => $query
                ->where(fn($q) => $q->whereNull($referenceType)->orWhere($referenceType, ''))
                ->whereRaw('NOT ' . self::FROM_TRANSFER_SQL),
            default => $query->where($referenceType, $origin)->whereRaw('NOT ' . self::FROM_TRANSFER_SQL),
        };
    }

    /** Deshechos o revertidos (true) o vigentes (false). Ver isReverted(). */
    public function scopeReverted(Builder $query, bool $reverted = true): Builder
    {
        $column = $query->qualifyColumn('content') . '->reverted_at';

        return $reverted ? $query->whereNotNull($column) : $query->whereNull($column);
    }
}
