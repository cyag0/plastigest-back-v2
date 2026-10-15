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
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Support\CurrentWorker;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\Money;
use App\Support\SalePricing;
use Illuminate\Database\Eloquent\Model;
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
        return DB::transaction(function () use ($callback, $data, $method): Model {
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
        ]);

        // Aislado por empresa/sucursal.
        if (!$this->scopedQuery()->whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
        }

        try {
            $order = SalesOrder::with($this->getShowRelations())->findOrFail($id);
            $stockService = app()->make('App\\Services\\SalesOrderStockService');
            $sale = $stockService->checkoutOrder($order, $validated);

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
            ]);
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
     * 422 de una validación de negocio con el primer motivo en `message` (la
     * app muestra message) y el detalle por campo en `errors`.
     */
    private function validationFailure(\Illuminate\Validation\ValidationException $exception, string $fallback)
    {
        return response()->json([
            'success' => false,
            'message' => collect($exception->errors())->flatten()->first() ?? $fallback,
            'errors' => $exception->errors(),
        ], 422);
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