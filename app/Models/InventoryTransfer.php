<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Enums\TransferStatus;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * @mixin IdeHelperInventoryTransfer
 */
class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'from_location_id',
        'to_location_id',
        'transfer_number',
        'status',
        'requested_by',
        'approved_by',
        'shipped_by',
        'received_by',
        'total_cost',
        'notes',
        'rejection_reason',
        'requested_at',
        'approved_at',
        'shipped_at',
        'received_at',
        'cancelled_at',
        'rejected_at',
        // Nuevos campos para el flujo mejorado
        'package_number',
        'package_count',
        'shipping_evidence',
        'shipping_notes',
        'receiving_notes',
        'received_complete',
        'received_partial',
        'has_differences',
    ];

    protected $casts = [
        'status' => TransferStatus::class,
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'total_cost' => 'decimal:2',
        'shipping_evidence' => 'array',
        'received_complete' => 'boolean',
        'received_partial' => 'boolean',
        'has_differences' => 'boolean',
        'package_count' => 'integer',
    ];

    /**
     * Boot del modelo
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->transfer_number) {
                $model->transfer_number = self::generateTransferNumber();
            }
            if (!$model->requested_at) {
                $model->requested_at = now();
            }
            if (!$model->status) {
                $model->status = TransferStatus::PENDING;
            }
        });
    }

    /**
     * Generar número de transferencia único
     */
    protected static function generateTransferNumber(): string
    {
        $prefix = 'TRANS-';
        $date = now()->format('Ymd');
        $lastTransfer = self::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastTransfer ? (int) substr($lastTransfer->transfer_number, -4) + 1 : 1;

        return $prefix . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Relaciones
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function shippedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(InventoryTransferDetail::class, 'transfer_id');
    }

    /**
     * Scopes
     */
    public function scopeByCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus(Builder $query, string|TransferStatus $status): Builder
    {
        if ($status instanceof TransferStatus) {
            return $query->where('status', $status);
        }
        return $query->where('status', $status);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::REJECTED);
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::IN_TRANSIT);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::COMPLETED);
    }

    public function scopeFromLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('from_location_id', $locationId);
    }

    public function scopeToLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('to_location_id', $locationId);
    }

    /**
     * Aprobar transferencia
     */
    public function approve(int $userId): bool
    {
        if ($this->status !== TransferStatus::PENDING) {
            throw new Exception("Solo se pueden aprobar transferencias pendientes");
        }

        $this->status = TransferStatus::APPROVED;
        $this->approved_by = $userId;
        $this->approved_at = now();

        return $this->save();
    }

    /**
     * Rechazar transferencia
     */
    public function reject(int $userId, string $reason = null): bool
    {
        if ($this->status !== TransferStatus::PENDING) {
            throw new Exception("Solo se pueden rechazar transferencias pendientes");
        }

        $this->status = TransferStatus::REJECTED;
        $this->approved_by = $userId; // Quien rechaza
        $this->rejected_at = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    /**
     * Marcar como enviada (en tránsito) y decrementar stock de origen
     */
    public function ship(int $userId, array $shipmentData = []): bool
    {
        if ($this->status !== TransferStatus::APPROVED) {
            throw new Exception("Solo se pueden enviar transferencias aprobadas");
        }

        DB::beginTransaction();
        try {
            // Decrementar stock en ubicación de origen
            foreach ($this->details as $detail) {
                $this->decrementOriginStock($detail);
                
                // Actualizar cantidad enviada
                $detail->quantity_shipped = $detail->quantity_requested;
                $detail->save();
            }

            // Actualizar información de envío
            $this->status = TransferStatus::IN_TRANSIT;
            $this->shipped_by = $userId;
            $this->shipped_at = now();
            
            // Información adicional de empaque y evidencias
            if (!empty($shipmentData['package_number'])) {
                $this->package_number = $shipmentData['package_number'];
            }
            if (!empty($shipmentData['package_count'])) {
                $this->package_count = $shipmentData['package_count'];
            }
            if (!empty($shipmentData['shipping_notes'])) {
                $this->shipping_notes = $shipmentData['shipping_notes'];
            }
            if (!empty($shipmentData['shipping_evidence'])) {
                $this->shipping_evidence = $shipmentData['shipping_evidence'];
            }
            
            $this->save();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Recibir transferencia (con cantidades confirmadas)
     */
    public function receive(int $userId, array $receiptData): bool
    {
        if ($this->status !== TransferStatus::IN_TRANSIT) {
            throw new Exception("Solo se pueden recibir transferencias en tránsito");
        }

        DB::beginTransaction();
        try {
            $hasAnyDifferences = false;
            $receivedQuantities = $receiptData['received_quantities'] ?? [];
            
            // Actualizar cantidades recibidas e incrementar stock en destino
            foreach ($this->details as $detail) {
                $receivedQty = $receivedQuantities[$detail->id] ?? 0;
                
                $detail->quantity_received = $receivedQty;
                
                // Verificar si hay diferencias
                if ($receivedQty !== $detail->quantity_shipped) {
                    $hasAnyDifferences = true;
                    $detail->has_difference = true;
                    $detail->difference = $receivedQty - $detail->quantity_shipped;
                }
                
                $detail->save();

                // Incrementar stock en ubicación de destino
                if ($receivedQty > 0) {
                    $this->incrementDestinationStock($detail, $receivedQty);
                }
            }

            // Actualizar información de recepción
            $this->status = TransferStatus::COMPLETED;
            $this->received_by = $userId;
            $this->received_at = now();
            $this->has_differences = $hasAnyDifferences;
            
            // Información adicional de recepción
            if (!empty($receiptData['receiving_notes'])) {
                $this->receiving_notes = $receiptData['receiving_notes'];
            }
            
            // Determinar tipo de recepción
            $totalReceived = array_sum($receivedQuantities);
            $totalShipped = $this->details->sum('quantity_shipped');
            
            if ($totalReceived === $totalShipped) {
                $this->received_complete = true;
                $this->received_partial = false;
            } elseif ($totalReceived > 0) {
                $this->received_complete = false;
                $this->received_partial = true;
            } else {
                // Rechazo total - cambiar a estado rechazado
                $this->status = TransferStatus::REJECTED;
                $this->received_complete = false;
                $this->received_partial = false;
                $this->rejection_reason = $receiptData['rejection_reason'] ?? 'Producto rechazado en recepción';
            }

            $this->save();
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Cancelar transferencia
     */
    public function cancel(string $reason = null): bool
    {
        if (!$this->status->canCancel()) {
            throw new Exception("Esta transferencia no puede ser cancelada");
        }

        DB::beginTransaction();
        try {
            // Si ya fue enviada, revertir el stock de origen
            if ($this->status === TransferStatus::IN_TRANSIT) {
                foreach ($this->details as $detail) {
                    $this->incrementOriginStock($detail, $detail->quantity_shipped);
                }
            }

            $this->status = TransferStatus::CANCELLED;
            $this->rejection_reason = $reason;
            $this->cancelled_at = now();
            $this->save();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Decrementar stock en ubicación de origen
     */
    protected function decrementOriginStock(InventoryTransferDetail $detail): void
    {
        $productLocation = DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->from_location_id)
            ->first();

        if (!$productLocation) {
            throw new Exception(
                "El producto ID {$detail->product_id} no existe en la ubicación de origen"
            );
        }

        if ($productLocation->current_stock < $detail->quantity_requested) {
            throw new Exception(
                "Stock insuficiente para el producto ID {$detail->product_id}. " .
                "Disponible: {$productLocation->current_stock}, " .
                "Solicitado: {$detail->quantity_requested}"
            );
        }

        DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->from_location_id)
            ->decrement('current_stock', $detail->quantity_requested);
    }

    /**
     * Incrementar stock en ubicación de origen (en caso de cancelación)
     */
    protected function incrementOriginStock(InventoryTransferDetail $detail, float $quantity): void
    {
        DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->from_location_id)
            ->increment('current_stock', $quantity);
    }

    /**
     * Incrementar stock en ubicación de destino
     */
    protected function incrementDestinationStock(InventoryTransferDetail $detail, float $quantity): void
    {
        // Buscar si existe el producto en la ubicación de destino
        $productLocation = DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $this->to_location_id)
            ->first();

        if ($productLocation) {
            // Si existe, incrementar
            DB::table('product_location')
                ->where('product_id', $detail->product_id)
                ->where('location_id', $this->to_location_id)
                ->increment('current_stock', $quantity);
        } else {
            // Si no existe, crear el registro
            DB::table('product_location')->insert([
                'product_id' => $detail->product_id,
                'location_id' => $this->to_location_id,
                'current_stock' => $quantity,
                'min_stock' => 0,
                'max_stock' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Calcular total de diferencias (faltantes)
     */
    public function getTotalDifferencesAttribute(): float
    {
        return $this->details->sum(function ($detail) {
            return $detail->quantity_shipped - $detail->quantity_received;
        });
    }

    /**
     * Verificar si tiene diferencias
     */
    public function getHasDifferencesAttribute(): bool
    {
        return $this->total_differences > 0;
    }

    /**
     * Métodos útiles para el nuevo flujo operativo
     */
    
    /**
     * Verificar si es una petición (pendiente o rechazada)
     */
    public function isPetition(): bool
    {
        return in_array($this->status, [TransferStatus::PENDING, TransferStatus::REJECTED]);
    }

    /**
     * Verificar si es un envío (aprobada o en tránsito)
     */
    public function isShipment(): bool
    {
        return in_array($this->status, [TransferStatus::APPROVED, TransferStatus::IN_TRANSIT]);
    }

    /**
     * Verificar si es un recibo (en tránsito o completado)
     */
    public function isReceipt(): bool
    {
        return in_array($this->status, [TransferStatus::IN_TRANSIT, TransferStatus::COMPLETED]);
    }

    /**
     * Verificar si puede ser aprobada por el usuario de una ubicación específica
     */
    public function canBeApprovedByLocation(int $locationId): bool
    {
        return $this->status === TransferStatus::PENDING && $this->from_location_id === $locationId;
    }

    /**
     * Verificar si puede ser enviada por el usuario de una ubicación específica
     */
    public function canBeShippedByLocation(int $locationId): bool
    {
        return $this->status === TransferStatus::APPROVED && $this->from_location_id === $locationId;
    }

    /**
     * Verificar si puede ser recibida por el usuario de una ubicación específica
     */
    public function canBeReceivedByLocation(int $locationId): bool
    {
        return $this->status === TransferStatus::IN_TRANSIT && $this->to_location_id === $locationId;
    }

    /**
     * Obtener el texto descriptivo del estado actual
     */
    public function getStatusDescription(): string
    {
        return match ($this->status) {
            TransferStatus::PENDING => 'Esperando aprobación de ' . $this->fromLocation->name,
            TransferStatus::APPROVED => 'Aprobada, lista para envío desde ' . $this->fromLocation->name,
            TransferStatus::REJECTED => 'Rechazada por ' . $this->fromLocation->name,
            TransferStatus::IN_TRANSIT => 'En camino a ' . $this->toLocation->name,
            TransferStatus::COMPLETED => 'Recibida en ' . $this->toLocation->name,
            TransferStatus::CANCELLED => 'Cancelada',
            default => 'Estado desconocido',
        };
    }
}
