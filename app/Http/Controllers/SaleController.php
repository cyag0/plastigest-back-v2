<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Product;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Services\CashMovementService;
use App\Services\CashRegisterSessionService;
use App\Support\ClientRequest;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Support\Money;
use App\Support\SalePricing;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use App\Models\Admin\Location;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Barryvdh\DomPDF\Facade\Pdf;

class SaleController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = SaleResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Sale::class;
    protected ?string $permissionPrefix = 'sales';
    protected ?string $locationColumn = 'location_id';

    /**
     * Columna de fecha principal: los filtros date_from/date_to se aplican sobre sale_date.
     */
    protected string $dateColumn = 'sale_date';

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'details.product',
            'location',
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'details.product.mainImage',
            'details.unit',
            'details.package',
            'location',
            'user',
            'cashRegister',
            'payments',
        ];
    }

    /**
     * Sobrescribir el método de filtros básicos para adaptar a la tabla movements
     */
    protected function applyBasicFilters($query, array $params)
    {
        // Filtro por búsqueda general - buscar en campos relevantes de movements
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.customer_name')) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.customer_phone')) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.customer_email')) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.document_number')) LIKE ?", ["%{$search}%"]);
            });
        }

        // Filtro por company_id si existe
        if (isset($params['company_id']) && !empty($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Por fecha de la venta ($dateColumn = sale_date), no de captura: una
        // venta registrada después con fecha anterior caía en el día de captura.
        // Acepta date_from/date_to o start_date/end_date, como CrudController.
        $dateFrom = $params['date_from'] ?? $params['start_date'] ?? null;
        $dateTo = $params['date_to'] ?? $params['end_date'] ?? null;

        // sale_date es DATE: se compara directo para que use el índice
        // (whereDate() lo envolvía en DATE() y recorría toda la sucursal).
        if (!empty($dateFrom)) {
            $query->where($this->dateColumn, '>=', self::dateOnly($dateFrom));
        }

        if (!empty($dateTo)) {
            $query->where($this->dateColumn, '<=', self::dateOnly($dateTo));
        }
    }

    /**
     * La parte de fecha (Y-m-d) de un filtro: "2026-10-15" o
     * "2026-10-15T08:00:00". Para comparar directo columnas DATE.
     */
    private static function dateOnly(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : $value;
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar por estado. Además de closed/cancelled acepta el estado del
        // pago (paid, partial, pending), como lo muestra la lista: una venta
        // cancelada dice «Cancelada» y las demás cómo va su pago, así que
        // «Pagadas» no incluye las canceladas aunque se hubieran cobrado.
        if (isset($params['status']) && !empty($params['status'])) {
            if (in_array($params['status'], ['paid', 'partial', 'pending'], true)) {
                $query->where('status', '!=', SaleStatus::CANCELLED->value)
                    ->where('payment_status', $params['status']);
            } else {
                $query->where('status', $params['status']);
            }
        }

        // Filtrar por ubicación
        if (isset($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }

        // Filtrar por método de pago
        if (isset($params['payment_method']) && !empty($params['payment_method'])) {
            $query->where('payment_method', $params['payment_method']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            // Información del cliente (opcional)
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:255',

            // Método de pago
            'payment_method' => 'required|in:cash,card,transfer,credit',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            // Llave del intento de cobro: el reintento con la misma no duplica la venta.
            'client_request_id' => ClientRequest::rules(),

            // Detalles de la venta (productos de la empresa del contexto)
            'details' => 'required|array|min:1',
            'details.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', CurrentCompany::id())],
            'details.*.package_id' => 'nullable|exists:product_packages,id',
            'details.*.unit_id' => 'required|exists:units,id',
            // Milésimas, como el punto de venta y los pedidos: la app manda el
            // renglón en la unidad del producto (5 g de un producto por kilo
            // son 0.005 kg).
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Las ventas no se editan (ver update()): no hay datos de edición que validar.
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return [];
    }

    /**
     * PUT/PATCH sales/{id}: las ventas no se editan.
     *
     * Antes reescribía la venta desde la petición (renglones, precios, total,
     * cobrado, fecha, cajero y sucursal) sin tocar el stock, los pagos ni la
     * caja, aun cerrada o cancelada: editar 10 piezas a 100 y cancelar
     * devolvía al inventario 90 piezas que nunca existieron. Ninguna pantalla
     * la usa; una corrección es cancelar la venta y registrar otra.
     */
    public function update(Request $request, $id)
    {
        return response()->json([
            'message' => 'Las ventas no se editan. Para corregir una venta, cancélala y registra una nueva.',
            'code' => 'sale_update_not_allowed',
        ], 405, ['Allow' => 'GET, HEAD, DELETE']);
    }

    /**
     * Alta de la venta (las ventas no se editan: ver update()).
     *
     * Con client_request_id es idempotente: si ya hay una venta con esa llave
     * se devuelve esa (200, misma forma que el alta) y no se cobra otra vez.
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        if ($method !== 'create') {
            throw new \LogicException('Las ventas no se editan.');
        }

        $requestId = $data['client_request_id'] ?? null;
        if ($requestId && ($existing = $this->saleForRequest($requestId))) {
            return $existing->load($this->getShowRelations());
        }

        // El alta vive en SaleService (la comparte el punto de venta). Si quien
        // vende tiene turno de caja abierto, la venta entra en ese turno.
        $session = app(\App\Services\CashRegisterSessionService::class)->current(Auth::user());

        // Solo se adjunta el turno si es de la sucursal actual: si no, la
        // venta quedaría apuntando al turno de otra sucursal e inflaría su
        // total de ventas.
        if ($session && $session->location_id !== CurrentLocation::id()) {
            $session = null;
        }

        // Sin permiso sales_discount, el precio sale del catálogo: la
        // pantalla anterior no debe poder cobrar a un precio arbitrario.
        $data['details'] = $this->enforceCatalogPricing($data['details']);

        try {
            return app(\App\Services\SaleService::class)->create([
                'company_id' => CurrentCompany::get()->id,
                'location_id' => CurrentLocation::get()->id,
                'user_id' => Auth::id(),
            ] + $data, $session)->load($this->getShowRelations());
        } catch (QueryException $e) {
            // Otra petición con la misma llave se registró primero: esta se
            // revirtió completa y se responde con aquella.
            if ($requestId && ClientRequest::isDuplicate($e) && ($existing = $this->saleForRequest($requestId))) {
                return $existing->load($this->getShowRelations());
            }

            throw $e;
        }
    }

    /**
     * Venta ya registrada con la llave en la empresa. Si es de otra sucursal
     * no se devuelve (ni se cobra de nuevo): 422 client_request_conflict.
     */
    private function saleForRequest(string $requestId): ?Sale
    {
        $sale = Sale::where('company_id', CurrentCompany::id())
            ->where('client_request_id', $requestId)
            ->first();

        if ($sale && (int) $sale->location_id !== (int) CurrentLocation::id()) {
            throw ClientRequest::conflict();
        }

        return $sale;
    }

    /**
     * Precio de catálogo obligatorio salvo permiso sales_discount, con las
     * mismas reglas que el punto de venta y los pedidos (SalePricing): la
     * pantalla anterior tampoco puede cobrar a un precio arbitrario, vender un
     * paquete con otro producto ni la pieza suelta de un producto que solo se
     * vende en paquete.
     */
    private function enforceCatalogPricing(array $details): array
    {
        return SalePricing::priceLines($details, CurrentWorker::hasPermission('sales_discount'));
    }

    /**
     * Cancelar venta
     */
    public function cancel(Request $request, int $id)
    {
        if (!CurrentWorker::hasPermission('sales_update')) {
            return $this->forbiddenResponse('cancelar ventas');
        }

        try {
            // Aislada por empresa/sucursal: no se cancela una venta de otra
            // empresa (ni de otra sucursal si no eres administrador).
            $sale = $this->scopedQuery()->with($this->getShowRelations())->findOrFail($id);

            if ($sale->transitionTo(SaleStatus::CANCELLED)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Venta cancelada correctamente',
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede cancelar esta venta',
            ], 400);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Venta no encontrada'], 404);
        } catch (BusinessRuleException $e) {
            // Devolver efectivo sin turno abierto en la sucursal: 422 {message, code}.
            return $e->render();
        } catch (\Exception $e) {
            Log::error('Error al cancelar venta', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            // Mensaje de negocio seguro; los detalles internos no se exponen.
            $message = $this->isInternalError($e) ? 'No se pudo cancelar la venta' : $e->getMessage();
            return response()->json(['success' => false, 'message' => $message], 400);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        if ($model->status !== SaleStatus::CANCELLED) {
            return [
                'can_delete' => false,
                'message' => 'Solo se pueden eliminar ventas canceladas'
            ];
        }
        return ['can_delete' => true, 'message' => ''];
    }

    /**
     * Obtener estadísticas y reportes de ventas
     */
    public function salesStats(Request $request)
    {
        if (!CurrentWorker::hasPermission('sales_read')) {
            return $this->forbiddenResponse('ver estadísticas de ventas');
        }

        // Sucursal: la actual por defecto; otra (o todas, "all") solo para
        // quien tiene acceso a todas las sucursales. Antes cualquiera veía las
        // estadísticas de otra sucursal con ?location_id=. Fuera del try: el
        // 403/404 no debe volverse un 500.
        $locationId = CurrentWorker::locationScope($request->input('location_id', CurrentLocation::id()));

        try {
            $companyId = CurrentCompany::id();
            $startDate = $request->filled('start_date') ? self::dateOnly($request->input('start_date')) : null;
            $endDate = $request->filled('end_date') ? self::dateOnly($request->input('end_date')) : null;

            // Sin rango: los últimos 30 días (el mismo divisor que ya usaba el
            // promedio diario). Antes se agregaba todo el historial de la
            // sucursal cada vez que se abría Ventas.
            if (!$startDate && !$endDate) {
                $startDate = now()->subDays(29)->toDateString();
                $endDate = now()->toDateString();
                $defaultRange = true;
            }

            // Query base, aislada por empresa y sucursal.
            $baseQuery = Sale::where('company_id', $companyId)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId));
            $query = clone $baseQuery;

            if ($startDate) {
                $query->where('sale_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('sale_date', '<=', $endDate);
            }

            // Los totales son de ventas vigentes: una venta cancelada no es
            // dinero vendido. Antes se sumaba, y "Ventas hoy" en Inicio incluía
            // las canceladas. El desglose por estado sí usa todas.
            $allQuery = clone $query;
            $query->where('status', '!=', SaleStatus::CANCELLED->value);

            // Estadísticas generales
            $totalSales = (clone $query)->count();
            $totalAmount = (clone $query)->sum('total');
            $averageAmount = $totalSales > 0 ? $totalAmount / $totalSales : 0;

            // Ventas del día (sale_date es DATE: comparación directa, con índice)
            $todaySales = (clone $query)
                ->where('sale_date', now()->toDateString())
                ->count();
            $todayAmount = (clone $query)
                ->where('sale_date', now()->toDateString())
                ->sum('total');

            // Ventas por estado
            $byStatus = (clone $allQuery)
                ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(function ($item) {
                    // Convertir enum a string si es necesario
                    $statusValue = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;

                    $statusLabel = match ($statusValue) {
                        'closed' => 'Cerrada',
                        'cancelled' => 'Cancelada',
                        default => $statusValue
                    };

                    return [$statusValue => [
                        'label' => $statusLabel,
                        'count' => $item->count,
                        'total' => (float) $item->total,
                    ]];
                });

            // Ventas por método de pago
            $byPaymentMethod = (clone $query)
                ->select('payment_method')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('SUM(total) as total')
                ->groupBy('payment_method')
                ->get()
                ->mapWithKeys(function ($item) {
                    $methodLabel = match ($item->payment_method) {
                        'cash' => 'Efectivo',
                        'card' => 'Tarjeta',
                        'transfer' => 'Transferencia',
                        'credit' => 'Crédito',
                        default => $item->payment_method ?? 'Sin especificar'
                    };

                    return [$item->payment_method ?? 'unknown' => [
                        'label' => $methodLabel,
                        'count' => $item->count,
                        'total' => (float) $item->total,
                    ]];
                });

            // Productos más vendidos
            $topProducts = DB::table('sales_details')
                ->join('sales', 'sales_details.sale_id', '=', 'sales.id')
                ->join('products', 'sales_details.product_id', '=', 'products.id')
                ->where('sales.company_id', $companyId)
                ->when($locationId, fn($q) => $q->where('sales.location_id', $locationId))
                // Los productos más vendidos no deben contar ventas canceladas.
                ->where('sales.status', '!=', SaleStatus::CANCELLED->value)
                ->when($startDate, function ($q) use ($startDate) {
                    return $q->where('sales.sale_date', '>=', $startDate);
                })
                ->when($endDate, function ($q) use ($endDate) {
                    return $q->where('sales.sale_date', '<=', $endDate);
                })
                ->select(
                    'products.id',
                    'products.name',
                    DB::raw('SUM(sales_details.quantity) as total_quantity'),
                    DB::raw('SUM(sales_details.total) as total_amount')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_amount')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'product_id' => $item->id,
                        'product_name' => $item->name,
                        'quantity_sold' => (float) $item->total_quantity,
                        'total_amount' => (float) $item->total_amount,
                    ];
                });

            // Tendencia de ventas (últimos 6 meses): no se recorta a los 30 días
            // por defecto, solo a un rango que se haya pedido.
            $sixMonthsAgo = now()->subMonths(6)->startOfMonth()->toDateString();
            $salesTrend = (clone $baseQuery)
                ->where('status', '!=', SaleStatus::CANCELLED->value)
                ->when(empty($defaultRange) && $startDate, fn($q) => $q->where('sale_date', '>=', $startDate))
                ->when(empty($defaultRange) && $endDate, fn($q) => $q->where('sale_date', '<=', $endDate))
                ->where('sale_date', '>=', $sixMonthsAgo)
                ->select(
                    DB::raw('DATE_FORMAT(sale_date, "%Y-%m") as month'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(total) as total')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(function ($item) {
                    return [
                        'month' => $item->month,
                        'count' => $item->count,
                        'total' => (float) $item->total,
                    ];
                });

            // Ventas completadas y canceladas
            $closedCount = (clone $query)
                ->where('status', 'closed')
                ->count();

            $cancelledCount = (clone $allQuery)
                ->where('status', 'cancelled')
                ->count();

            // Venta promedio por día. diffInDays es con signo en Carbon 3: se usa
            // el valor absoluto para no obtener un divisor negativo/incorrecto.
            $daysInRange = $startDate && $endDate
                ? max(1, (int) abs(now()->parse($startDate)->diffInDays(now()->parse($endDate))) + 1)
                : 30; // Rango abierto de un lado: 30 días

            $averagePerDay = $totalAmount / $daysInRange;

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_sales' => $totalSales,
                        'total_amount' => (float) $totalAmount,
                        'average_amount' => (float) $averageAmount,
                        'closed_count' => $closedCount,
                        'cancelled_count' => $cancelledCount,
                        'average_per_day' => (float) $averagePerDay,
                        'today_sales' => $todaySales,
                        'today_amount' => (float) $todayAmount,
                    ],
                    'by_status' => $byStatus,
                    'by_payment_method' => $byPaymentMethod,
                    'top_products' => $topProducts,
                    'sales_trend' => $salesTrend,
                    // Periodo de los totales (sin rango pedido: últimos 30 días).
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas de ventas', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
            ], 500);
        }
    }

    /**
     * Obtener datos iniciales para crear una venta (productos, categorías, unidades)
     */
    public function getInitialData(Request $request)
    {
        // Productos con su costo y existencias: solo para quien arma ventas o
        // transferencias (los formularios que lo usan). Antes cualquier sesión,
        // p. ej. la cuenta del kiosko, podía bajar los costos de compra.
        if (!CurrentWorker::hasAnyPermission(['sales_create', 'sales_update', 'transfers_create', 'transfers_update'])) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $companyId = CurrentCompany::get()->id;
            $locationId = $request->integer('location_id') ?: CurrentLocation::get()->id;

            $locationBelongsToCompany = DB::table('locations')
                ->where('id', $locationId)
                ->where('company_id', $companyId)
                ->exists();

            if (!$locationBelongsToCompany) {
                return response()->json([
                    'success' => false,
                    'message' => 'La ubicación seleccionada no pertenece a la empresa actual',
                ], 422);
            }

            // Productos a la venta con sus paquetes, y también los productos que
            // no se venden sueltos pero tienen paquetes con precio (de ellos solo
            // se vende el paquete: for_sale le dice a la app que no ofrezca la
            // pieza). Antes esos paquetes no aparecían en ninguna pantalla.
            $products = Product::where('company_id', $companyId)
                ->where(fn($q) => $q->where('for_sale', true)->orWhereHas('activePackages'))
                ->whereHas('locations', function ($query) use ($locationId) {
                    $query->where('location_id', $locationId)
                        ->where('active', true);
                })
                ->with([
                    'mainImage',
                    'unit',
                    'category',
                    'activePackages',
                    'locations' => function ($query) use ($locationId) {
                        $query->where('location_id', $locationId);
                    }
                ])
                ->get()
                ->filter(fn($product) => SalePricing::onlySellablePackages($product))
                ->values();

            // Formatear las imágenes de los productos manualmente
            $productsWithImages = $products->map(function ($product) {
                $productArray = $product->toArray();

                // Precio de catálogo de cada paquete (el que valida el servidor).
                $productArray['active_packages'] = SalePricing::packagesWithPrice($product, $productArray['active_packages'] ?? []);

                // Formatear imagen principal si existe
                if ($product->relationLoaded('mainImage') && $product->mainImage) {
                    $productArray['main_image'] = \App\Utils\AppUploadUtil::formatFile(
                        \App\Constants\Files::PRODUCT_IMAGES_PATH,
                        $product->mainImage->image_path
                    );
                }

                return $productArray;
            });

            // Cargar unidades agrupadas por tipo
            $units = DB::table('units')
                ->select('id', 'name', 'abbreviation', 'unit_type', 'is_base_unit', 'factor_to_base')
                ->orderBy('unit_type')
                ->orderBy('name')
                ->get()
                ->groupBy('unit_type');

            // Cargar categorías
            $categories = DB::table('categories')
                ->where('company_id', $companyId)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $productsWithImages,
                    'units' => $units,
                    'categories' => $categories,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener datos iniciales para venta', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cargar datos iniciales',
            ], 500);
        }
    }

    /**
     * Agregar un pago a una venta existente
     */
    public function addPayment(Request $request, int $id)
    {
        if (!CurrentWorker::hasPermission('sales_update')) {
            return $this->forbiddenResponse('registrar pagos de ventas');
        }

        // Fuera del try: un error de validación es un 422, no "Error al
        // registrar pago" (500). "Crédito" no es una forma de pago: un abono
        // "a crédito" marcaba como cobrado un dinero que no entró y lo
        // asentaba en caja como "otro". Un abono es efectivo, tarjeta o
        // transferencia.
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,transfer',
            'notes' => 'nullable|string|max:500',
            // Llave del abono: el reintento con la misma no lo registra dos veces.
            'client_request_id' => ClientRequest::rules(),
        ], [
            'payment_method.in' => 'Un abono se registra en efectivo, tarjeta o transferencia.',
        ]);

        try {
            // La venta debe ser de la empresa/sucursal del contexto.
            $scopedId = $this->scopedQuery()->whereKey($id)->value('id');
            if (!$scopedId) {
                return response()->json(['success' => false, 'message' => 'Venta no encontrada'], 404);
            }

            $requestId = $validated['client_request_id'] ?? null;

            $result = DB::transaction(function () use ($scopedId, $validated, $requestId) {
                // Bloqueo de fila: evita que dos pagos simultáneos sobrepasen el total.
                $sale = Sale::whereKey($scopedId)->lockForUpdate()->firstOrFail();

                // Reintento de un abono ya registrado (misma llave): se responde
                // igual que la primera vez, antes de validar el saldo (que ya
                // incluye ese abono) o el turno. Con la venta bloqueada, dos
                // envíos simultáneos se forman y el segundo ya ve el primero.
                if ($requestId && $sale->payments()->where('client_request_id', $requestId)->exists()) {
                    return ['sale' => $sale];
                }

                if ($sale->status === SaleStatus::CANCELLED) {
                    return ['error' => 'No se puede registrar un pago en una venta cancelada', 'status' => 400];
                }

                // En centavos enteros: pagar el saldo exacto en parcialidades
                // (50.10 + 50.20 vs 100.30) no se rechaza por el error de punto
                // flotante.
                $paidCents = Money::toCents($sale->paid_amount ?? 0);
                $newCents = Money::toCents($validated['amount']);
                $totalCents = Money::toCents($sale->total);
                $afterCents = $paidCents + $newCents;
                $newAmount = Money::fromCents($newCents);
                $totalAfterPayment = Money::fromCents($afterCents);

                if ($afterCents > $totalCents) {
                    return [
                        'error' => 'El monto ingresado excede el saldo pendiente',
                        'status' => 400,
                        'pending_amount' => Money::fromCents($totalCents - $paidCents),
                    ];
                }

                // Turno de caja de quien cobra en la sucursal de la venta, con su
                // fila bloqueada (venta → turno): el abono entra a ESE turno, no
                // al turno (quizá ya cerrado) en el que se creó la venta. El
                // efectivo exige turno abierto (C14).
                $session = app(CashRegisterSessionService::class)->lockOpenForUser(Auth::id(), (int) $sale->location_id);
                $isCash = $validated['payment_method'] === 'cash';
                if ($isCash && !$session) {
                    throw CashRegisterSessionService::cashNeedsOpenShift();
                }

                $paymentHistory = $sale->payment_history ?? [];
                $paymentHistory[] = [
                    'amount' => $newAmount,
                    'payment_method' => $validated['payment_method'],
                    'date' => now()->toDateTimeString(),
                    'notes' => $validated['notes'] ?? null,
                ];

                $sale->paid_amount = $totalAfterPayment;
                $sale->payment_history = $paymentHistory;
                $sale->payment_status = $afterCents >= $totalCents
                    ? 'paid'
                    : ($afterCents > 0 ? 'partial' : 'pending');
                $sale->save();

                // El abono también queda en sale_payments, donde los reportes y
                // el corte leen lo cobrado por método (antes solo estaba el
                // cobro inicial). Su created_at es el momento del abono.
                $sale->payments()->create([
                    'client_request_id' => $requestId,
                    'method' => $validated['payment_method'],
                    'amount' => $newAmount,
                    'tendered' => $isCash ? $newAmount : null,
                    'change' => $isCash ? 0 : null,
                ]);

                // Fechado hoy, en el turno de quien cobra.
                CashMovementService::fromSalePayment(
                    $sale,
                    $newAmount,
                    $validated['payment_method'],
                    $validated['notes'] ?? null,
                    $session?->id
                );

                return ['sale' => $sale];
            });

            if (isset($result['error'])) {
                return response()->json(array_filter([
                    'success' => false,
                    'message' => $result['error'],
                    'pending_amount' => $result['pending_amount'] ?? null,
                ], fn($v) => $v !== null), $result['status']);
            }

            $sale = $result['sale']->load($this->getShowRelations());

            return response()->json([
                'success' => true,
                'message' => 'Pago registrado correctamente',
                'data' => new $this->resource($sale),
            ]);
        } catch (BusinessRuleException $e) {
            // Sin turno para cobrar en efectivo, turno recién cerrado…: 422 {message, code}.
            return $e->render();
        } catch (\Exception $e) {
            // Respaldo del índice único (venta + llave): el abono ya quedó
            // registrado por otra petición con la misma llave.
            if (($requestId ?? null) && ClientRequest::isDuplicate($e)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pago registrado correctamente',
                    'data' => new $this->resource(Sale::findOrFail($id)->load($this->getShowRelations())),
                ]);
            }

            Log::error('Error al registrar pago', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar pago',
            ], 500);
        }
    }

    /**
     * Generar URL firmada para el PDF de la venta (válida 1 hora).
     */
    public function generatePdfUrl($id)
    {
        if (!CurrentWorker::hasPermission('sales_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $companyId = CurrentCompany::id();
            $locationId = CurrentLocation::get()?->id;

            $sale = Sale::where('company_id', $companyId)
                ->where('location_id', $locationId)
                ->findOrFail($id);

            $signedUrl = URL::temporarySignedRoute(
                'sales.pdf',
                now()->addHour(),
                [
                    'sale' => $sale->id,
                    'company_id' => $companyId,
                ]
            );

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ]);
        } catch (\Exception $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            Log::error('Error al generar URL de PDF de venta: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al generar URL del PDF'
            ], 500);
        }
    }

    /**
     * Generar PDF de la venta. Ruta pública-firmada (no requiere auth:sanctum).
     */
    public function generatePdf(Request $request, $id)
    {
        try {
            $companyId = $request->query('company_id');
            abort_if(
                $companyId === null || !Sale::where('id', $id)->where('company_id', $companyId)->exists(),
                404
            );

            $sale = Sale::with([
                'customer',
                'user',
                'location',
                'details.product',
                'details.unit',
            ])->findOrFail($id);

            $pdf = Pdf::loadView('pdf.sale', [
                'sale' => $sale,
            ]);

            $pdf->setPaper('letter', 'portrait');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="venta-' . ($sale->sale_number ?? $sale->id) . '-' . now()->format('Y-m-d') . '.pdf"',
            ]);
        } catch (\Exception $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            Log::error('Error al generar PDF de venta: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al generar el PDF'
            ], 500);
        }
    }
}
