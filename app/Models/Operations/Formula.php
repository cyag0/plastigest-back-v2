<?php

namespace App\Models\Operations;

use App\Models\Admin\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una fórmula es el proceso: qué se consume (`items`) y qué sale (`outputs`).
 *
 * No apunta a un solo producto: de un lote de cocos salen agua, pulpa y fibra
 * al mismo tiempo, y las tres son salidas de la misma corrida.
 *
 * @mixin IdeHelperFormula
 */
class Formula extends Model
{
    protected $table = 'formulas';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'version',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Formula $model) {
            if (!$model->company_id && ($company = CurrentCompany::get())) {
                $model->company_id = $company->id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Ingredientes que consume el proceso. */
    public function items(): HasMany
    {
        return $this->hasMany(FormulaItem::class)->orderBy('sort_order');
    }

    /** Productos que entrega el proceso. */
    public function outputs(): HasMany
    {
        return $this->hasMany(FormulaOutput::class)->orderBy('sort_order');
    }

    /**
     * Salida principal: la que da nombre al proceso. Si ninguna está marcada,
     * la primera por orden.
     */
    public function primaryOutput(): HasOne
    {
        return $this->hasOne(FormulaOutput::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeByCompany(Builder $query, ?int $companyId): Builder
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Fórmulas donde el producto participa, ya sea como insumo o como salida. */
    public function scopeByProduct(Builder $query, int $productId): Builder
    {
        return $query->where(function (Builder $q) use ($productId) {
            $q->whereHas('items', fn(Builder $qq) => $qq->where('product_id', $productId))
                ->orWhereHas('outputs', fn(Builder $qq) => $qq->where('product_id', $productId));
        });
    }

    /** Fórmulas que producen el producto dado. */
    public function scopeProducing(Builder $query, int $productId): Builder
    {
        return $query->whereHas('outputs', fn(Builder $q) => $q->where('product_id', $productId));
    }
}
