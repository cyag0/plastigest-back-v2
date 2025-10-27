<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase Model - Wrapper para movements con movement_type = 'entry' y movement_reason = 'purchase'
 */
class Purchase extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';
    /**
     * Configurar automáticamente el tipo de movimiento como purchase
     */
    protected static function booted()
    {
        parent::booted();

        // Automáticamente filtrar solo compras
        static::addGlobalScope('purchase_scope', function (Builder $builder) {
            $builder->where('movement_type', 'entry')
                ->where('movement_reason', 'purchase');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_type = 'entry';
            $model->movement_reason = 'purchase';
            $model->reference_type = 'purchase_order';
        });
    }

    /**
     * Obtener los detalles de la compra
     */
    public function details(): HasMany
    {
        return $this->hasMany(PurchaseDetail::class, 'movement_id');
    }

    /**
     * Obtener el proveedor (si existe)
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Scope para compras por estado
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para compras pendientes
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope para compras completadas
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope para compras por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de compra
     */
    public function getPurchaseNumberAttribute(): string
    {
        return $this->document_number ?? 'PUR-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de compra
     */
    public function getPurchaseDateAttribute(): string
    {
        return $this->movement_date;
    }

    /**
     * Accessor para el total de la compra
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total_cost ?? 0;
    }

    /**
     * Accessor para obtener información del proveedor desde comments (temporal)
     */
    public function getSupplierInfoAttribute(): ?array
    {
        if ($this->comments && str_starts_with($this->comments, 'SUPPLIER:')) {
            $supplierData = substr($this->comments, 9);
            return json_decode($supplierData, true);
        }
        return null;
    }

    /**
     * Mutator para guardar información del proveedor en comments (temporal)
     */
    public function setSupplierInfoAttribute(array $supplierInfo): void
    {
        $this->comments = 'SUPPLIER:' . json_encode($supplierInfo);
    }
}
