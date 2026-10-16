<?php

namespace App\Services;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\KioskOrderItemsException;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Support\ClientRequest;
use App\Support\DocumentSequence;
use App\Support\SalePricing;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pedidos del kiosko de autoservicio: el cliente arma su pedido en el kiosko
 * y paga en caja.
 *
 * - create(): pedido pendiente de mostrador (canal kiosk) con número corto
 *   por sucursal y día, precios de catálogo y su mercancía apartada, todo en
 *   una transacción.
 * - chargeAtPos(): el cajero lo cobra como cualquier venta del punto de
 *   venta; en la misma transacción se libera lo apartado y se liga la venta.
 * - cancelAtPos() y expireStale(): lo cancelan (el cliente se fue sin pagar o
 *   pasó order_ttl_minutes) y liberan lo apartado.
 *
 * Un pedido del kiosko se decide siempre con su fila bloqueada: cobrarlo,
 * cancelarlo y vencerlo a la vez no se pisan; gana quien lo bloqueó primero
 * y los demás ven el estado final.
 */
class KioskOrderService
{
    /** code de los 422 cuando el pedido ya no se puede cobrar ni cancelar. */
    public const UNAVAILABLE = 'kiosk_order_unavailable';

    private const CHARGED_OR_CANCELLED = 'Ese pedido ya se cobró o se canceló.';

    public function __construct(
        private readonly KioskCatalog $catalog,
        private readonly SalesOrderStockService $stock,
        private readonly CashRegisterSessionService $sessions,
    ) {
    }

    /** Minutos que un pedido espera a que lo cobren antes de vencer. */
    public static function ttlMinutes(): int
    {
        return max(1, (int) config('kiosk.order_ttl_minutes', 120));
    }

    public static function expiresAt(SalesOrder $order): ?CarbonInterface
    {
        return $order->created_at?->copy()->addMinutes(self::ttlMinutes());
    }

    /**
     * POST kiosk/orders. Con la llave (client_request_id) de un pedido del
     * kiosko que ya existe en la sucursal devuelve ese pedido y no crea otro.
     *
     * @param array<int, array{product_id: int, package_id?: int|null, quantity: int}> $items
     * @return array{0: SalesOrder, 1: bool} El pedido y si se creó en esta llamada.
     */
    public function create(int $companyId, int $locationId, int $userId, string $requestId, ?string $customerName, array $items): array
    {
        if ($existing = $this->orderForRequest($companyId, $locationId, $requestId)) {
            return [$existing, false];
        }

        $lines = $this->priceItems($companyId, $locationId, $items);

        try {
            $order = DB::transaction(function () use ($companyId, $locationId, $userId, $requestId, $customerName, $lines): SalesOrder {
                $now = now();

                // Folios antes que existencias, como cualquier alta: el número
                // corto de la sucursal y el folio del pedido (en el alta del modelo).
                $order = SalesOrder::create([
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'client_request_id' => $requestId,
                    'order_date' => $now->toDateString(),
                    'ticket_number' => $this->nextTicketNumber($locationId, $now),
                    'channel' => SalesOrderChannel::KIOSK,
                    'service_mode' => SalesOrderServiceMode::COUNTER,
                    'status' => SalesOrderStatus::PENDING,
                    'customer_name_snapshot' => $customerName,
                ]);

                // Mismos renglones y totales que el alta normal de pedidos.
                $order->writeDetails(array_column($lines, 'detail'));

                $this->assertAvailable($locationId, $lines);
                $this->stock->reserveOrder($order);

                return $order;
            });
        } catch (QueryException $e) {
            // Otro envío con la misma llave se registró primero: este se
            // revirtió completo (pedido, número y apartado) y se responde aquel.
            if (ClientRequest::isDuplicate($e) && ($existing = $this->orderForRequest($companyId, $locationId, $requestId))) {
                return [$existing, false];
            }

            throw $e;
        }

        return [$order->load(self::presentRelations()), true];
    }

