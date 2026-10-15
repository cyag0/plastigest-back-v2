<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\Admin\CustomerResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Admin\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Services\SalesOrderStockService;
use App\Support\ClientRequest;
use App\Support\CurrentWorker;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\Money;
use App\Support\SalePricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesOrderController extends CrudController
{
    protected string $resource = SalesOrderResource::class;

    protected string $model = SalesOrder::class;

    protected string $dateColumn = 'order_date';

    protected ?string $permissionPrefix = 'sales_orders';
    protected ?string $locationColumn = 'location_id';

    /** El alta corre dentro del cobro rápido (quickCheckout): ver process(). */
    private bool $inQuickCheckout = false;

    protected function indexRelations(): array
    {
        return [
            'location',
            'customer',
            'details.product',
        ];
    }

    protected function handleQuery($query, array $params)
    {
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['channel'])) {
            $query->where('channel', $params['channel']);
        }

        if (!empty($params['service_mode'])) {
            $query->where('service_mode', $params['service_mode']);
        }

        if (!empty($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }

        if (!empty($params['customer_phone'])) {
            $query->where('customer_phone_snapshot', 'like', '%' . $params['customer_phone'] . '%');
        }
    }

    protected function applyBasicFilters($query, array $params)
    {
        parent::applyBasicFilters($query, $params);

        if (!empty($params['search'])) {
            $search = $params['search'];

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('customer_phone_snapshot', 'like', "%{$search}%")
                    ->orWhere('customer_email_snapshot', 'like', "%{$search}%");
            });
        }
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate($this->orderRules());
    }

    /**
     * Reglas del alta de un pedido (también las usa el cobro rápido del
     * kiosco). Cliente y productos de la empresa del contexto: antes se
     * aceptaba cualquier id existente.
     *
     * @return array<string, mixed>
     */
    private function orderRules(): array
    {
        $companyId = CurrentCompany::id();

        return [
            'channel' => 'required|in:kiosk,phone,admin',
            'service_mode' => 'required|in:counter,delivery',
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'customer_name_snapshot' => 'nullable|string|max:255',
            'customer_phone_snapshot' => 'nullable|string|max:25',
            'customer_email_snapshot' => 'nullable|email|max:255',
            'promised_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'details.*.package_id' => 'nullable|exists:product_packages,id',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.requested_quantity' => 'required|numeric|min:0.001',
            'details.*.unit_price' => 'required|numeric|min:0',
            // Llave del alta: el reintento con la misma no crea otro pedido.
            'client_request_id' => ClientRequest::rules(),
        ];
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($model instanceof SalesOrder && $model->status !== SalesOrderStatus::PENDING) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Solo se pueden editar pedidos en estado pendiente.'],
            ]);
        }

        $companyId = CurrentCompany::id();

        return $request->validate([
            'channel' => 'sometimes|required|in:kiosk,phone,admin',
            'service_mode' => 'sometimes|required|in:counter,delivery',
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'customer_name_snapshot' => 'nullable|string|max:255',
            'customer_phone_snapshot' => 'nullable|string|max:25',
            'customer_email_snapshot' => 'nullable|email|max:255',
            'promised_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'details.*.package_id' => 'nullable|exists:product_packages,id',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.requested_quantity' => 'required|numeric|min:0.001',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    protected function getShowRelations(): array
    {
        return [
            'location',
            'customer',
            'sale',
            'details.product',
            'details.package',
            'details.unit',
        ];
    }

    protected function process($callback, array $data, $method): Model
    {
        $requestId = $method === 'create' ? ($data['client_request_id'] ?? null) : null;
        unset($data['client_request_id']);

        // Alta con llave ya registrada (reintento): el mismo pedido, sin crear otro.
        if ($requestId && ($existing = $this->orderForRequest($requestId))) {
            return $existing->fresh($this->getShowRelations());
        }

        try {
            return $this->persistOrder($callback, $data, $method, $requestId);
        } catch (QueryException $e) {
            // Otra petición con la misma llave se registró primero: esta se
            // revirtió y se responde con aquel pedido. En el cobro rápido no:
            // ahí la transacción de afuera decide (también falta la venta).
            if (!$this->inQuickCheckout && $requestId && ClientRequest::isDuplicate($e)
                && ($existing = $this->orderForRequest($requestId))) {
                return $existing->fresh($this->getShowRelations());
            }

            throw $e;
        }
    }

    /**
     * Pedido ya registrado con la llave en la empresa. Si es de otra sucursal
     * no se devuelve ni se crea otro: 422 client_request_conflict.
     */
    private function orderForRequest(string $requestId): ?SalesOrder
    {
        $order = SalesOrder::where('company_id', CurrentCompany::id())
            ->where('client_request_id', $requestId)
            ->first();

        if ($order && (int) $order->location_id !== (int) CurrentLocation::id()) {
            throw ClientRequest::conflict();
        }

        return $order;
    }

    private function persistOrder($callback, array $data, string $method, ?string $requestId): Model
    {
        return DB::transaction(function () use ($callback, $data, $method, $requestId): Model {
            $company = CurrentCompany::get();
            $location = CurrentLocation::get();
            $user = Auth::user();

            if (!$company || !$location || !$user) {
                throw new \RuntimeException('Contexto de empresa, sucursal o usuario no disponible');
            }

            // Los renglones solo se reemplazan si vienen: editar las notas de un
            // pedido (sin details) borraba todos sus renglones y dejaba el total
            // en 0.
            $hasDetails = array_key_exists('details', $data);
            $payload = $data;
            $details = $payload['details'] ?? [];
            unset($payload['details']);

            if ($method === 'create') {
                $payload['company_id'] = $company->id;
                $payload['location_id'] = $location->id;
                $payload['created_by'] = $user->id;
                $payload['status'] = SalesOrderStatus::PENDING;
                $payload['client_request_id'] = $requestId;
            } else {
                // Al editar no se tocan empresa, sucursal ni quién lo creó (antes
                // se pasaba el pedido a la sucursal de quien editaba y se borraba
                // su creador).
                unset($payload['company_id'], $payload['location_id'], $payload['created_by']);
            }
            $payload['updated_by'] = $user->id;
            if (isset($payload['channel'])) {
                $payload['channel'] = SalesOrderChannel::from($payload['channel']);
            }
            if (isset($payload['service_mode'])) {
                $payload['service_mode'] = SalesOrderServiceMode::from($payload['service_mode']);
            }

            if ($hasDetails) {
                // Precio de catálogo salvo permiso sales_discount, como en el
                // punto de venta (SalePricing): antes el pedido (y el kiosco)
                // cobraba el precio que mandara la app.
                $details = SalePricing::priceLines($details, CurrentWorker::hasPermission('sales_discount'), $company->id);

                app()->make('App\\Services\\SalesOrderStockService')->validateDraftDetails($location->id, $details);
            }

            /** @var SalesOrder $order */
            $order = $callback($payload);

            if (!$hasDetails) {
                return $order->fresh($this->getShowRelations());
            }

            if ($method === 'update') {
                $order->details()->delete();
            }

            // En centavos enteros, como el punto de venta y la app: el renglón
            // se redondea y el total es la suma exacta de los renglones (antes
            // se sumaban flotantes sin redondear y el total podía no cuadrar
            // con sus renglones por un centavo).
            $subtotalCents = 0;
            foreach ($details as $detail) {
                $lineCents = Money::line($detail['requested_quantity'], $detail['unit_price'])['subtotal'];
                $subtotalCents += $lineCents;

                SalesOrderDetail::create([
                    'sales_order_id' => $order->id,
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
            $order->forceFill([
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'tax_amount' => $order->tax_amount ?? 0,
                'discount_amount' => $order->discount_amount ?? 0,
            ])->save();

            return $order->fresh($this->getShowRelations());
        });
    }

    protected function processStoreData(array $validatedData, Request $request): array
    {
        return $this->normalizeGuestCustomerSnapshot($validatedData);
    }

    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        return $this->normalizeGuestCustomerSnapshot($validatedData);
    }

    private function normalizeGuestCustomerSnapshot(array $validatedData): array
    {
        if (!empty($validatedData['customer_id'])) {
            $customer = Customer::find($validatedData['customer_id']);
            if ($customer) {
                $validatedData['customer_name_snapshot'] = $validatedData['customer_name_snapshot'] ?? $customer->name;
                $validatedData['customer_phone_snapshot'] = $validatedData['customer_phone_snapshot'] ?? $customer->phone;
                $validatedData['customer_email_snapshot'] = $validatedData['customer_email_snapshot'] ?? $customer->email;
            }
        }

        return $validatedData;
    }

    public function getInitialData(Request $request)
    {
        $location = CurrentLocation::get();
        $companyId = CurrentCompany::id();

        // Productos a la venta y también los que no se venden sueltos pero
        // tienen paquetes con precio: de esos solo se vende el paquete
        // (for_sale = false le dice a la app que no ofrezca la pieza). Antes
        // esos paquetes no aparecían en el kiosco. Solo de la empresa del
        // contexto (antes no se filtraba por empresa).
        $products = Product::with([
            'category',
            'unit',
            'mainImage',
            'activePackages',
            'locations',
        ])
            ->where('company_id', $companyId)
            ->where(fn($query) => $query->where('for_sale', true)->orWhereHas('activePackages'))
            ->when($location, function ($query) use ($location) {
                $query->whereHas('locations', function ($locationQuery) use ($location) {
                    $locationQuery->where('locations.id', $location->id);
                });
            })
            ->get()
            ->filter(fn(Product $product) => SalePricing::onlySellablePackages($product))
            ->values();

        // Clientes de la empresa del contexto (antes salían los de todas).
        $customers = Customer::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'channels' => array_map(
                    fn (SalesOrderChannel $channel) => ['value' => $channel->value, 'label' => $channel->label()],
                    SalesOrderChannel::cases(),
                ),
                'service_modes' => array_map(
                    fn (SalesOrderServiceMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                    SalesOrderServiceMode::cases(),
                ),
                'statuses' => array_map(
                    fn (SalesOrderStatus $status) => [
                        'value' => $status->value,
                        'label' => $status->label(),
                        'color' => $status->color(),
                    ],
                    SalesOrderStatus::cases(),
                ),
                'customers' => CustomerResource::collection($customers),
                'products' => ProductResource::collection($products),
                'location' => $location,
            ],
        ]);
    }

    /**
     * DELETE sales-orders/{id}: solo pedidos pendientes o cancelados y sin
     * venta.
     *
     * Antes se borraba cualquier pedido: uno en preparación o en camino dejaba
     * su mercancía apartada para siempre (reserved_stock nunca se liberaba) y
     * uno cobrado dejaba su venta apuntando a un pedido inexistente. Se decide
     * con la fila bloqueada: un "preparar" simultáneo no puede apartar stock
     * de un pedido que se está borrando.
     */
    public function destroy($id)
    {
        if (!$this->canDestroy()) {
            return $this->forbiddenResponse('eliminar este pedido');
        }

        try {
            return DB::transaction(function () use ($id) {
                /** @var SalesOrder|null $order */
                $order = $this->scopedQuery()->with('details')->lockForUpdate()->find($id);
                if (!$order) {
                    return response()->json(['message' => 'Pedido no encontrado'], 404);
                }

                $reason = match (true) {
                    (bool) $order->sale_id => 'El pedido ya se cobró: no se puede eliminar porque su venta lo referencia.',
                    !in_array($order->status, [SalesOrderStatus::PENDING, SalesOrderStatus::CANCELLED], true),
                    (float) $order->details->sum('reserved_quantity_base') > 0.000001
                        => 'El pedido tiene mercancía apartada: cancélalo primero para liberarla y después elimínalo.',
                    default => null,
                };
                if ($reason) {
                    throw new BusinessRuleException($reason, 'order_not_deletable');
                }

                $order->delete();

                return response()->json(['message' => 'Pedido eliminado']);
            });
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, 'Error al eliminar el pedido', $this->isInternalError($exception) ? 500 : 422);
        }
    }

    public function prepare(Request $request, int $id)
    {
        return $this->transition($id, SalesOrderStatus::PREPARING, 'Pedido marcado como preparando');
    }

    public function ship(Request $request, int $id)
    {
        return $this->transition($id, SalesOrderStatus::IN_TRANSIT, 'Pedido marcado como en tránsito');
    }

    public function deliver(Request $request, int $id)
    {
        return $this->transition($id, SalesOrderStatus::DELIVERED, 'Pedido marcado como entregado');
    }

    public function cancel(Request $request, int $id)
    {
        return $this->transition($id, SalesOrderStatus::CANCELLED, 'Pedido cancelado');
    }

    public function checkout(Request $request, int $id)
    {
        if (!$this->canEdit() || !CurrentWorker::hasPermission('sales_orders_update')) {
            return $this->forbiddenResponse('cobrar este pedido');
        }

        $validated = $request->validate([
            'payment_method' => 'sometimes|in:cash,card,transfer,credit',
            'paid_amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string',
            // Llave del cobro: el reintento con la misma no cobra dos veces.
            'client_request_id' => ClientRequest::rules(),
        ]);

        // Aislado por empresa/sucursal.
        if (!$this->scopedQuery()->whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
        }

        try {
            // Reintento de un cobro que ya entró: la misma respuesta, sin cobrar.
            $requestId = $validated['client_request_id'] ?? null;
            if ($requestId && ($previous = $this->saleForRequest($requestId))) {
                if ((int) SalesOrder::whereKey($id)->value('sale_id') !== (int) $previous->id) {
                    throw ClientRequest::conflict();
                }

                return $this->checkoutResponse(SalesOrder::findOrFail($id), $previous);
            }

            $order = SalesOrder::with($this->getShowRelations())->findOrFail($id);
            $stockService = app()->make('App\\Services\\SalesOrderStockService');
            $sale = $stockService->checkoutOrder($order, $validated);

            return $this->checkoutResponse($order, $sale);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->validationFailure($exception, 'No se pudo cobrar el pedido');
        } catch (\Throwable $exception) {
            // Reglas de negocio (stock insuficiente, turno, crédito con pago):
            // su propio 422 {message, code}. Antes cualquier excepción salía con
            // su texto, SQL incluido; un error interno ahora se registra y se
            // responde un mensaje genérico.
            return $this->errorResponse($exception, 'Error al cobrar el pedido', $this->isInternalError($exception) ? 500 : 422);
        }
    }

    /**
     * POST sales-orders/quick-checkout — cobro rápido del kiosco: crea el
     * pedido y lo cobra en UNA transacción.
     *
     * Antes el kiosco hacía dos llamadas (alta y cobro): si el cobro fallaba o
     * no llegaba la respuesta, el pedido quedaba pendiente y el reintento
     * creaba otro. Mismas reglas que el alta (precio de catálogo salvo
     * sales_discount, stock disponible) y que el cobro (turno para el
     * efectivo, crédito sin pago); si algo falla no queda nada.
     *
     * client_request_id es obligatorio: un reintento con la misma llave
     * responde 200 con el pedido ya cobrado, sin cobrar otra vez. El alta
     * responde 201 con la misma forma que el cobro de un pedido:
     * {success, message, data: pedido (con sale), sale: {id, sale_number, total}}.
     */
    public function quickCheckout(Request $request)
    {
        if (!$this->canStore() || !$this->canEdit()) {
            return $this->forbiddenResponse('cobrar pedidos');
        }

        $validated = $request->validate(array_merge($this->orderRules(), [
            'payment_method' => 'sometimes|in:cash,card,transfer,credit',
            'paid_amount' => 'sometimes|numeric|min:0',
            'client_request_id' => ClientRequest::rules(true),
        ]));
        $requestId = $validated['client_request_id'];

        try {
            if ($previous = $this->orderForRequest($requestId)) {
                return $this->quickCheckoutReplay($previous);
            }

            $payment = array_intersect_key($validated, array_flip(['payment_method', 'paid_amount']))
                + ['client_request_id' => $requestId];
            $orderData = $this->processStoreData(
                array_diff_key($validated, array_flip(['payment_method', 'paid_amount'])),
                $request,
            );

            $this->inQuickCheckout = true;
            [$order, $sale] = DB::transaction(function () use ($orderData, $payment): array {
                $order = $this->process(fn($data) => $this->create($data), $orderData, 'create');
                $sale = app()->make('App\\Services\\SalesOrderStockService')->checkoutOrder($order, $payment);

                return [$order, $sale];
            });

            return $this->checkoutResponse($order, $sale, 201);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->validationFailure($exception, 'No se pudo cobrar el pedido');
        } catch (\Throwable $exception) {
            // Otro envío con la misma llave se registró primero: esta
            // transacción se revirtió completa y se responde con aquel pedido.
            if (ClientRequest::isDuplicate($exception)) {
                try {
                    if ($previous = $this->orderForRequest($requestId)) {
                        return $this->quickCheckoutReplay($previous);
                    }
                } catch (\Throwable $replayException) {
                    $exception = $replayException;
                }
            }

            return $this->errorResponse($exception, 'Error al cobrar el pedido', $this->isInternalError($exception) ? 500 : 422);
        } finally {
            $this->inQuickCheckout = false;
        }
    }

    /**
     * Reintento del cobro rápido: el pedido con esa llave ya se cobró en el
     * mismo envío; se responde igual (200). Un pedido con la llave pero sin
     * venta vino de otro lado (un alta simple): no se cobra aquí.
     */
    private function quickCheckoutReplay(SalesOrder $order)
    {
        $sale = $order->sale_id ? Sale::find($order->sale_id) : null;
        if (!$sale) {
            throw ClientRequest::conflict();
        }

        return $this->checkoutResponse($order, $sale);
    }

    /**
     * Venta registrada con la llave en la empresa (el cobro de un pedido o el
     * cobro rápido del kiosco guardan la llave en la venta).
     */
    private function saleForRequest(string $requestId): ?Sale
    {
        return Sale::where('company_id', CurrentCompany::id())
            ->where('client_request_id', $requestId)
            ->first();
    }

    /**
     * Respuesta del cobro: el pedido (ya entregado, con su venta) y el resumen
     * de la venta. La misma en el cobro, su reintento y el cobro rápido.
     */
    private function checkoutResponse(SalesOrder $order, Sale $sale, int $status = 200)
    {
        $order->refresh();
        $order->load($this->getShowRelations());

        return response()->json([
            'success' => true,
            'message' => 'Pedido cobrado y convertido a venta',
            'data' => new $this->resource($order, ['editing' => true]),
            'sale' => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total' => $sale->total,
            ],
        ], $status);
    }

    /**
     * 422 de una validación de negocio con el primer motivo en `message` (la
     * app muestra message) y el detalle por campo en `errors`.
     */
    private function validationFailure(\Illuminate\Validation\ValidationException $exception, string $fallback)
    {
        $message = collect($exception->errors())->flatten()->first() ?? $fallback;
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $exception->errors(),
        ];

        // No alcanza el stock de un renglón: el mismo code que el resto de los
        // cobros (C3), para que la app recargue las existencias.
        if (str_starts_with((string) $message, SalesOrderStockService::INSUFFICIENT_STOCK_PREFIX)) {
            $payload['code'] = 'insufficient_stock';
        }

        return response()->json($payload, 422);
    }

    private function transition(int $id, SalesOrderStatus $targetStatus, string $message)
    {
        if (!$this->canEdit() || !CurrentWorker::hasPermission('sales_orders_update')) {
            return $this->forbiddenResponse('actualizar este pedido');
        }

        // Aislado por empresa/sucursal: no se transiciona un pedido ajeno.
        if (!$this->scopedQuery()->whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
        }

        try {
            $stockService = app()->make('App\\Services\\SalesOrderStockService');

            DB::transaction(function () use ($id, $targetStatus, $stockService): void {
                // Bloqueo de fila + relectura del estado: un cancel compitiendo
                // con un checkout ya no puede pisar "entregado" con "cancelado".
                $order = SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();

                // Entregar exige que el pedido ya tenga una venta asociada
                // (se cobró con checkout); si no, no debe pasar a entregado.
                if ($targetStatus === SalesOrderStatus::DELIVERED && !$order->sale_id) {
                    throw new BusinessRuleException('El pedido debe cobrarse antes de marcarse como entregado.', 'order_not_paid');
                }

                if ($targetStatus === SalesOrderStatus::PREPARING) {
                    $stockService->reserveOrder($order);
                }

                $order->transitionTo($targetStatus);

                if ($targetStatus === SalesOrderStatus::CANCELLED) {
                    $stockService->releaseOrder($order);
                }
            });

            $order = SalesOrder::with($this->getShowRelations())->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => new $this->resource($order, ['editing' => true]),
            ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // Stock insuficiente al apartar, pedido ya apartado…: el motivo va
            // en `message`, que es lo que muestra la app (antes iba escondido).
            return $this->validationFailure($exception, 'Error al cambiar el estado del pedido');
        } catch (\Throwable $exception) {
            // Reglas de negocio con su 422 {message, code}; un error interno se
            // registra y responde un mensaje genérico, sin su texto.
            return $this->errorResponse($exception, 'Error al cambiar el estado del pedido', $this->isInternalError($exception) ? 500 : 422);
        }
    }
}