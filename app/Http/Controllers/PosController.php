<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Services\CashRegisterSessionService;
use App\Services\KioskOrderService;
use App\Services\SaleService;
use App\Support\ClientRequest;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Support\Money;
use App\Support\SalePricing;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Punto de venta para cajeros.
 *
 * El catálogo se descarga una vez y se busca en el dispositivo (búsqueda
 * instantánea, el lector de códigos no espera a la red). Cada venta queda
 * ligada a la caja y al turno abierto del cajero.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly CashRegisterSessionService $sessions,
        private readonly SaleService $sales,
        private readonly KioskOrderService $kioskOrders,
    ) {}

    /**
     * GET pos/catalog — productos vendibles de la sucursal y sus paquetes.
     *
     * Entran los productos a la venta y también los paquetes con precio de un
     * producto que no se vende suelto (for_sale = false): de esas piezas base
     * solo sale el paquete, no la pieza. Antes se filtraba solo por for_sale y
     * paquetes como "PAQ GALLETAS 16 PZA" no se podían vender en el punto de
     * venta.
     */
    public function catalog(): JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $locationId = CurrentLocation::id();

        $products = Product::query()
            ->where('products.company_id', CurrentCompany::id())
            ->where(fn($q) => $q->where('products.for_sale', true)->orWhereHas('activePackages'))
            ->join('product_location as pl', fn($j) => $j->on('pl.product_id', '=', 'products.id')
                ->where('pl.location_id', $locationId)
                ->where('pl.active', true))
            ->with(['unit:id,name,abbreviation,unit_type', 'activePackages'])
            ->orderBy('products.name')
            ->get(['products.id', 'products.name', 'products.code', 'products.sale_price', 'products.unit_id', 'products.for_sale', 'pl.current_stock', 'pl.reserved_stock']);

        $items = [];
        foreach ($products as $product) {
            // Disponible para vender: lo apartado para pedidos en preparación
            // no se vende (Sale::validateAndUpdateStock lo rechaza).
            $stock = max(0.0, (float) $product->current_stock - (float) $product->reserved_stock);
            $unit = $product->unit ? ['id' => $product->unit->id, 'abbreviation' => $product->unit->abbreviation, 'type' => $product->unit->unit_type] : null;

            if ($product->for_sale) {
                $items[] = [
                    'key' => "p{$product->id}",
                    'product_id' => $product->id,
                    'package_id' => null,
                    'name' => $product->name,
                    'code' => $product->code,
                    'price' => SalePricing::catalogPrice($product),
                    'unit' => $unit,
                    'stock' => $stock,
                ];
            }

            foreach ($product->activePackages as $package) {
                if (!SalePricing::isSellable($product, $package)) {
                    continue;
                }

                $perPackage = (float) $package->quantity_per_package;
                $items[] = [
                    'key' => "k{$package->id}",
                    'product_id' => $product->id,
                    'package_id' => $package->id,
                    'name' => "{$product->name} ({$package->package_name})",
                    'code' => $package->barcode,
                    'price' => SalePricing::catalogPrice($product, $package),
                    'unit' => $unit,
                    // Paquetes completos que alcanzan con la existencia actual.
                    'stock' => $perPackage > 0 ? floor($stock / $perPackage) : 0,
                    'per_package' => $perPackage,
                ];
            }
        }

        return response()->json(['data' => $items]);
    }

    /**
     * POST pos/sales — cobra una venta en el turno abierto del cajero.
     *
     * Con client_request_id (la llave del intento de cobro) es idempotente: si
     * ya hay una venta con esa llave responde 200 con la misma forma que el
     * alta (201) y no cobra otra vez, aunque el turno ya se haya cerrado.
     *
     * Con kiosk_order_id cobra un pedido del kiosko: la venta lleva los
     * renglones del carrito (pueden diferir del pedido) y, en la misma
     * transacción, se libera lo apartado y el pedido queda entregado con su
     * venta (KioskOrderService::chargeAtPos). Un pedido que ya no está
     * pendiente, de otra sucursal o que no existe: 422 kiosk_order_unavailable.
     */
    public function store(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $requestId = $request->validate(['client_request_id' => ClientRequest::rules()])['client_request_id'] ?? null;
        if ($requestId && ($existing = $this->saleForRequest($requestId))) {
            return $this->saleResponse($existing, 200);
        }

        $session = $this->sessions->current(Auth::user());
        if (!$session) {
            return response()->json(['message' => 'Abre un turno de caja para poder cobrar.', 'code' => 'no_open_session'], 422);
        }
        if ($session->location_id !== CurrentLocation::id()) {
            return response()->json(['message' => 'Tu turno abierto es de otra sucursal.', 'code' => 'session_other_location'], 422);
        }

        $companyId = CurrentCompany::id();
        $validated = $request->validate([
            'details' => 'required|array|min:1',
            'details.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'details.*.package_id' => 'nullable|integer|exists:product_packages,id',
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_price' => 'nullable|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,card,transfer',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.tendered' => 'nullable|numeric|min:0',
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'notes' => 'nullable|string|max:500',
            // Pedido del kiosko que se cobra con esta venta (se valida al cobrar).
            'kiosk_order_id' => 'nullable|integer|min:1',
        ], [
            'details.required' => 'Agrega al menos un producto.',
            'payments.required' => 'Indica cómo se paga.',
        ]);

        $details = $this->priceDetails($validated['details']);

        // En centavos enteros, igual que la app (utils/money.ts): cada renglón
        // se redondea mitad hacia arriba y luego se suman. Con flotantes un
        // producto pesado (2.489 kg × $25) salía distinto aquí y en la app, y
        // la venta se rechazaba por un centavo.
        $total = Money::fromCents(array_sum(array_map(
            fn($d) => Money::line($d['quantity'], $d['unit_price'], $d['discount'])['total'],
            $details
        )));

        $paid = Money::fromCents(array_sum(array_map(
            fn($p) => Money::toCents($p['amount']),
            $validated['payments']
        )));
        if (abs($paid - $total) > 0.009) {
            throw ValidationException::withMessages([
                'payments' => [sprintf('Los pagos suman $%s y el total es $%s.', number_format($paid, 2), number_format($total, 2))],
            ]);
        }
        foreach ($validated['payments'] as $i => $payment) {
            if ($payment['method'] === 'cash' && isset($payment['tendered']) && $payment['tendered'] < $payment['amount']) {
                throw ValidationException::withMessages(["payments.{$i}.tendered" => ['El efectivo recibido no alcanza.']]);
            }
        }

        $saleData = [
            'company_id' => $companyId,
            'location_id' => $session->location_id,
            'user_id' => Auth::id(),
            'customer_id' => $validated['customer_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'client_request_id' => $requestId,
            'details' => $details,
            'payments' => $validated['payments'],
        ];

        try {
            if (!empty($validated['kiosk_order_id'])) {
                [$sale, $created] = $this->kioskOrders->chargeAtPos(
                    (int) $validated['kiosk_order_id'],
                    $session,
                    $requestId,
                    fn(array $kioskData) => $this->sales->create(array_merge($saleData, $kioskData), $session),
                );

                // Reintento de un cobro que ya ligó el pedido: esa venta.
                if (!$created) {
                    return $this->saleResponse($sale, 200);
                }
            } else {
                $sale = $this->sales->create($saleData, $session);
            }
        } catch (QueryException $e) {
            // Otra petición con la misma llave se registró primero (doble envío
            // simultáneo): esta se revirtió completa y se responde con aquella.
            if ($requestId && ClientRequest::isDuplicate($e) && ($existing = $this->saleForRequest($requestId))) {
                return $this->saleResponse($existing, 200);
            }

            throw $e;
        }

        return $this->saleResponse($sale, 201);
    }

    /**
     * GET pos/sales/request/{clientRequestId} — la venta registrada con esa
     * llave, con la misma forma que el alta. La app la consulta cuando un
     * cobro se quedó sin respuesta, antes de dejar cobrar otra vez.
     */
    public function findByRequest(string $clientRequestId): JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $sale = Sale::query()
            ->where('company_id', CurrentCompany::id())
            ->where('location_id', CurrentLocation::id())
            ->where('client_request_id', $clientRequestId)
            ->first();

        if (!$sale) {
            return response()->json(['message' => 'No hay una venta registrada con esa llave de cobro.'], 404);
        }

        return $this->saleResponse($sale, 200);
    }

    /**
     * Venta ya registrada con la llave en la empresa. Si es de otra sucursal
     * no se devuelve (ni se cobra de nuevo): 422 client_request_conflict.
     */
    private function saleForRequest(string $requestId): ?Sale
    {
        $sale = Sale::query()
            ->where('company_id', CurrentCompany::id())
            ->where('client_request_id', $requestId)
            ->first();

        if ($sale && (int) $sale->location_id !== (int) CurrentLocation::id()) {
            throw ClientRequest::conflict();
        }

        return $sale;
    }

    /**
     * Respuesta del alta: la misma en el cobro, el reintento y la consulta por
     * llave. Si la venta cobró un pedido del kiosko lleva kiosk_order {id,
     * ticket_number}.
     */
    private function saleResponse(Sale $sale, int $status): JsonResponse
    {
        $sale->loadMissing('payments');

        $data = [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'total' => (float) $sale->total,
            'discount' => (float) $sale->discount,
            'payment_method' => $sale->payment_method,
            'payments' => $sale->payments->map(fn($p) => [
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'tendered' => $p->tendered !== null ? (float) $p->tendered : null,
                'change' => $p->change !== null ? (float) $p->change : null,
            ])->values(),
            'change' => (float) $sale->payments->sum('change'),
        ];

        $content = is_array($sale->content) ? $sale->content : [];
        if (($content['channel'] ?? null) === 'kiosk' && !empty($content['sales_order_id'])) {
            $data['kiosk_order'] = [
                'id' => (int) $content['sales_order_id'],
                'ticket_number' => isset($content['ticket_number']) ? (int) $content['ticket_number'] : null,
            ];
        }

        return response()->json([
            'message' => 'Venta registrada',
            'data' => $data,
        ], $status);
    }

    /**
     * Precio de cada renglón (SalePricing): sin el permiso sales_discount se
     * cobra el precio del catálogo y no se aceptan descuentos; el precio nunca
     * se toma de la app para un cajero sin permiso.
     */
    private function priceDetails(array $details): array
    {
        $priced = SalePricing::priceLines($details, CurrentWorker::hasPermission('sales_discount'));

        return array_map(fn($d) => [
            'product_id' => $d['product_id'],
            'package_id' => $d['package_id'],
            // Los paquetes se venden en la unidad del producto; MovementService
            // multiplica por quantity_per_package al descontar stock.
            'unit_id' => $d['unit_id'],
            'quantity' => Money::roundQuantity($d['quantity']),
            'unit_price' => $d['unit_price'],
            'discount' => $d['discount'],
        ], $priced);
    }
}
