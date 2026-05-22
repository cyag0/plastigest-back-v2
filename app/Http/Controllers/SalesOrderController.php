<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends CrudController
{
    protected string $resource = SalesOrderResource::class;

    protected string $model = SalesOrder::class;

    protected string $dateColumn = 'order_date';

    protected ?string $permissionPrefix = 'sales_orders';

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
        return $request->validate([
            'channel' => 'required|in:kiosk,phone,admin',
            'service_mode' => 'required|in:counter,delivery',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name_snapshot' => 'nullable|string|max:255',
            'customer_phone_snapshot' => 'nullable|string|max:25',
            'customer_email_snapshot' => 'nullable|email|max:255',
            'promised_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.package_id' => 'nullable|exists:product_packages,id',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.requested_quantity' => 'required|numeric|min:0.001',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($model instanceof SalesOrder && $model->status !== SalesOrderStatus::PENDING) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Solo se pueden editar pedidos en estado pendiente.'],
            ]);
        }

        return $request->validate([
            'channel' => 'sometimes|required|in:kiosk,phone,admin',
            'service_mode' => 'sometimes|required|in:counter,delivery',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name_snapshot' => 'nullable|string|max:255',
            'customer_phone_snapshot' => 'nullable|string|max:25',
            'customer_email_snapshot' => 'nullable|email|max:255',
            'promised_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
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

            $payload = $data;
            $details = $payload['details'] ?? [];
            unset($payload['details']);

            $payload['company_id'] = $company->id;
            $payload['location_id'] = $location->id;
            $payload['created_by'] = $method === 'create' ? $user->id : ($payload['created_by'] ?? null);
            $payload['updated_by'] = $user->id;
            $payload['channel'] = SalesOrderChannel::from($payload['channel']);
            $payload['service_mode'] = SalesOrderServiceMode::from($payload['service_mode']);

            app()->make('App\\Services\\SalesOrderStockService')->validateDraftDetails($location->id, $details);

            if ($method === 'create') {
                $payload['status'] = SalesOrderStatus::PENDING;
            }

            /** @var SalesOrder $order */
            $order = $callback($payload);

            if ($method === 'update') {
                $order->details()->delete();
            }

            $subtotal = 0;
            foreach ($details as $detail) {
                $lineSubtotal = (float) $detail['requested_quantity'] * (float) $detail['unit_price'];
                $subtotal += $lineSubtotal;

                SalesOrderDetail::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $detail['product_id'],
                    'package_id' => $detail['package_id'] ?? null,
                    'unit_id' => $detail['unit_id'],
                    'requested_quantity' => $detail['requested_quantity'],
                    'prepared_quantity' => 0,
                    'delivered_quantity' => 0,
                    'reserved_quantity_base' => 0,
                    'delivered_quantity_base' => 0,
                    'unit_price' => $detail['unit_price'],
                    'line_subtotal' => $lineSubtotal,
                    'line_total' => $lineSubtotal,
                ]);
            }

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

        $products = Product::with([
            'category',
            'unit',
            'mainImage',
            'activePackages',
            'locations',
        ])
            ->where('for_sale', true)
            ->when($location, function ($query) use ($location) {
                $query->whereHas('locations', function ($locationQuery) use ($location) {
                    $locationQuery->where('locations.id', $location->id);
                });
            })
            ->get();

        $customers = Customer::where('is_active', true)
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

    private function transition(int $id, SalesOrderStatus $targetStatus, string $message)
    {
        if (!$this->canEdit() || !CurrentWorker::hasPermission('sales_orders_update')) {
            return $this->forbiddenResponse('actualizar este pedido');
        }

        try {
            $order = SalesOrder::with($this->getShowRelations())->findOrFail($id);
            $stockService = app()->make('App\\Services\\SalesOrderStockService');

            DB::transaction(function () use ($order, $targetStatus, $stockService): void {
                if ($targetStatus === SalesOrderStatus::PREPARING) {
                    $stockService->reserveOrder($order);
                }

                $order->transitionTo($targetStatus);

                if ($targetStatus === SalesOrderStatus::CANCELLED) {
                    $stockService->releaseOrder($order);
                }
            });

            $order->refresh();
            $order->load($this->getShowRelations());

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => new $this->resource($order, ['editing' => true]),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado del pedido',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }
}