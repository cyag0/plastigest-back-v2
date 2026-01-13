<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Sale Model - Wrapper para movements con movement_type = 'exit' y movement_reason = 'sale'
 *
 * @mixin IdeHelperSale
 */
class Sale extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'status' => SaleStatus::class,
        'sale_date' => 'date',
        'content' => 'json'
    ];

    /**
     * Configurar automáticamente el tipo de movimiento como sale
     */
    protected static function booted()
    {

        parent::booted();

        // Automáticamente filtrar solo ventas
        static::addGlobalScope('sale_scope', function (Builder $builder) {
            $builder->where('movement_type', 'exit')
                ->where('movement_reason', 'sale');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_type = 'exit';
            $model->movement_reason = 'sale';
            $model->reference_type = 'sales_order';
            $model->status = SaleStatus::DRAFT;
        });
    }

    /**
     * Obtener los detalles de la venta
     */
    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class, 'movement_id');
    }

    /**
     * Scope para ventas por estado
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para ventas pendientes
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para ventas cerradas
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope para ventas por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de venta
     */
    public function getSaleNumberAttribute(): string
    {
        return $this->document_number ?? 'SALE-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de venta
     */
    public function getSaleDateAttribute(): string
    {
        return $this->movement_date;
    }

    /**
     * Accessor para el total de la venta
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total_cost ?? 0;
    }

    /**
     * Accessor para obtener método de pago
     */
    public function getPaymentMethodAttribute(): ?string
    {
        return $this->content['payment_method'] ?? null;
    }

    /**
     * Accessor para obtener información del cliente
     */
    public function getCustomerInfoAttribute(): ?array
    {
        return [
            'name' => $this->content['customer_name'] ?? null,
            'phone' => $this->content['customer_phone'] ?? null,
            'email' => $this->content['customer_email'] ?? null,
        ];
    }

    /**
     * Accessor para obtener monto recibido (efectivo)
     */
    public function getReceivedAmountAttribute(): ?float
    {
        return $this->content['received_amount'] ?? null;
    }

    /**
     * Accessor para obtener cambio (efectivo)
     */
    public function getChangeAmountAttribute(): ?float
    {
        if ($this->payment_method === 'efectivo' && isset($this->content['received_amount'])) {
            return $this->content['received_amount'] - $this->total_amount;
        }
        return null;
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
    public function transitionTo(SaleStatus $newStatus): bool
    {
        /*  if (!$this->status->canTransitionTo($newStatus)) {
            throw new Exception(
                "No se puede transicionar de '{$this->status->label()}' a '{$newStatus->label()}'"
            );
        } */

        $oldStatus = $this->status;

        DB::beginTransaction();
        try {
            // Si se está moviendo a "completado", validar y actualizar el stock
            if ($newStatus === SaleStatus::CLOSED && $oldStatus !== SaleStatus::CLOSED) {
                $this->validateAndUpdateStock();
            }

            // Si se está moviendo desde "completado", revertir el stock
            if ($oldStatus === SaleStatus::CLOSED && $newStatus !== SaleStatus::CLOSED) {
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
     * Validar stock y actualizar cuando la venta es completada
     */
    protected function validateAndUpdateStock(): void
    {
        foreach ($this->details as $detail) {
            // Buscar la relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->first();

            if (!$productLocation) {
                throw new Exception(
                    "El producto '{$detail->product->name}' no existe en la ubicación seleccionada"
                );
            }

            // Validar que hay suficiente stock
            if ($productLocation->current_stock < $detail->quantity) {
                throw new Exception(
                    "Stock insuficiente para el producto '{$detail->product->name}'. " .
                        "Stock disponible: {$productLocation->current_stock}, " .
                        "Cantidad solicitada: {$detail->quantity}"
                );
            }

            // Decrementar stock
            DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->decrement('current_stock', $detail->quantity);
        }
    }

    /**
     * Revertir stock cuando se deshace la venta
     */
    protected function revertStock(): void
    {
        foreach ($this->details as $detail) {
            // Incrementar el stock de vuelta
            $productLocation = DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                ->first();

            if ($productLocation) {
                DB::table('product_location')
                    ->where('product_id', $detail->product_id)
                    ->where('location_id', $this->location_origin_id ?? $this->location_destination_id)
                    ->increment('current_stock', $detail->quantity);
            }
        }
    }

    /**
     * Scope para ventas en borrador
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::DRAFT);
    }

    /**
     * Scope para ventas procesadas
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::PROCESSED);
    }

    /**
     * Scope para ventas completadas
     */
    public function scopeCLOSEDSales(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::CLOSED);
    }

    /**
     * Scope para ventas canceladas
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::CANCELLED);
    }
}
