<?php

namespace App\Models;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Models\Sale;
use App\Models\SalesOrderDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOrder extends Model
{
    use HasFactory;

    protected $table = 'sales_orders';

    protected $fillable = [
        'company_id',
        'location_id',
        'customer_id',
        'created_by',
        'updated_by',
        'order_number',
        'order_date',
        'channel',
        'service_mode',
        'status',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'customer_email_snapshot',
        'promised_at',
        'prepared_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'reserved_at',
        'sale_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'notes',
        'internal_notes',
        'content',
    ];

    protected $casts = [
        'channel' => SalesOrderChannel::class,
        'service_mode' => \App\Enums\SalesOrderServiceMode::class,
        'status' => SalesOrderStatus::class,
        'order_date' => 'date',
        'promised_at' => 'datetime',
        'prepared_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reserved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'content' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (!$model->status) {
                $model->status = SalesOrderStatus::PENDING;
            }

            if (!$model->channel) {
                $model->channel = SalesOrderChannel::ADMIN;
            }

            if (!$model->service_mode) {
                $model->service_mode = \App\Enums\SalesOrderServiceMode::COUNTER;
            }

            if (!$model->order_date) {
                $model->order_date = now()->toDateString();
            }

            if (!$model->order_number) {
                $model->order_number = self::generateOrderNumber();
            }
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(SalesOrderDetail::class, 'sales_order_id');
    }

    public function scopeByStatus(Builder $query, string|SalesOrderStatus $status): Builder
    {
        $value = $status instanceof SalesOrderStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    public function scopeByChannel(Builder $query, string|SalesOrderChannel $channel): Builder
    {
        $value = $channel instanceof SalesOrderChannel ? $channel->value : $channel;

        return $query->where('channel', $value);
    }

    public function scopeByServiceMode(Builder $query, string|\App\Enums\SalesOrderServiceMode $serviceMode): Builder
    {
        $value = $serviceMode instanceof \App\Enums\SalesOrderServiceMode ? $serviceMode->value : $serviceMode;

        return $query->where('service_mode', $value);
    }

    public function transitionTo(SalesOrderStatus $nextStatus): bool
    {
        if (!$this->status->canTransitionTo($nextStatus, $this->service_mode)) {
            throw new InvalidArgumentException(
                "No se puede transicionar de '{$this->status->label()}' a '{$nextStatus->label()}'"
            );
        }

        DB::transaction(function () use ($nextStatus): void {
            $this->status = $nextStatus;

            match ($nextStatus) {
                SalesOrderStatus::PREPARING => $this->prepared_at = now(),
                SalesOrderStatus::IN_TRANSIT => $this->shipped_at = now(),
                SalesOrderStatus::DELIVERED => $this->delivered_at = now(),
                SalesOrderStatus::CANCELLED => $this->cancelled_at = now(),
                default => null,
            };

            $this->save();
        });

        return true;
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->details()->sum('line_subtotal');
        $lineTotal = $this->details()->sum('line_total');

        $this->forceFill([
            'subtotal' => $subtotal,
            'total_amount' => $lineTotal,
        ])->save();
    }

    public static function generateOrderNumber(): string
    {
        $datePrefix = now()->format('Ymd');
        $lastOrder = self::whereDate('created_at', today())
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($lastOrder && preg_match('/(\d{4})$/', $lastOrder->order_number ?? '', $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('SOR-%s-%04d', $datePrefix, $sequence);
    }
}