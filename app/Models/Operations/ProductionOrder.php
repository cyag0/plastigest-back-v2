<?php

namespace App\Models\Operations;

use App\Enums\ProductionOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\InventoryAdjustmentDetail;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\DocumentSequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperProductionOrder
 */
class ProductionOrder extends Model
{
    /** Producción: entran insumos y salen productos (cocos → agua y pulpa). */
    public const KIND_PROCESS = 'process';

    /**
     * Envasado de granel que ya estaba en inventario: el agua que quedó en la
     * mañana se embotella en la tarde. No tiene consumos ni salidas propios,
     * sólo empaques.
     */
    public const KIND_PACKAGING = 'packaging';

    public const KINDS = [self::KIND_PROCESS, self::KIND_PACKAGING];

    protected $table = 'production_orders';

    protected $fillable = [
        'company_id',
        'location_id',
        'formula_id',
        'kind',
        'folio',
        'production_date',
        'responsible_user_id',
        'status',
        'notes',
        'wastes',
        'planned_packagings',
        'total_consumed_quantity',
        'total_produced_quantity',
        'waste_percentage',
        'completed_at',
        'packed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'production_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'packed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_consumed_quantity' => 'decimal:3',
        'total_produced_quantity' => 'decimal:3',
        'waste_percentage' => 'decimal:2',
        'status' => ProductionOrderStatus::class,
        'wastes' => 'array',
        'planned_packagings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductionOrder $model) {
            if (!$model->company_id && ($company = CurrentCompany::get())) {
                $model->company_id = $company->id;
            }
            if (!$model->location_id && ($location = CurrentLocation::get())) {
                $model->location_id = $location->id;
            }
            if (!$model->folio) {
                $model->folio = static::nextFolio($model->company_id);
            }
        });
    }

    /**
     * Siguiente folio PROD-YYYY-NNNN de la empresa.
     *
     * Cada empresa lleva su propio consecutivo por año (DocumentSequence,
     * como compras y transferencias), así que dos empresas pueden tener el
     * mismo folio: el índice único es (company_id, folio). Antes el índice era
     * solo el folio y la primera orden de una segunda empresa chocaba con la
     * PROD-YYYY-0001 de la primera; además el bloqueo sobre "el último folio"
     * no protegía la primera orden del año, en la que no había fila que
     * bloquear.
     */
    public static function nextFolio(?int $companyId): string
    {
        $year = date('Y');
        $prefix = "PROD-{$year}-";

        $number = DocumentSequence::next(
            "production:{$companyId}:{$year}",
            fn() => DocumentSequence::lastUsed(static::query()->where('company_id', $companyId), 'folio', $prefix),
        );

        return $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionOrderConsumption::class)->orderBy('sort_order');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOrderOutput::class)->orderBy('sort_order');
    }

    public function wastes(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentDetail::class, 'reference_id')
            ->where('reference_type', 'production_order');
    }

    /** Envasado registrado: granel + material → producto terminado. */
    public function packagings(): HasMany
    {
        return $this->hasMany(ProductionOrderPackaging::class)->orderBy('sort_order');
    }

    public function scopeByCompany(Builder $query, ?int $companyId): Builder
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }

    public function scopeByLocation(Builder $query, ?int $locationId): Builder
    {
        if ($locationId) {
            return $query->where('location_id', $locationId);
        }
        return $query;
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        if ($status) {
            return $query->where('status', $status);
        }
        return $query;
    }

    public function scopeByResponsible(Builder $query, ?int $userId): Builder
    {
        if ($userId) {
            return $query->where('responsible_user_id', $userId);
        }
        return $query;
    }

    public function scopeByDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('production_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('production_date', '<=', $to);
        }
        return $query;
    }

    public function scopeByProduct(Builder $query, int $productId): Builder
    {
        return $query->where(function (Builder $q) use ($productId) {
            $q->whereHas('consumptions', fn(Builder $qq) => $qq->where('product_id', $productId))
                ->orWhereHas('outputs', fn(Builder $qq) => $qq->where('product_id', $productId));
        });
    }

    public function isPackaging(): bool
    {
        return $this->kind === self::KIND_PACKAGING;
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('production_date', today());
    }

    /**
     * Valida que se pueda transicionar al estado dado. Si no, una regla de
     * negocio (422) con el motivo en palabras de la app: antes decía
     * "No se puede cambiar de estado completed a completed".
     */
    public function assertCanTransitionTo(ProductionOrderStatus $next): void
    {
        if (!$this->status instanceof ProductionOrderStatus) {
            $this->status = ProductionOrderStatus::from((string) $this->status);
        }
        if (!$this->status->canTransitionTo($next)) {
            $current = $this->status->label();

            throw new BusinessRuleException(match ($next) {
                ProductionOrderStatus::COMPLETED => "Solo se puede registrar una producción pendiente (esta está «{$current}»).",
                ProductionOrderStatus::CANCELLED => 'La producción ya está cancelada.',
                default => "No se puede pasar la producción de «{$current}» a «{$next->label()}».",
            }, 'invalid_status');
        }
    }
}
