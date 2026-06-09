<?php

namespace App\Models\Operations;

use App\Enums\ProductionOrderStatus;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\InventoryAdjustmentDetail;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @mixin IdeHelperProductionOrder
 */
class ProductionOrder extends Model
{
    protected $table = 'production_orders';

    protected $fillable = [
        'company_id',
        'location_id',
        'formula_id',
        'folio',
        'production_date',
        'responsible_user_id',
        'status',
        'notes',
        'wastes',
        'total_consumed_quantity',
        'total_produced_quantity',
        'waste_percentage',
        'completed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'production_date' => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_consumed_quantity' => 'decimal:3',
        'total_produced_quantity' => 'decimal:3',
        'waste_percentage' => 'decimal:2',
        'status' => ProductionOrderStatus::class,
        'wastes' => 'array',
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
     * Genera el siguiente folio PROD-YYYY-NNNN con lock pesimista.
     */
    public static function nextFolio(?int $companyId): string
    {
        $year = date('Y');
        $prefix = "PROD-{$year}-";

        return DB::transaction(function () use ($companyId, $prefix) {
            $lastFolio = static::query()
                ->where('company_id', $companyId)
                ->where('folio', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('folio');

            $nextNumber = 1;
            if ($lastFolio && preg_match('/(\d+)$/', $lastFolio, $m)) {
                $nextNumber = ((int) $m[1]) + 1;
            }

            return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
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

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('production_date', today());
    }

    /**
     * Valida que se pueda transicionar al estado dado.
     */
    public function assertCanTransitionTo(ProductionOrderStatus $next): void
    {
        if (!$this->status instanceof ProductionOrderStatus) {
            $this->status = ProductionOrderStatus::from((string) $this->status);
        }
        if (!$this->status->canTransitionTo($next)) {
            throw new RuntimeException(
                "No se puede cambiar de estado {$this->status->value} a {$next->value}"
            );
        }
    }
}