    /**
     * GET pos/kiosk-orders: los pendientes de la sucursal, del más viejo al
     * más nuevo. Uno que ya pasó su hora pero aún no vence (el comando corre
     * cada 10 minutos) sigue apartado y se puede cobrar.
     *
     * @return Collection<int, SalesOrder>
     */
    public function pendingAt(int $companyId, int $locationId): Collection
    {
        return SalesOrder::query()
            ->kiosk()
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->where('status', SalesOrderStatus::PENDING->value)
            ->whereNull('sale_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(self::presentRelations())
            ->get();
    }

    /**
     * Cobro de un pedido del kiosko en el punto de venta (POST pos/sales con
     * kiosk_order_id), en UNA transacción con la venta:
     *
     *  1. El pedido bloqueado: tiene que ser del kiosko, de la sucursal del
     *     turno, pendiente y sin venta. Si no, 422 kiosk_order_unavailable.
     *  2. Turno → folio de la venta → existencias: el mismo orden de
     *     bloqueos que cualquier cobro (al revés podían bloquearse con una
     *     venta del punto de venta que vende lo mismo).
     *  3. Se libera lo apartado antes de validar el stock de la venta (si no,
     *     su propio apartado la bloquea) y se crea la venta con los renglones
     *     del cajero, que pueden diferir del pedido.
     *  4. Se liga: el pedido queda entregado con su venta.
     *
     * Si algo falla se revierte todo y el pedido conserva su apartado. Un
     * reintento con la llave del cobro que ya lo ligó devuelve esa venta.
     *
     * @param Closure(array<string, mixed>): Sale $createSale Crea la venta del
     *   punto de venta (SaleService::create) con estos datos extra: sale_number,
     *   customer_name y content.
     * @return array{0: Sale, 1: bool} La venta y si se creó en esta llamada.
     */
    public function chargeAtPos(int $orderId, CashRegisterSession $session, ?string $requestId, Closure $createSale): array
    {
        return DB::transaction(function () use ($orderId, $session, $requestId, $createSale): array {
            $order = SalesOrder::query()
                ->kiosk()
                ->where('company_id', $session->company_id)
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw self::unavailable('Ese pedido del kiosko ya no existe.');
            }

            // Reintento de este mismo cobro: con el pedido bloqueado el segundo
            // envío espera al primero y aquí ve la venta que dejó ligada.
            if ($requestId && $order->sale_id) {
                $previous = Sale::query()->whereKey($order->sale_id)->where('client_request_id', $requestId)->first();
                if ($previous) {
                    return [$previous, false];
                }
            }

            if ((int) $order->location_id !== (int) $session->location_id) {
                throw self::unavailable('Ese pedido es de otra sucursal.');
            }
            if ($order->status !== SalesOrderStatus::PENDING || $order->sale_id) {
                throw self::unavailable(self::CHARGED_OR_CANCELLED);
            }

            $this->sessions->lockOpen((int) $session->id, (int) $session->location_id);
            $saleNumber = Sale::generateSaleNumber((int) $order->company_id);

            $this->stock->releaseOrder($order);

            $sale = $createSale([
                'sale_number' => $saleNumber,
                'customer_name' => $order->customer_name_snapshot,
                'content' => [
                    'channel' => SalesOrderChannel::KIOSK->value,
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->order_number,
                    'ticket_number' => $order->ticket_number,
                ],
            ]);

            $order->forceFill([
                'sale_id' => $sale->id,
                'status' => SalesOrderStatus::DELIVERED,
                'delivered_at' => now(),
                'updated_by' => $sale->user_id ?? $order->updated_by,
            ])->save();

            foreach ($order->details as $detail) {
                $detail->forceFill(['delivered_quantity' => $detail->requested_quantity])->save();
            }

            return [$sale, true];
        });
    }

    /**
     * POST pos/kiosk-orders/{id}/cancel: el cliente se fue sin pagar. Cancela
     * el pedido pendiente de la sucursal y libera lo apartado.
     */
    public function cancelAtPos(int $orderId, int $companyId, int $locationId, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $companyId, $locationId, $userId): SalesOrder {
            $order = SalesOrder::query()
                ->kiosk()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw (new ModelNotFoundException())->setModel(SalesOrder::class, [$orderId]);
            }
            if ((int) $order->location_id !== $locationId) {
                throw self::unavailable('Ese pedido es de otra sucursal.');
            }
            if ($order->status !== SalesOrderStatus::PENDING || $order->sale_id) {
                throw self::unavailable(self::CHARGED_OR_CANCELLED);
            }

            $this->cancelLocked($order, 'pos', $userId);

