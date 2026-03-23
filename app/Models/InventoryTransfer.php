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
        'content',
        'requested_by',
        'total_cost',
        'notes',
    ];

    protected $casts = [
        'status' => TransferStatus::class,
        'content' => 'array',
        'total_cost' => 'decimal:2',
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
            if (!$model->status) {
                $model->status = TransferStatus::PENDING;
            }
            if (!$model->content) {
                $model->content = self::defaultWorkflowContent((int) ($model->requested_by ?? 0));
            }
        });
    }

    /**
     * Estructura JSON completa de content para transferencias.
     *
     * {
     *   "current_step": 1,
     *   "step_1": {
     *     "status": "pending|approved|rejected",
     *     "created_at": "ISO-8601",
     *     "requested_by": 10,
     *     "approved_at": "ISO-8601|null",
     *     "approved_by": 20,
     *     "rejected_at": "ISO-8601|null",
     *     "rejected_by": 21,
     *     "reason": "texto",
     *     "items": [
     *       {
     *         "detail_id": 1,
     *         "quantity_requested": 5.0
     *       }
     *     ]
     *   },
     *   "step_2": {
     *     "status": "shipped",
     *     "shipped_at": "ISO-8601",
     *     "shipped_by": 30,
     *     "items_count": 3
     *   },
     *   "step_3": {
     *     "status": "received",
     *     "received_at": "ISO-8601",
     *     "received_by": 40,
     *     "items_count": 3
     *   }
     * }
     */
    public static function defaultWorkflowContent(int $requestedBy = 0): array
    {
        return [
            'current_step' => 1,
            'ended_at_step' => 1,
            'flow_state' => 'in_progress',
            'step_1' => [
                'status' => 'pending',
                'created_at' => now()->toISOString(),
                'requested_by' => $requestedBy,
                'items' => [],
            ],
            'step_2' => null,
            'step_3' => null,
            'step_4' => [
                'status' => 'pending',
            ],
            'progress' => [
                'step_1' => [
                    'visited' => true,
                    'result' => 'pending',
                    'ended_here' => false,
                ],
                'step_2' => [
                    'visited' => false,
                    'result' => 'pending',
                    'ended_here' => false,
                ],
                'step_3' => [
                    'visited' => false,
                    'result' => 'pending',
                    'ended_here' => false,
                ],
                'step_4' => [
                    'visited' => false,
                    'result' => 'pending',
                    'ended_here' => false,
                ],
            ],
        ];
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
     * Cancelar transferencia
     * NOTA: La lógica de reversión de stock ahora se maneja en TransferService
     */
    public function cancel(string $reason = null): bool
    {
        if (!$this->status->canCancel()) {
            throw new Exception("Esta transferencia no puede ser cancelada");
        }

        $this->status = TransferStatus::CANCELLED;
        $this->rejection_reason = $reason;
        $this->cancelled_at = now();

        return $this->save();
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
        return $this->status === TransferStatus::PENDING && $this->to_location_id === $locationId;
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
