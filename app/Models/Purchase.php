<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\PurchaseStatus;
use Illuminate\Support\Facades\DB;
use Exception;

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
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'status' => PurchaseStatus::class,
        'purchase_date' => 'date'
    ];
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
            $model->status = PurchaseStatus::DRAFT;
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

    /**
     * Transicionar al siguiente estado
     */
    public function advanceStatus(): bool
    {
        $nextStatus = $this->status->next();

        if (!$nextStatus) {
            return false; // Ya está en el estado final
        }

        return $this->transitionTo($nextStatus);
    }

    /**
     * Retroceder al estado anterior
     */
    public function revertStatus(): bool
    {
        $previousStatus = $this->status->previous();

        if (!$previousStatus) {
            return false; // Ya está en el estado inicial
        }

        return $this->transitionTo($previousStatus);
    }

    /**
     * Transicionar a un estado específico
     */
    public function transitionTo(PurchaseStatus $newStatus): bool
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new Exception(
                "No se puede transicionar de '{$this->status->label()}' a '{$newStatus->label()}'"
            );
        }

        $oldStatus = $this->status;

        DB::beginTransaction();
        try {
            // Si se está moviendo a "recibido", actualizar el stock
            if ($newStatus === PurchaseStatus::RECEIVED && $oldStatus !== PurchaseStatus::RECEIVED) {
                $this->updateStock();
            }

            // Si se está moviendo desde "recibido", revertir el stock
            if ($oldStatus === PurchaseStatus::RECEIVED && $newStatus !== PurchaseStatus::RECEIVED) {
                $this->revertStock();
            }

            $this->status = $newStatus;
            $this->save();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Actualizar stock cuando la compra es recibida
     */
    protected function updateStock(): void
    {
        foreach ($this->details as $detail) {
            // Buscar la relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->first();

            if ($productLocation) {
                // Actualizar stock existente
                DB::table('product_location')
                    ->where('product_id', $detail->product_id)
                    ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                    ->increment('current_stock', $detail->quantity);
            } else {
                // Crear nueva relación product_location si no existe
                DB::table('product_location')->insert([
                    'product_id' => $detail->product_id,
                    'location_id' => $this->location_origin_id ?? $this->location_destination_id,
                    'current_stock' => $detail->quantity,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Revertir stock cuando se deshace la recepción
     */
    protected function revertStock(): void
    {
        foreach ($this->details as $detail) {
            DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_id ?? $this->location_destination_id)
                ->decrement('stock', $detail->quantity);
        }
    }

    /**
     * Scope para compras en borrador
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::DRAFT);
    }

    /**
     * Scope para compras pedidas
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::ORDERED);
    }

    /**
     * Scope para compras en transporte
     */
    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::IN_TRANSIT);
    }

    /**
     * Scope para compras recibidas
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::RECEIVED);
    }
}
