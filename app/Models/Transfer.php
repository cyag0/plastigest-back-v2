<?php

namespace App\Models;

use App\Models\Admin\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Admin\Location;

/**
 * Transfer Model - Wrapper para movements con movement_type = 'transfer' y movement_reason = 'transfer'
 *
 * @mixin IdeHelperTransfer
 */
class Transfer extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'transfer_date' => 'date',
        'content' => 'json'
    ];

    /**
     * Configurar automáticamente el tipo de movimiento como transfer
     */
    protected static function booted()
    {
        parent::booted();

        // Automáticamente filtrar solo transferencias
        static::addGlobalScope('transfer_scope', function (Builder $builder) {
            $builder->where('movement_type', 'transfer')
                ->where('movement_reason', 'transfer');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_type = 'transfer';
            $model->movement_reason = 'transfer';
            $model->reference_type = 'transfer';

            if (!$model->status) {
                $model->status = 'ordered';
            }
        });
    }

    /**
     * Obtener los detalles de la transferencia
     */
    public function details(): HasMany
    {
        return $this->hasMany(MovementDetail::class, 'movement_id');
    }

    /**
     * Obtener la ubicación de origen
     */
    public function locationOrigin(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_origin_id');
    }

    /**
     * Obtener la ubicación de destino
     */
    public function locationDestination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_destination_id');
    }

    /**
     * Scope para transferencias por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Scope para transferencias por estado
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Peticiones - Transferencias que solicité (location_origin_id = mi ubicación)
     * Estados: ordered, in_transit
     */
    public function scopePetitions(Builder $query, $locationId): Builder
    {
        return $query->where('location_origin_id', $locationId)
            ->whereIn('status', ['ordered', 'in_transit']);
    }

    /**
     * Scope: Envíos - Transferencias que solicité y ya fueron completadas o rechazadas
     * Estados: closed, rejected
     */
    public function scopeShipments(Builder $query, $locationId): Builder
    {
        return $query->where('location_origin_id', $locationId)
            ->whereIn('status', ['closed', 'rejected']);
    }

    /**
     * Scope: Recepciones - Transferencias que recibiré (location_destination_id = mi ubicación)
     * Estados: ordered, in_transit
     */
    public function scopeReceipts(Builder $query, $locationId): Builder
    {
        return $query->where('location_destination_id', $locationId)
            ->whereIn('status', ['ordered', 'in_transit']);
    }

    /**
     * Scope: Historial - Transferencias recibidas completadas o rechazadas (historial)
     * Estados: closed, rejected
     */
    public function scopeTransferHistory(Builder $query, $locationId): Builder
    {
        return $query->where('location_destination_id', $locationId)
            ->whereIn('status', ['closed', 'rejected']);
    }

    /**
     * Scope para transferencias en borrador
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope para transferencias ordenadas
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->where('status', 'ordered');
    }

    /**
     * Scope para transferencias en tránsito
     */
    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', 'in_transit');
    }

    /**
     * Scope para transferencias completadas
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope para transferencias rechazadas
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Accessor para el número de transferencia
     */
    public function getTransferNumberAttribute(): string
    {
        return $this->content['transfer_number'] ??
            $this->document_number ??
            'TRANS-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de transferencia
     */
    public function getTransferDateAttribute(): string
    {
        return $this->movement_date;
    }

    /**
     * Accessor para el total de la transferencia
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->total_cost ?? 0;
    }

    /**
     * Accessor para obtener el usuario que solicitó
     */
    public function getRequestedByUserAttribute()
    {
        $requestedById = $this->content['requested_by'] ?? $this->user_id;
        if ($requestedById) {
            return User::find($requestedById);
        }
        return null;
    }

    /**
     * Accessor para obtener el usuario que aprobó
     */
    public function getApprovedByUserAttribute()
    {
        $approvedById = $this->content['approved_by'] ?? null;
        if ($approvedById) {
            return User::find($approvedById);
        }
        return null;
    }

    /**
     * Accessor para fecha de aprobación
     */
    public function getApprovedAtAttribute(): ?string
    {
        return $this->content['approved_at'] ?? null;
    }

    /**
     * Accessor para fecha de envío
     */
    public function getShippedAtAttribute(): ?string
    {
        return $this->content['shipped_at'] ?? null;
    }

    /**
     * Accessor para fecha de recepción
     */
    public function getReceivedAtAttribute(): ?string
    {
        return $this->content['received_at'] ?? null;
    }

    /**
     * Accessor para motivo de rechazo
     */
    public function getRejectionReasonAttribute(): ?string
    {
        return $this->content['rejection_reason'] ?? null;
    }

    /**
     * Accessor para notas de envío
     */
    public function getShippingNotesAttribute(): ?string
    {
        return $this->content['shipping_notes'] ?? null;
    }

    /**
     * Accessor para notas de recepción
     */
    public function getReceivingNotesAttribute(): ?string
    {
        return $this->content['receiving_notes'] ?? null;
    }

    /**
     * Accessor para verificar si tiene diferencias
     */
    public function getHasDifferencesAttribute(): bool
    {
        return $this->content['has_differences'] ?? false;
    }

    /**
     * Accessor para obtener envíos (shipments)
     */
    public function getShipmentsAttribute(): array
    {
        return $this->content['shipments'] ?? [];
    }

    /**
     * Accessor para verificar si la recepción está completa
     */
    public function getReceivedCompleteAttribute(): bool
    {
        return $this->content['received_complete'] ?? false;
    }

    /**
     * Accessor para notas de diferencias
     */
    public function getDifferenceNotesAttribute(): ?string
    {
        return $this->content['difference_notes'] ?? null;
    }

    /**
     * Accessor para la fecha de solicitud
     */
    public function getRequestedAtAttribute(): ?string
    {
        return $this->content['requested_at'] ?? $this->movement_date;
    }

    public function Supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function Customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Mutator para establecer información de aprobación
     */
    public function setApprovalInfo(int $userId, string $timestamp): void
    {
        $content = $this->content ?? [];
        $content['approved_by'] = $userId;
        $content['approved_at'] = $timestamp;
        $this->content = $content;
    }

    /**
     * Mutator para establecer información de rechazo
     */
    public function setRejectionInfo(int $userId, string $reason, string $timestamp): void
    {
        $content = $this->content ?? [];
        $content['rejected_by'] = $userId;
        $content['rejected_at'] = $timestamp;
        $content['rejection_reason'] = $reason;
        $this->content = $content;
    }

    /**
     * Mutator para establecer información de envío
     */
    public function setShippingInfo(array $shipments, ?string $notes, string $timestamp): void
    {
        $content = $this->content ?? [];
        $content['shipments'] = $shipments;
        $content['shipped_at'] = $timestamp;
        if ($notes) {
            $content['shipping_notes'] = $notes;
        }
        $this->content = $content;
    }

    /**
     * Mutator para establecer información de recepción
     */
    public function setReceivingInfo(bool $complete, bool $hasDifferences, ?string $notes, string $timestamp): void
    {
        $content = $this->content ?? [];
        $content['received_at'] = $timestamp;
        $content['received_complete'] = $complete;
        $content['has_differences'] = $hasDifferences;
        if ($notes) {
            $content['receiving_notes'] = $notes;
        }
        if ($hasDifferences && $notes) {
            $content['difference_notes'] = $notes;
        }
        $this->content = $content;
    }
}