            return $order;
        });
    }

    /**
     * kiosk:expire-orders: cancela los pedidos del kiosko que siguen
     * pendientes después de order_ttl_minutes y libera lo apartado. Uno por
     * uno, cada uno en su transacción con el pedido bloqueado y releído: uno
     * que en ese momento se está cobrando o cancelando en caja se deja como
     * quede. Si uno falla se registra y sigue con los demás (la siguiente
     * corrida lo vuelve a intentar).
     *
     * @return int Pedidos cancelados.
     */
    public function expireStale(): int
    {
        $cutoff = now()->subMinutes(self::ttlMinutes());

        $ids = SalesOrder::query()
            ->kiosk()
            ->where('status', SalesOrderStatus::PENDING->value)
            ->whereNull('sale_id')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            try {
                $expired += DB::transaction(function () use ($id, $cutoff): int {
                    $order = SalesOrder::query()->kiosk()->lockForUpdate()->find($id);

                    if (!$order || $order->status !== SalesOrderStatus::PENDING || $order->sale_id || !$order->created_at?->lt($cutoff)) {
                        return 0;
                    }

                    $this->cancelLocked($order, 'expired', null);

                    return 1;
                });
            } catch (\Throwable $e) {
                Log::error('No se pudo vencer un pedido del kiosko', [
                    'sales_order_id' => $id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }

    /**
     * Relaciones que necesita present(), con los renglones en el orden en que
     * se pidieron.
     *
     * @return array<int|string, mixed>
     */
    public static function presentRelations(): array
    {
        return [
            'details' => fn($query) => $query->orderBy('id'),
            'details.product:id,name',
            'details.package:id,package_name',
            'details.unit:id,name',
        ];
    }

    /**
     * Un pedido del kiosko como lo reciben el kiosko (al mandarlo) y el punto
     * de venta (en la lista). `option` es la unidad del producto o el nombre
     * del paquete; los importes, en pesos.
     *
     * @return array<string, mixed>
     */
    public static function present(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'ticket_number' => $order->ticket_number,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name_snapshot,
            'total' => (float) $order->total_amount,
            'created_at' => $order->created_at?->toISOString(),
            'expires_at' => self::expiresAt($order)?->toISOString(),
            'items' => $order->details->map(fn(SalesOrderDetail $detail) => [
                'product_id' => $detail->product_id,
                'package_id' => $detail->package_id,
                'name' => $detail->product?->name,
                'option' => $detail->package_id ? $detail->package?->package_name : $detail->unit?->name,
                'quantity' => (float) $detail->requested_quantity,
                'unit_price' => (float) $detail->unit_price,
                'line_total' => (float) $detail->line_total,
            ])->values()->all(),
        ];
    }

    /**
     * Valida los renglones con las reglas del catálogo del kiosko y les pone
     * el precio de catálogo (SalePricing, sin permiso de descuento: el precio
     * que mande la app no cuenta). Cada renglón va en la unidad del producto;
     * un paquete, por paquetes.
     *
     * @return array<int, array{detail: array<string, mixed>, product: Product, package: ?ProductPackage, base: float}>
     *   base: la cantidad en la unidad del producto (un paquete × su contenido).
     *
     * @throws KioskOrderItemsException product_unavailable
     */
    private function priceItems(int $companyId, int $locationId, array $items): array
    {
        $productIds = array_values(array_unique(array_map(fn($item) => (int) $item['product_id'], $items)));
        $products = $this->catalog->products($companyId, $locationId, $productIds)->keyBy('id');

        $lines = [];
        $unavailable = [];
        foreach (array_values($items) as $item) {
            $product = $products->get((int) $item['product_id']);
            $packageId = !empty($item['package_id']) ? (int) $item['package_id'] : null;
            $package = $product && $packageId ? KioskCatalog::packages($product)->firstWhere('id', $packageId) : null;

            if (!$product || ($packageId ? !$package : !KioskCatalog::soldLoose($product))) {
                $unavailable[] = ['product_id' => (int) $item['product_id'], 'package_id' => $packageId, 'available' => 0];
                continue;
            }

            $quantity = (int) $item['quantity'];
            $lines[] = [
                'detail' => [
                    'product_id' => $product->id,
                    'package_id' => $package?->id,
                    // Los paquetes se piden en la unidad del producto, como en
                    // el punto de venta: el stock se mueve por su contenido.
                    'unit_id' => (int) $product->unit_id,
                    'requested_quantity' => $quantity,
                ],
                'product' => $product,
                'package' => $package,
                'base' => $package ? $quantity * (float) $package->quantity_per_package : (float) $quantity,
            ];
        }

        if ($unavailable !== []) {
            throw $this->unavailableItems($companyId, $unavailable);
        }

        foreach (SalePricing::priceLines(array_column($lines, 'detail'), false, $companyId) as $i => $detail) {
            $lines[$i]['detail'] = $detail;
        }

        return $lines;
    }

    /**
     * Con las existencias bloqueadas (en orden de producto, como toda salida
     * de stock) revisa que alcance para el pedido completo; lo de un mismo
     * producto suelto y en paquetes se suma. Si no alcanza: 422
     * insufficient_stock con lo que queda de cada opción de esos productos.
     * reserveOrder vuelve a revisar y aparta con estas mismas filas, que
     * siguen bloqueadas hasta el final de la transacción.
     *
     * @param array<int, array{product: Product, package: ?ProductPackage, base: float}> $lines
     *
     * @throws KioskOrderItemsException insufficient_stock
     */
    private function assertAvailable(int $locationId, array $lines): void
    {
        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['product']->id] = ($requested[$line['product']->id] ?? 0) + $line['base'];
        }
        ksort($requested);

        $stock = DB::table('product_location')
            ->where('location_id', $locationId)
            ->whereIn('product_id', array_keys($requested))
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['product_id', 'current_stock', 'reserved_stock'])
            ->keyBy('product_id');

        $available = [];
        $short = [];
        foreach ($requested as $productId => $quantity) {
            $row = $stock->get($productId);
            $available[$productId] = round(max(0.0, (float) ($row->current_stock ?? 0) - (float) ($row->reserved_stock ?? 0)), 3);

            // Misma tolerancia que SalesOrderStockService::validateDraftDetails.
            if ($quantity > $available[$productId] + 0.000001) {
                $short[$productId] = true;
            }
        }

        if ($short === []) {
            return;
        }

        $items = [];
        $names = [];
        foreach ($lines as $line) {
            $productId = $line['product']->id;
            if (!isset($short[$productId])) {
                continue;
            }

            $names[$productId] = $line['product']->name;
            $items[] = [
                'product_id' => $productId,
                'package_id' => $line['package']?->id,
                'available' => $line['package']
                    ? KioskCatalog::fullPackages($available[$productId], $line['package']->quantity_per_package)
                    : $available[$productId],
            ];
        }

        throw new KioskOrderItemsException('Ya no alcanza: ' . implode(', ', $names) . '.', 'insufficient_stock', $items);
    }

    /**
     * Siguiente número corto de la sucursal en el día (1, 2, 3…), serie
     * kiosk_ticket:{sucursal}:{Ymd}. Se revierte con el pedido si este no se
     * registra.
     */
    private function nextTicketNumber(int $locationId, CarbonInterface $day): int
    {
        return DocumentSequence::next(
            "kiosk_ticket:{$locationId}:{$day->format('Ymd')}",
            fn() => (int) SalesOrder::query()
                ->where('location_id', $locationId)
                ->where('order_date', $day->toDateString())
                ->max('ticket_number'),
        );
    }

    /**
     * Pedido ya registrado con la llave en la empresa. Si la llave es de otra
     * cosa (un pedido que no es del kiosko o de otra sucursal) no se devuelve
     * ni se crea otro: 422 client_request_conflict, con un mensaje sin jerga
     * porque lo lee el cliente.
     */
    protected function orderForRequest(int $companyId, int $locationId, string $requestId): ?SalesOrder
    {
        $order = SalesOrder::query()
            ->where('company_id', $companyId)
            ->where('client_request_id', $requestId)
            ->first();

        if (!$order) {
            return null;
        }

        if ((int) $order->location_id !== $locationId
            || $order->channel !== SalesOrderChannel::KIOSK
            || $order->ticket_number === null) {
            throw new BusinessRuleException('No pudimos registrar tu pedido. Pide ayuda en caja.', 'client_request_conflict');
        }

        return $order->load(self::presentRelations());
    }

    /**
     * 422 product_unavailable: renglones que el kiosko ya no vende (se
     * desactivó en la sucursal, quedó sin precio, cambió el paquete…).
     *
     * @param array<int, array{product_id: int, package_id: int|null, available: int}> $rows
     */
    private function unavailableItems(int $companyId, array $rows): KioskOrderItemsException
    {
        $names = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_unique(array_column($rows, 'product_id')))
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        $message = $names === []
            ? 'Uno de los productos de tu pedido ya no está disponible. Quítalo para continuar.'
            : 'Ya no está disponible: ' . implode(', ', $names) . '. '
                . (count($names) > 1 ? 'Quítalos' : 'Quítalo') . ' de tu pedido para continuar.';

        return new KioskOrderItemsException($message, 'product_unavailable', $rows);
    }

    /**
     * Cancela un pedido ya bloqueado y validado (pendiente, sin venta) y
     * libera lo apartado, como la cancelación de Pedidos. Deja anotado por
     * qué: 'pos' (el cajero) o 'expired' (venció).
     */
    private function cancelLocked(SalesOrder $order, string $reason, ?int $userId): void
    {
        $order->forceFill([
            'content' => array_merge($order->content ?? [], ['cancel_reason' => $reason]),
            'updated_by' => $userId ?? $order->updated_by,
        ]);
        $order->transitionTo(SalesOrderStatus::CANCELLED);

        $this->stock->releaseOrder($order);
    }

    private static function unavailable(string $message): BusinessRuleException
    {
        return new BusinessRuleException($message, self::UNAVAILABLE);
    }
}
