<?php

namespace App\Models;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Models\Sale;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Support\DocumentSequence;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'client_request_id',
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
        'order_date' => 'date:Y-m-d',
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

    /**
     * Guarda los renglones (ya con su precio definitivo, ver
     * SalePricing::priceLines) y deja subtotal y total como la suma exacta de
     * sus importes. La usan el alta y la edición de pedidos y el kiosko: un
     * pedido se calcula igual venga de donde venga.
     *
     * En centavos enteros, como el punto de venta y la app: el renglón se
     * redondea y el total es la suma exacta de los renglones (antes se
     * sumaban flotantes sin redondear y el total podía no cuadrar con sus
     * renglones por un centavo).
     *
     * @param array<int, array{product_id: int, package_id?: int|null, unit_id: int, requested_quantity: mixed, unit_price: mixed}> $details
     */
    public function writeDetails(array $details): void
    {
        $subtotalCents = 0;
        foreach ($details as $detail) {
            $lineCents = Money::line($detail['requested_quantity'], $detail['unit_price'])['subtotal'];
            $subtotalCents += $lineCents;

            SalesOrderDetail::create([
                'sales_order_id' => $this->id,
                'product_id' => $detail['product_id'],
                'package_id' => $detail['package_id'] ?? null,
                'unit_id' => $detail['unit_id'],
                'requested_quantity' => Money::roundQuantity($detail['requested_quantity']),
                'prepared_quantity' => 0,
                'delivered_quantity' => 0,
                'reserved_quantity_base' => 0,
                'delivered_quantity_base' => 0,
                'unit_price' => Money::round($detail['unit_price']),
                'line_subtotal' => Money::fromCents($lineCents),
                'line_total' => Money::fromCents($lineCents),
            ]);
        }

        $subtotal = Money::fromCents($subtotalCents);
        $this->forceFill([
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'tax_amount' => $this->tax_amount ?? 0,
            'discount_amount' => $this->discount_amount ?? 0,
        ])->save();
    }

    public function transitionTo(SalesOrderStatus $nextStatus): bool
    {
        // Regla de negocio (422 {message, code}): el motivo llega tal cual a la app.
        if (!$this->status->canTransitionTo($nextStatus, $this->service_mode)) {
            throw new BusinessRuleException(
                "El pedido está «{$this->status->label()}» y no puede pasar a «{$nextStatus->label()}».",
                'invalid_order_transition',
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
        // Serie global (order_number es único en toda la tabla).
        $date = now()->format('Ymd');
        $prefix = "SOR-{$date}-";

        $number = DocumentSequence::next(
            "sales_order:{$date}",
            fn () => DocumentSequence::lastUsed(self::query(), 'order_number', $prefix),
        );

        return sprintf('%s%04d', $prefix, $number);
    }
}