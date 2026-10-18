<?php

namespace App\Models;

use App\Support\DocumentSequence;
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
use App\Services\CashMovementService;
use App\Enums\StockLedgerReason;
use App\Exceptions\InsufficientStockException;
use App\Support\StockContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Sale Model - Modelo independiente para ventas
 *
 * @mixin IdeHelperSale
 */
class Sale extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'cash_register_id',
        'cash_register_session_id',
        'user_id',
        'customer_id',
        'sale_number',
        'client_request_id',
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
        'sale_date' => 'date:Y-m-d',
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
                $model->status = SaleStatus::CLOSED;
            }
            if (!$model->sale_date) {
                $model->sale_date = now()->toDateString();
            }
            if (!$model->sale_number) {
                $model->sale_number = self::generateSaleNumber($model->company_id);
            }
        });
    }

    /**
     * Folio de venta SAL-YYYYMMDD-####, consecutivo por empresa y día.
     *
     * Dentro de una transacción, el contador queda bloqueado hasta el commit:
     * pídelo antes de bloquear stock para no cruzar bloqueos con otra venta.
     */
    public static function generateSaleNumber(?int $companyId = null): string
    {
        $date = now()->format('Ymd');
        $prefix = "SAL-{$date}-";

        $number = DocumentSequence::next(
            'sale:' . ($companyId ?? 0) . ":{$date}",
            fn () => DocumentSequence::lastUsed(
                self::withTrashed()->where('company_id', $companyId),
                'sale_number',
                $prefix,
            ),
        );

        return sprintf('%s%04d', $prefix, $number);
    }

    /**
     * Relación con detalles de la venta
     */
    /** Pagos por método (una venta mixta tiene varios). */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

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
     * Transicionar a un estado específico. Solo se permite transicionar a CANCELLED.
     */
    public function transitionTo(SaleStatus $newStatus): bool
    {
        if ($newStatus !== SaleStatus::CANCELLED) {
            throw new Exception("Solo se permite transicionar a CANCELLED");
        }

        return DB::transaction(function () use ($newStatus): bool {
            // El estado se decide con la fila bloqueada, no con el modelo que se
            // cargó antes: dos cancelaciones simultáneas (doble toque, reintento)
            // devolvían el stock y el dinero dos veces. La segunda espera aquí y
            // después ve la venta ya cancelada.
            $current = static::query()->lockForUpdate()->find($this->getKey());

            if ($current) {
                $this->setRawAttributes($current->getAttributes(), true);
            }

            if (!$current || $this->status === SaleStatus::CANCELLED) {
                return false;
            }

            // Turno que recibe la devolución, bloqueado antes de tocar el stock
            // (mismo orden que una venta: turno → stock). Si la venta cobró
            // efectivo y no hay turno abierto en su sucursal, no se cancela.
            $refundSessionId = CashMovementService::refundSessionFor($this);

            // Solo revertir stock si la venta estaba CLOSED (la única forma de existir)
            if ($this->status === SaleStatus::CLOSED) {
                $this->revertStock();
            }
            // Devolver en caja lo cobrado. Va dentro de la transacción: si no
            // se puede registrar, la venta no se cancela a medias.
            CashMovementService::reverseSale($this, $refundSessionId);
            $this->status = $newStatus;
            $this->save();

            return true;
        });
    }

    /**
     * Validar stock y actualizar cuando la venta es completada
     */
    public function validateAndUpdateStock(): void
    {
        $movementService = new MovementService();
        $locationId = $this->location_id;

        $this->assertStockNotReserved($movementService);

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
                    $detail->package_id, // Package si se vendió como paquete
                    StockContext::for(StockLedgerReason::SALE, $this)
                );
            } catch (InsufficientStockException|QueryException $e) {
                // Sin envolver: la de stock ya nombra el producto y la
                // existencia, y tiene que llegar como tal para responder 422
                // (envuelta salía como 500 y con el texto repetido). Un error
                // de base de datos (bloqueo, deadlock) debe seguir siendo
                // interno, no salir con su SQL como "stock insuficiente".
                throw $e;
            } catch (Exception $e) {
                // Personalizar el mensaje de error con el nombre del producto
                throw new Exception(
                    "Stock insuficiente para '{$product->name}'. " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Lo apartado para pedidos (product_location.reserved_stock, al pasar un
     * pedido a "preparando") no se vende: cada producto se valida contra
     * existencia − apartado, sumando paquetes y piezas sueltas del mismo
     * producto. Antes el punto de venta y la pantalla anterior solo veían la
     * existencia y podían vender lo que ya estaba apartado para un pedido.
     *
     * Al cobrar un pedido su propio apartado ya se liberó (checkoutOrder):
     * aquí solo cuenta lo apartado para otros pedidos. Las filas se bloquean
     * en orden de producto, antes de que MovementService las vuelva a tomar.
     */
    private function assertStockNotReserved(MovementService $movementService): void
    {
        $requested = [];
        foreach ($this->details as $detail) {
            $requested[$detail->product_id] = ($requested[$detail->product_id] ?? 0)
                + $movementService->quantityInProductUnit(
                    (int) $detail->product_id,
                    (int) $detail->unit_id,
                    (float) $detail->quantity,
                    $detail->package_id ? (int) $detail->package_id : null,
                );
        }

        if ($requested === []) {
            return;
        }

        ksort($requested);

        $stockRows = DB::table('product_location')
            ->where('location_id', $this->location_id)
            ->whereIn('product_id', array_keys($requested))
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['product_id', 'current_stock', 'reserved_stock'])
            ->keyBy('product_id');

        foreach ($requested as $productId => $quantity) {
            $row = $stockRows->get($productId);
            $reserved = (float) ($row->reserved_stock ?? 0);

            // Sin apartado, MovementService valida contra la existencia.
            if ($reserved <= 0) {
                continue;
            }

            $available = max(0.0, (float) ($row->current_stock ?? 0) - $reserved);
            if ($quantity > $available + 0.000001) {
                throw InsufficientStockException::forProduct((int) $productId, $available, $quantity, (int) $this->location_id);
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
                $detail->package_id, // Package si se vendió como paquete
                StockContext::for(StockLedgerReason::SALE_RETURN, $this, 'Reversa de la venta')
            );
        }
    }

    /**
     * Scope para ventas canceladas
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::CANCELLED);
    }
}
