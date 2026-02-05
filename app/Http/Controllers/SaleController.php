<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Product;
use App\Models\Expense;
use App\Enums\SaleStatus;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Services\NotificationService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'location',
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

        // Filtro por fecha de creación
        if (isset($params['date_from']) && !empty($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }

        if (isset($params['date_to']) && !empty($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
        }
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar por estado
        if (isset($params['status']) && !empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        // Filtrar por rango de fechas
        if (isset($params['start_date']) && isset($params['end_date'])) {
            $query->betweenDates($params['start_date'], $params['end_date']);
        }

        // Filtrar por ubicación
        if (isset($params['location_id'])) {
            $query->where('location_origin_id', $params['location_id']);
        }

        // Filtrar por método de pago
        if (isset($params['payment_method']) && !empty($params['payment_method'])) {
            $query->where('content->payment_method', $params['payment_method']);
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
            'payment_method' => 'required|in:efectivo,tarjeta,transferencia',
            'notes' => 'nullable|string',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Detalles de la venta
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            // Información del cliente (opcional)
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:255',

            // Método de pago
            'payment_method' => 'sometimes|required|in:efectivo,tarjeta,transferencia',
            'notes' => 'nullable|string',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Detalles de la venta
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();

            // Obtener company y location del contexto
            $company = CurrentCompany::get();
            $location = CurrentLocation::get();

            // Preparar datos del content
            $content = [
                'payment_method' => $data['payment_method'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            // Agregar document_number y comments a content
            if (isset($data['document_number'])) {
                $content['document_number'] = $data['document_number'];
            }
            if (isset($data['comments'])) {
                $content['comments'] = $data['comments'];
            }


            // Preparar datos de la venta
            $saleData = [
                'location_origin_id' => $location->id,
                'location_destination_id' => $location->id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'content' => $content,
                'company_id' => $company->id,
                'user_id' => $user->id,
            ];

            // Calcular total
            $totalCost = 0;
            foreach ($data['details'] as $detail) {
                $totalCost += $detail['quantity'] * $detail['unit_price'];
            }
            $saleData['total_cost'] = $totalCost;

            // Validar monto recibido para efectivo
            /* if ($data['payment_method'] === 'efectivo' && isset($data['received_amount'])) {
                if ($data['received_amount'] < $totalCost) {
                    throw new \Exception('El monto recibido debe ser mayor o igual al total de la venta');
                }
            }
 */
            // Crear o actualizar venta
            /** @var Sale $sale */
            $sale = $callback($saleData);

            // Manejar detalles
            if (isset($data['details'])) {
                // Si es actualización, eliminar detalles existentes
                if ($method === 'update') {
                    $sale->details()->delete();
                }

                // Crear nuevos detalles
                foreach ($data['details'] as $detail) {
                    SaleDetail::create([
                        'movement_id' => $sale->id,
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                        'unit_cost' => $detail['unit_price'],
                        'total_cost' => $detail['quantity'] * $detail['unit_price'],
                    ]);
                }
            }

            // Marcar como cerrada y afectar stock automáticamente
            if ($method === 'create') {
                // Usar transitionTo que maneja validación y actualización de stock
                $sale->transitionTo(SaleStatus::CLOSED);

                // Verificar stock bajo después de la venta
                $this->checkLowStockAndNotify($sale);
            }

            // Recargar relaciones
            $sale->load($this->getShowRelations());

            DB::commit();
            return $sale;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Avanzar al siguiente estado
     */
    public function advanceStatus(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

            if ($sale->advanceStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Venta avanzada a estado: {$sale->status->label()}",
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede avanzar más el estado',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retroceder al estado anterior
     */
    public function revertStatus(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

            if ($sale->revertStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Venta revertida a estado: {$sale->status->label()}",
                    'data' => new $this->resource($sale),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede retroceder más el estado',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancelar venta
     */
    public function cancel(Request $request, int $id)
    {
        try {
            $sale = Sale::with($this->getShowRelations())->findOrFail($id);

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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // Solo se pueden eliminar ventas en borrador
        if ($model->status !== SaleStatus::DRAFT) {
            return [
                'can_delete' => false,
                'message' => 'Solo se pueden eliminar ventas en estado borrador'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Obtener estadísticas y reportes de ventas
     */
    public function salesStats(Request $request)
    {
        try {
            $locationId = $request->input('location_id') ?? current_location_id();
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Query base
            $query = Sale::where('location_origin_id', $locationId);

            if ($startDate) {
                $query->where('movement_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('movement_date', '<=', $endDate);
            }

            // Estadísticas generales
            $totalSales = (clone $query)->count();
            $totalAmount = (clone $query)->sum('total_cost');
            $averageAmount = $totalSales > 0 ? $totalAmount / $totalSales : 0;

            // Ventas del día
            $todaySales = (clone $query)
                ->whereDate('movement_date', now()->toDateString())
                ->count();
            $todayAmount = (clone $query)
                ->whereDate('movement_date', now()->toDateString())
                ->sum('total_cost');

            // Ventas por estado
            $byStatus = (clone $query)
                ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_cost) as total'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(function ($item) {
                    // Convertir enum a string si es necesario
                    $statusValue = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;

                    $statusLabel = match ($statusValue) {
                        'draft' => 'Borrador',
                        'processed' => 'Procesada',
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
                ->select(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.payment_method')) as payment_method"))
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('SUM(total_cost) as total')
                ->groupBy('payment_method')
                ->get()
                ->mapWithKeys(function ($item) {
                    $methodLabel = match ($item->payment_method) {
                        'efectivo' => 'Efectivo',
                        'tarjeta' => 'Tarjeta',
                        'transferencia' => 'Transferencia',
                        default => $item->payment_method ?? 'Sin especificar'
                    };

                    return [$item->payment_method ?? 'unknown' => [
                        'label' => $methodLabel,
                        'count' => $item->count,
                        'total' => (float) $item->total,
                    ]];
                });

            // Productos más vendidos
            $topProducts = DB::table('movements_details')
                ->join('movements', 'movements_details.movement_id', '=', 'movements.id')
                ->join('products', 'movements_details.product_id', '=', 'products.id')
                ->where('movements.location_origin_id', $locationId)
                ->where('movements.movement_type', 'exit')
                ->where('movements.movement_reason', 'sale')
                ->when($startDate, function ($q) use ($startDate) {
                    return $q->where('movements.movement_date', '>=', $startDate);
                })
                ->when($endDate, function ($q) use ($endDate) {
                    return $q->where('movements.movement_date', '<=', $endDate);
                })
                ->select(
                    'products.id',
                    'products.name',
                    DB::raw('SUM(movements_details.quantity) as total_quantity'),
                    DB::raw('SUM(movements_details.total_cost) as total_amount')
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

            // Tendencia de ventas (últimos 6 meses)
            $sixMonthsAgo = now()->subMonths(6)->startOfMonth();
            $salesTrend = (clone $query)
                ->where('movement_date', '>=', $sixMonthsAgo)
                ->select(
                    DB::raw('DATE_FORMAT(movement_date, "%Y-%m") as month'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(total_cost) as total')
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

            $cancelledCount = (clone $query)
                ->where('status', 'cancelled')
                ->count();

            // Venta promedio por día
            $daysInRange = $startDate && $endDate
                ? max(1, now()->parse($endDate)->diffInDays(now()->parse($startDate)) + 1)
                : 30; // Default 30 días si no hay rango

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
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar stock bajo y enviar notificaciones
     */
    protected function checkLowStockAndNotify(Sale $sale): void
    {
        try {
            $locationId = $sale->location_origin_id;
            $companyId = $sale->company_id;

            foreach ($sale->details as $detail) {

                $productLocation = DB::table('product_location')
                    ->where('product_id', $detail->product_id)
                    ->where('location_id', $locationId)
                    ->first();

                if (!$productLocation || !$productLocation->minimum_stock) {
                    continue;
                }

                $currentStock = $productLocation->current_stock ?? 0;
                $minimumStock = $productLocation->minimum_stock;

                // Si el stock actual es menor al mínimo, enviar notificación
                if ($currentStock < $minimumStock) {
                    $product = Product::find($detail->product_id);

                    NotificationService::notifyLowStock(
                        $companyId,
                        $locationId,
                        $product,
                        $currentStock,
                        $minimumStock
                    );
                }
            }
        } catch (\Exception $e) {
            // Log error pero no fallar la venta
            Log::error('Error al verificar stock bajo: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Obtener información de corte de caja por fecha
     */
    public function cashRegister(Request $request)
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'location_id' => 'nullable|exists:locations,id',
                'company_id' => 'nullable|exists:companies,id',
            ]);

            $date = $validated['date'];
            $locationId = $validated['location_id'] ?? CurrentLocation::get()?->id;
            $company = CurrentCompany::get();

            // Si no hay compañía del contexto, usar la del parámetro o la del usuario
            $companyId = $validated['company_id'] ?? $company?->id ?? Auth::user()?->company_id ?? 1;

            // Obtener todas las ventas del día (closed y processed)
            $sales = Sale::where('company_id', $companyId)
                ->when($locationId, function ($q) use ($locationId) {
                    $q->where('location_origin_id', $locationId);
                })
                ->whereDate('movement_date', $date)
                ->whereIn('status', ['closed', 'processed'])
                ->with(['details.product'])
                ->get();

            // Calcular totales por método de pago
            $paymentMethods = [
                'efectivo' => 0,
                'tarjeta' => 0,
                'transferencia' => 0,
            ];

            $totalSales = 0;
            $totalProducts = 0;
            $salesCount = $sales->count();

            foreach ($sales as $sale) {
                $saleTotal = $sale->details->sum(function ($detail) {
                    return $detail->quantity * $detail->unit_cost;
                });

                $totalSales += $saleTotal;
                $totalProducts += $sale->details->sum('quantity');

                $paymentMethod = $sale->content['payment_method'] ?? 'efectivo';
                if (isset($paymentMethods[$paymentMethod])) {
                    $paymentMethods[$paymentMethod] += $saleTotal;
                }
            }

            // Productos más vendidos del día
            $topProducts = [];
            $productSales = [];

            foreach ($sales as $sale) {
                foreach ($sale->details as $detail) {
                    $productId = $detail->product_id;
                    if (!isset($productSales[$productId])) {
                        $productSales[$productId] = [
                            'product' => $detail->product,
                            'quantity' => 0,
                            'total' => 0,
                        ];
                    }
                    $productSales[$productId]['quantity'] += $detail->quantity;
                    $productSales[$productId]['total'] += $detail->quantity * $detail->unit_cost;
                }
            }

            // Ordenar por cantidad vendida
            usort($productSales, function ($a, $b) {
                return $b['quantity'] <=> $a['quantity'];
            });

            $topProducts = array_slice($productSales, 0, 5);

            // Ventas por hora
            $salesByHour = [];
            for ($i = 0; $i < 24; $i++) {
                $salesByHour[$i] = [
                    'hour' => $i,
                    'count' => 0,
                    'total' => 0,
                ];
            }

            foreach ($sales as $sale) {
                $hour = (int) date('H', strtotime($sale->created_at));
                $saleTotal = $sale->details->sum(function ($detail) {
                    return $detail->quantity * $detail->unit_cost;
                });

                $salesByHour[$hour]['count']++;
                $salesByHour[$hour]['total'] += $saleTotal;
            }

            // Filtrar solo horas con ventas
            $salesByHour = array_values(array_filter($salesByHour, function ($hour) {
                return $hour['count'] > 0;
            }));

            // Obtener gastos del día
            $expenses = Expense::where('company_id', $companyId)
                ->when($locationId, function ($q) use ($locationId) {
                    $q->where('location_id', $locationId);
                })
                ->whereDate('expense_date', $date)
                ->with(['user'])
                ->get();

            // Calcular totales de gastos por método de pago
            $expensesByPaymentMethod = [
                'efectivo' => 0,
                'tarjeta' => 0,
                'transferencia' => 0,
            ];

            $totalExpenses = 0;
            $expensesByCategory = [];

            foreach ($expenses as $expense) {
                $totalExpenses += $expense->amount;

                // Sumar por método de pago
                if (isset($expensesByPaymentMethod[$expense->payment_method])) {
                    $expensesByPaymentMethod[$expense->payment_method] += $expense->amount;
                }

                // Sumar por categoría
                if (!isset($expensesByCategory[$expense->category])) {
                    $expensesByCategory[$expense->category] = [
                        'category' => $expense->category_label,
                        'total' => 0,
                        'count' => 0,
                    ];
                }
                $expensesByCategory[$expense->category]['total'] += $expense->amount;
                $expensesByCategory[$expense->category]['count']++;
            }

            // Calcular efectivo neto (ventas - gastos)
            $netCash = $paymentMethods['efectivo'] - $expensesByPaymentMethod['efectivo'];

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'location_id' => $locationId,
                    'summary' => [
                        'total_sales' => round($totalSales, 2),
                        'sales_count' => $salesCount,
                        'total_products' => $totalProducts,
                        'average_ticket' => $salesCount > 0 ? round($totalSales / $salesCount, 2) : 0,
                        'total_expenses' => round($totalExpenses, 2),
                        'net_income' => round($totalSales - $totalExpenses, 2),
                    ],
                    'payment_methods' => [
                        [
                            'method' => 'Efectivo',
                            'total' => round($paymentMethods['efectivo'], 2),
                            'percentage' => $totalSales > 0 ? round(($paymentMethods['efectivo'] / $totalSales) * 100, 1) : 0,
                            'expenses' => round($expensesByPaymentMethod['efectivo'], 2),
                            'net' => round($netCash, 2),
                        ],
                        [
                            'method' => 'Tarjeta',
                            'total' => round($paymentMethods['tarjeta'], 2),
                            'percentage' => $totalSales > 0 ? round(($paymentMethods['tarjeta'] / $totalSales) * 100, 1) : 0,
                            'expenses' => round($expensesByPaymentMethod['tarjeta'], 2),
                            'net' => round($paymentMethods['tarjeta'] - $expensesByPaymentMethod['tarjeta'], 2),
                        ],
                        [
                            'method' => 'Transferencia',
                            'total' => round($paymentMethods['transferencia'], 2),
                            'percentage' => $totalSales > 0 ? round(($paymentMethods['transferencia'] / $totalSales) * 100, 1) : 0,
                            'expenses' => round($expensesByPaymentMethod['transferencia'], 2),
                            'net' => round($paymentMethods['transferencia'] - $expensesByPaymentMethod['transferencia'], 2),
                        ],
                    ],
                    'expenses' => [
                        'total' => round($totalExpenses, 2),
                        'count' => $expenses->count(),
                        'by_category' => array_values($expensesByCategory),
                        'items' => $expenses->map(function ($expense) {
                            return [
                                'id' => $expense->id,
                                'category' => $expense->category_label,
                                'description' => $expense->description,
                                'amount' => round($expense->amount, 2),
                                'payment_method' => $expense->payment_method_label,
                                'user' => $expense->user->name ?? 'N/A',
                                'created_at' => $expense->created_at->format('H:i:s'),
                            ];
                        }),
                    ],
                    'top_products' => array_map(function ($item) {
                        return [
                            'id' => $item['product']->id,
                            'name' => $item['product']->name,
                            'code' => $item['product']->code,
                            'quantity' => $item['quantity'],
                            'total' => round($item['total'], 2),
                        ];
                    }, $topProducts),
                    'sales_by_hour' => $salesByHour,
                    'sales' => $sales->map(function ($sale) {
                        return [
                            'id' => $sale->id,
                            'document_number' => $sale->document_number,
                            'customer_name' => $sale->content['customer_name'] ?? 'Cliente General',
                            'payment_method' => $sale->content['payment_method'] ?? 'efectivo',
                            'total' => round($sale->details->sum(function ($detail) {
                                return $detail->quantity * $detail->unit_cost;
                            }), 2),
                            'created_at' => $sale->created_at->format('H:i:s'),
                        ];
                    }),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener corte de caja: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener corte de caja: ' . $e->getMessage()
            ], 500);
        }
    }
}
