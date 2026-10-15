<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderStockService
{
    /** Inicio del motivo de validateDraftDetails cuando no alcanza el stock. */
    public const INSUFFICIENT_STOCK_PREFIX = 'Stock insuficiente para ';

    public function __construct(
        private readonly MovementService $movementService = new MovementService(),
    ) {
    }

    /**
     * Revisa que alcance el stock disponible (existencia - apartado) para los
     * renglones. Con $forUpdate las existencias se leen bloqueadas (en orden de
     * producto, como Sale::assertStockNotReserved) y con su último valor: lo
     * usa la reserva, que aparta justo después. Sin bloqueo es una lectura
     * previa (la del alta y la edición de un pedido, que no apartan).
     */
    public function validateDraftDetails(int $locationId, array $details, bool $forUpdate = false): void
    {
        if ($details === []) {
            return;
        }

        $requestedByProduct = [];
        $errorKeysByProduct = [];

        foreach ($details as $index => $detail) {
            $productId = (int) $detail['product_id'];

            try {
                $requestedBaseQuantity = $this->movementService->quantityInProductUnit(
                    $productId,
                    (int) $detail['unit_id'],
                    (float) $detail['requested_quantity'],
                    isset($detail['package_id']) ? (int) $detail['package_id'] : null,
                );
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages([
                    "details.{$index}.requested_quantity" => [$exception->getMessage()],
                ]);
            }

            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $requestedBaseQuantity;
            $errorKeysByProduct[$productId] ??= "details.{$index}.requested_quantity";
        }

        $products = Product::with('unit')
            ->whereIn('id', array_keys($requestedByProduct))
            ->get()
            ->keyBy('id');

        $stockByProduct = DB::table('product_location')
            ->where('location_id', $locationId)
            ->whereIn('product_id', array_keys($requestedByProduct))
            ->when($forUpdate, fn($query) => $query->orderBy('product_id')->lockForUpdate())
            ->get(['product_id', 'current_stock', 'reserved_stock'])
            ->keyBy('product_id');

        $errors = [];

        foreach ($requestedByProduct as $productId => $requestedQuantity) {
            $product = $products->get($productId);
            $stockRow = $stockByProduct->get($productId);
            $availableQuantity = max(
                0,
                (float) ($stockRow->current_stock ?? 0) - (float) ($stockRow->reserved_stock ?? 0),
            );

            if ($requestedQuantity <= $availableQuantity + 0.000001) {
                continue;
            }

            $unitLabel = $product?->unit?->abbreviation ?? 'u base';
            $productName = $product?->name ?? "producto {$productId}";

            $errors[$errorKeysByProduct[$productId]] = [
                sprintf(
                    self::INSUFFICIENT_STOCK_PREFIX . '%s. Disponible: %s %s, solicitado: %s %s.',
                    $productName,
                    number_format($availableQuantity, 3, '.', ''),
                    $unitLabel,
                    number_format($requestedQuantity, 3, '.', ''),
                    $unitLabel,
                ),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function reserveOrder(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            // Estado y reservas se leen con el pedido bloqueado: dos "preparar"
            // simultáneos (doble toque, reintento) reservaban el stock dos veces.
            $locked = $this->lockOrder($order);

            if ($locked->status !== SalesOrderStatus::PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['El pedido ya no está pendiente: no se puede volver a reservar.'],
                ]);
            }

            if ($locked->details->isEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['El pedido no tiene productos para reservar.'],
                ]);
            }

            if ((float) $locked->details->sum('reserved_quantity_base') > 0.000001) {
                throw ValidationException::withMessages([
                    'status' => ['El pedido ya tiene inventario reservado.'],
                ]);
            }

            $draftDetails = $locked->details->map(fn ($detail) => [
                'product_id' => $detail->product_id,
                'package_id' => $detail->package_id,
                'unit_id' => $detail->unit_id,
                'requested_quantity' => $detail->requested_quantity,
            ])->all();

            $this->reserveLockedOrder($locked, $draftDetails);

            // Quien llamó sigue con su instancia (el controlador la transiciona
            // después): se le pasa el estado recién guardado.
            $this->syncFrom($order, $locked);
        });
    }

    /**
     * Reserva el stock de un pedido ya bloqueado y validado. Se llama dentro de
     * la transacción de reserveOrder().
     *
     * @param  array<int, array{product_id:int, package_id:?int, unit_id:int, requested_quantity:mixed}>  $draftDetails
     */
    private function reserveLockedOrder(SalesOrder $order, array $draftDetails): void
    {
        // Se valida con las existencias ya bloqueadas: con una lectura sin
        // bloqueo dos "preparar" simultáneos de pedidos distintos veían el
        // mismo disponible y apartaban los dos (en REPEATABLE READ esa lectura
        // es de la foto de la transacción, aunque el bloqueo llegue después).
        $this->validateDraftDetails($order->location_id, $draftDetails, true);

        $reservedByProduct = [];

        foreach ($order->details as $detail) {
            $baseQuantity = $this->movementService->quantityInProductUnit(
                $detail->product_id,
                $detail->unit_id,
                (float) $detail->requested_quantity,
                $detail->package_id,
            );

            $reservedByProduct[$detail->product_id] = ($reservedByProduct[$detail->product_id] ?? 0) + $baseQuantity;

            $detail->forceFill([
                'prepared_quantity' => $detail->requested_quantity,
                'reserved_quantity_base' => $baseQuantity,
            ])->save();
        }

        $productStocks = DB::table('product_location')
            ->where('location_id', $order->location_id)
            ->whereIn('product_id', array_keys($reservedByProduct))
            ->lockForUpdate()
            ->get(['product_id', 'reserved_stock'])
            ->keyBy('product_id');

        foreach ($reservedByProduct as $productId => $reservedQuantity) {
            $currentReservedStock = (float) ($productStocks->get($productId)->reserved_stock ?? 0);

            DB::table('product_location')
                ->where('location_id', $order->location_id)
                ->where('product_id', $productId)
                ->update([
                    'reserved_stock' => $currentReservedStock + $reservedQuantity,
                    'updated_at' => now(),
                ]);
        }

        $order->forceFill(['reserved_at' => now()])->save();
    }

    public function releaseOrder(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            // Las reservas se leen con el pedido bloqueado y de la base, no de la
            // instancia recibida: si dos peticiones liberaban el mismo pedido
            // (dos cancelaciones, o cancelar y cobrar a la vez) se restaba dos
            // veces y se perdían reservas de otros pedidos.
            $locked = $this->lockOrder($order);

            $reservedByProduct = [];
            foreach ($locked->details as $detail) {
                $reservedQuantity = (float) $detail->reserved_quantity_base;
                if ($reservedQuantity <= 0) {
                    continue;
                }

                $reservedByProduct[$detail->product_id] = ($reservedByProduct[$detail->product_id] ?? 0) + $reservedQuantity;
            }

            if ($reservedByProduct !== []) {
                $productStocks = DB::table('product_location')
                    ->where('location_id', $locked->location_id)
                    ->whereIn('product_id', array_keys($reservedByProduct))
                    ->lockForUpdate()
                    ->get(['product_id', 'reserved_stock'])
                    ->keyBy('product_id');

                foreach ($reservedByProduct as $productId => $reservedQuantity) {
                    $currentReservedStock = (float) ($productStocks->get($productId)->reserved_stock ?? 0);

                    DB::table('product_location')
                        ->where('location_id', $locked->location_id)
                        ->where('product_id', $productId)
                        ->update([
                            'reserved_stock' => max(0, $currentReservedStock - $reservedQuantity),
                            'updated_at' => now(),
                        ]);
                }
            }

            foreach ($locked->details as $detail) {
                if ((float) $detail->reserved_quantity_base != 0.0) {
                    $detail->forceFill(['reserved_quantity_base' => 0])->save();
                }
            }

            $locked->forceFill(['reserved_at' => null])->save();

            $this->syncFrom($order, $locked);
        });
    }

    /**
     * Convert a sales order into a closed sale, releasing the reservation
     * and decrementing real stock atomically.
     *
     * El cobro entra a caja igual que una venta normal: un movimiento de
     * ingreso (y su pago) en el turno abierto de quien cobra en la sucursal
     * del pedido, si lo tiene.
     *
     * Con client_request_id (la llave del intento de cobro) es idempotente: si
     * el pedido ya se cobró con esa llave se devuelve esa venta y no se cobra
     * otra vez.
     *
     * @param  array{payment_method?: string, paid_amount?: float|int, notes?: string|null, client_request_id?: string|null}  $payment
     */
    public function checkoutOrder(SalesOrder $order, array $payment = []): Sale
    {
        return DB::transaction(function () use ($order, $payment): Sale {
            // Se decide con el pedido bloqueado: dos cobros simultáneos (doble
            // toque, reintento) creaban dos ventas y descontaban el stock dos veces.
            $order = $this->lockOrder($order);

            // Reintento de un cobro que ya entró (misma llave): con el pedido
            // bloqueado el segundo envío espera al primero y aquí ve su venta.
            $requestId = $payment['client_request_id'] ?? null;
            if ($requestId && $order->sale_id) {
                $previous = Sale::whereKey($order->sale_id)->where('client_request_id', $requestId)->first();
                if ($previous) {
                    return $previous->fresh('details');
                }
            }

            if ($order->status === SalesOrderStatus::CANCELLED || $order->status === SalesOrderStatus::DELIVERED) {
                throw ValidationException::withMessages([
                    'status' => ['El pedido ya no puede cobrarse en su estado actual.'],
                ]);
            }

            if ($order->sale_id) {
                throw ValidationException::withMessages([
                    'status' => ['El pedido ya está vinculado a una venta.'],
                ]);
            }

            if ($order->details->isEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['El pedido no tiene productos para cobrar.'],
                ]);
            }

            // 1. Importes en centavos enteros (App\Support\Money): el total es
            //    la suma exacta de los renglones, como en el punto de venta.
            $subtotalCents = (int) $order->details->sum(fn($detail) => Money::toCents($detail->line_subtotal));
            $totalCents = $order->total_amount !== null ? Money::toCents($order->total_amount) : $subtotalCents;
            $subtotal = Money::fromCents($subtotalCents);
            $total = Money::fromCents($totalCents);
            $paymentMethod = $payment['payment_method'] ?? 'cash';

            // Crédito, igual que en SaleService: sin monto no se cobra nada
            // (antes tomaba el total, marcaba la venta como pagada y metía un
            // ingreso "otro" a caja: la cuenta por cobrar desaparecía). Un
            // anticipo va con su forma de pago real, nunca como "crédito".
            if ($paymentMethod === 'credit') {
                if (isset($payment['paid_amount']) && Money::toCents($payment['paid_amount']) > 0) {
                    throw SaleService::creditWithPayment();
                }
                $payment['paid_amount'] = 0;
            }

            $paidCents = isset($payment['paid_amount']) ? Money::toCents($payment['paid_amount']) : $totalCents;

            // Lo que excede el total es cambio, no dinero que se queda: en
            // efectivo se guarda como recibido/cambio, igual que en el POS.
            $appliedCents = min($paidCents, $totalCents);
            $appliedAmount = Money::fromCents($appliedCents);
            $paymentStatus = $appliedCents >= $totalCents
                ? 'paid'
                : ($appliedCents > 0 ? 'partial' : 'pending');

            // 2. Turno de quien cobra en la sucursal del pedido, bloqueado (un
            //    cierre simultáneo espera o el cobro ve el turno cerrado). El
            //    efectivo exige turno (C14): sin él no lo esperaría ninguna caja.
            $session = app(CashRegisterSessionService::class)->lockOpenForUser(Auth::id(), (int) $order->location_id);
            if ($paymentMethod === 'cash' && $appliedCents > 0 && !$session) {
                throw CashRegisterSessionService::cashNeedsOpenShift();
            }

            // El folio se pide antes de tocar stock: su contador queda bloqueado
            // hasta el commit y una venta del POS lo toma en ese mismo orden
            // (turno → contador → stock). Al revés podían bloquearse mutuamente.
            $saleNumber = Sale::generateSaleNumber((int) $order->company_id);

            // 3. Release any pending reservation so the sale's decrement
            //    operates on the same baseline as the previously available stock.
            $this->releaseOrder($order);

            $sale = Sale::create([
                'sale_number' => $saleNumber,
                'client_request_id' => $requestId,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'cash_register_id' => $session?->cash_register_id,
                'cash_register_session_id' => $session?->id,
                'user_id' => Auth::id() ?? $order->updated_by ?? $order->created_by,
                'customer_id' => $order->customer_id,
                'sale_date' => now()->toDateString(),
                'status' => SaleStatus::CLOSED,
                'subtotal' => $subtotal,
                'tax' => 0,
                'discount' => 0,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'paid_amount' => $appliedAmount,
                'payment_history' => $appliedAmount > 0
                    ? [[
                        'amount' => $appliedAmount,
                        'payment_method' => $paymentMethod,
                        'date' => now()->toDateTimeString(),
                        'notes' => $payment['notes'] ?? null,
                    ]]
                    : [],
                'content' => [
                    'payment_method' => $paymentMethod,
                    'customer_name' => $order->customer_name_snapshot,
                    'customer_phone' => $order->customer_phone_snapshot,
                    'customer_email' => $order->customer_email_snapshot,
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->order_number,
                    'notes' => $payment['notes'] ?? $order->notes,
                ],
                'notes' => $payment['notes'] ?? $order->notes,
            ]);

            foreach ($order->details as $detail) {
                $lineSubtotal = (float) $detail->line_subtotal;
                $lineTotal = (float) $detail->line_total;

                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $detail->product_id,
                    'package_id' => $detail->package_id,
                    'unit_id' => $detail->unit_id,
                    'quantity' => $detail->requested_quantity,
                    'unit_price' => $detail->unit_price,
                    'subtotal' => $lineSubtotal,
                    'tax' => 0,
                    'discount' => 0,
                    'total' => $lineTotal,
                ]);
            }

            // 4. Decrement real stock via MovementService.
            $sale->load('details');
            $sale->validateAndUpdateStock();

            // 5. Register the payment in cash, like any other sale. Antes el
            //    cobro de un pedido no generaba movimiento de caja: el efectivo
            //    no aparecía en el corte ni en el efectivo esperado del turno.
            if ($appliedAmount > 0) {
                if (in_array($paymentMethod, SalePayment::METHODS, true)) {
                    $isCash = $paymentMethod === 'cash';
                    $sale->payments()->create([
                        'method' => $paymentMethod,
                        'amount' => $appliedAmount,
                        'tendered' => $isCash ? Money::fromCents(max($paidCents, $appliedCents)) : null,
                        'change' => $isCash ? Money::fromCents(max($paidCents - $appliedCents, 0)) : null,
                    ]);
                }

                CashMovementService::fromSale($sale, $appliedAmount, $paymentMethod, null, $session?->id);
            }

            // 6. Link the sale and mark the order as delivered.
            $order->forceFill([
                'sale_id' => $sale->id,
                'status' => SalesOrderStatus::DELIVERED,
                'delivered_at' => $order->delivered_at ?: now(),
            ])->save();

            foreach ($order->details as $detail) {
                $detail->forceFill([
                    'delivered_quantity' => $detail->requested_quantity,
                ])->save();
            }

            return $sale->fresh('details');
        });
    }

    /**
     * Relee el pedido (con sus renglones) bloqueando su fila hasta que termine
     * la transacción. Las decisiones que mueven stock o dinero se toman con
     * este estado, no con el de la instancia que llegó, que pudo cargarse
     * antes de que otra petición lo cambiara.
     */
    private function lockOrder(SalesOrder $order): SalesOrder
    {
        $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
        $locked->load('details');

        return $locked;
    }

    /** Pasa a la instancia de quien llamó el estado recién guardado. */
    private function syncFrom(SalesOrder $target, SalesOrder $source): void
    {
        if ($target === $source) {
            return;
        }

        $target->setRawAttributes($source->getAttributes(), true);
        $target->setRelation('details', $source->details);
    }
}
