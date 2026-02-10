<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\SaleStatus;
use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Services\MovementService;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Sale Model - Modelo independiente para ventas
 *
 * @mixin IdeHelperSale
 */
class Sale extends Model
{
    use SoftDeletes;

    /**
     * La tabla asociada al modelo
     */
    protected $table = 'sales';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'customer_id',
        'sale_number',
        'sale_date',
        'status',
        'subtotal',
        'tax',
        'discount',
        'total',
        'payment_method',
        'payment_status',
        'paid_amount',
        'payment_history',
        'content',
        'notes',
    ];

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'status' => SaleStatus::class,
        'sale_date' => 'date',
        'content' => 'json',
        'payment_history' => 'json',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    /**
     * Configurar automáticamente valores por defecto
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->status) {
                $model->status = SaleStatus::DRAFT;
            }
            if (!$model->sale_date) {
                $model->sale_date = now()->toDateString();
            }
        });
    }

    /**
     * Relación con detalles de la venta
     */
    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class, 'sale_id');
    }

    /**
     * Relación con la ubicación
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Relación con la compañía
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el cliente
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return $query->whereBetween('sale_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de venta
     */
    public function getFormattedSaleNumberAttribute(): string
    {
        return $this->sale_number ?? 'SALE-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para el total de la venta
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total ?? 0;
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
        $movementService = new MovementService();
        $locationId = $this->location_id;

        foreach ($this->details as $detail) {
            // Cargar producto para obtener su unit_id
            $product = Product::findOrFail($detail->product_id);

            if (!$product->unit_id) {
                throw new Exception(
                    "El producto '{$product->name}' no tiene una unidad base definida"
                );
            }

            try {
                // Usar MovementService para decrementar stock
                // MovementService se encargará de convertir a la unidad base del producto
                $movementService->decrement(
                    $locationId,
                    $detail->product_id,
                    $detail->unit_id, // Unidad en la que se vendió
                    $detail->quantity,
                    $detail->package_id // Package si se vendió como paquete
                );
            } catch (Exception $e) {
                // Personalizar el mensaje de error con el nombre del producto
                throw new Exception(
                    "Stock insuficiente para '{$product->name}'. " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Revertir stock cuando se deshace la venta
     */
    protected function revertStock(): void
    {
        $movementService = new MovementService();
        $locationId = $this->location_id;

        foreach ($this->details as $detail) {
            // Cargar producto para obtener su unit_id
            $product = Product::findOrFail($detail->product_id);

            if (!$product->unit_id) {
                continue; // Si no tiene unidad, omitir
            }

            // Usar MovementService para incrementar stock de vuelta
            $movementService->increment(
                $locationId,
                $detail->product_id,
                $detail->unit_id, // Unidad en la que se vendió
                $detail->quantity,
                $detail->package_id // Package si se vendió como paquete
            );
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
