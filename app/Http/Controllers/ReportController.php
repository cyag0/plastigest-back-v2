<?php

namespace App\Http\Controllers;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\PurchaseV2;
use App\Models\Expense;
use App\Models\CashMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Dashboard V2 desacoplado de estructura legacy de movements.
     */
    public function dashboardV2(Request $request)
    {
        if (!CurrentWorker::hasPermission('reports_view')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        $scope = $this->resolveScope($request);
        $period = $request->get('period', 'month');
        $dateRange = $this->getDateRange($period);

        // Ventas vigentes: una venta cancelada no es dinero vendido. Antes el
        // dashboard (KPIs, tendencia, sucursales, top productos y métodos de
        // pago) sumaba también las canceladas.
        $salesQuery = Sale::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', SaleStatus::CANCELLED->value)
            ->whereBetween('sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            });

        // Compras recibidas: un borrador, un pedido sin recibir o una compra
        // cancelada no son gasto. Antes contaban todas y la utilidad salía mal.
        $purchasesQuery = PurchaseV2::query()
            ->where('company_id', $companyId)
            ->where('status', PurchaseV2::STATUS_RECEIVED)
            ->whereBetween('purchase_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            });

        $expensesQuery = Expense::query()
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            });

        $cashMovementsQuery = CashMovement::query()
            ->where('company_id', $companyId)
            ->whereBetween('movement_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            });

        $transfersQuery = InventoryTransfer::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where(function ($q) use ($locationId) {
                    $q->where('from_location_id', $locationId)
                        ->orWhere('to_location_id', $locationId);
                });
            });

        $salesTotal = (float) (clone $salesQuery)->sum('total');
        $purchasesTotal = (float) (clone $purchasesQuery)->sum('total');
        $expensesTotal = (float) (clone $expensesQuery)->sum('amount');

        $cashIncome = (float) (clone $cashMovementsQuery)
            ->where('type', 'income')
            ->sum('amount');
        $cashExpense = (float) (clone $cashMovementsQuery)
            ->where('type', 'expense')
            ->sum('amount');

        $salesCount = (int) (clone $salesQuery)->count();
        $purchasesCount = (int) (clone $purchasesQuery)->count();
        $expensesCount = (int) (clone $expensesQuery)->count();
        $cashMovementsCount = (int) (clone $cashMovementsQuery)->count();
        $transfersCount = (int) (clone $transfersQuery)->count();

        $totalStock = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->sum('product_location.current_stock');

        $inventoryValue = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->sum(DB::raw('product_location.current_stock * products.purchase_price'));

        $lowStockProducts = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->whereRaw('product_location.current_stock < product_location.minimum_stock')
            ->count();

        $salesTrend = Sale::query()
            ->selectRaw($this->getSalesV2Grouping($period) . ' as period, SUM(total) as total')
            ->where('company_id', $companyId)
            ->where('status', '!=', SaleStatus::CANCELLED->value)
            ->whereBetween('sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(function ($row) {
                return [
                    'period' => (string) $row->period,
                    'total' => (float) ($row->total ?? 0),
                ];
            })
            ->values();

        $salesByLocation = DB::table('sales')
            ->join('locations', 'sales.location_id', '=', 'locations.id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', '!=', SaleStatus::CANCELLED->value)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('sales.location_id', $locationId);
            })
            ->select('locations.name', DB::raw('SUM(sales.total) as value'))
            ->groupBy('locations.id', 'locations.name')
            ->orderByDesc('value')
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->name,
                    'value' => (float) ($row->value ?? 0),
                ];
            })
            ->values();

        $topProducts = DB::table('sales_details')
            ->join('sales', 'sales_details.sale_id', '=', 'sales.id')
            ->join('products', 'sales_details.product_id', '=', 'products.id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', '!=', SaleStatus::CANCELLED->value)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('sales.location_id', $locationId);
            })
            ->select(
                'products.name',
                DB::raw('SUM(sales_details.quantity) as quantity'),
                DB::raw('SUM(sales_details.total) as sales')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('sales')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->name,
                    'quantity' => (float) ($row->quantity ?? 0),
                    'sales' => (float) ($row->sales ?? 0),
                ];
            })
            ->values();

        $paymentLabel = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'credit' => 'Crédito',
            'other' => 'Otro',
            'efectivo' => 'Efectivo',
            'tarjeta' => 'Tarjeta',
            'transferencia' => 'Transferencia',
            'sin_especificar' => 'Sin especificar',
        ];

        $paymentColor = [
            'cash' => '#809671',
            'efectivo' => '#809671',
            'card' => '#8B9FA8',
            'tarjeta' => '#8B9FA8',
            'transfer' => '#725C3A',
            'transferencia' => '#725C3A',
            'credit' => '#A65A4D',
            'other' => '#A65A4D',
            'sin_especificar' => '#A65A4D',
        ];

        $paymentMethods = Sale::query()
            ->select('payment_method', DB::raw('SUM(total) as value'))
            ->where('company_id', $companyId)
            ->where('status', '!=', SaleStatus::CANCELLED->value)
            ->whereBetween('sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->groupBy('payment_method')
            ->get()
            ->map(function ($row) use ($paymentLabel, $paymentColor) {
                $key = strtolower((string) ($row->payment_method ?? 'sin_especificar'));
                return [
                    'name' => $paymentLabel[$key] ?? ucfirst($key),
                    'value' => (float) ($row->value ?? 0),
                    'color' => $paymentColor[$key] ?? '#A65A4D',
                ];
            })
            ->values();

        // La actividad reciente muestra montos como ingreso o egreso: se toman
        // los mismos criterios que los KPIs (sin canceladas, compras recibidas).
        $recentSales = Sale::query()
            ->with('location:id,name')
            ->where('company_id', $companyId)
            ->where('status', '!=', SaleStatus::CANCELLED->value)
            ->whereBetween('sale_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => 'sale-' . $sale->id,
                    'type' => 'sale',
                    'label' => 'Venta ' . ($sale->sale_number ?? ('#' . $sale->id)),
                    'date' => optional($sale->sale_date)->toDateString(),
                    'amount' => (float) ($sale->total ?? 0),
                    'money_type' => 'income',
                    'location' => $sale->location?->name,
                    'sort_at' => optional($sale->created_at)?->toDateTimeString(),
                ];
            });

        $recentPurchases = PurchaseV2::query()
            ->with('location:id,name')
            ->where('company_id', $companyId)
            ->where('status', PurchaseV2::STATUS_RECEIVED)
            ->whereBetween('purchase_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => 'purchase-' . $purchase->id,
                    'type' => 'purchase',
                    'label' => 'Compra ' . ($purchase->purchase_number ?? ('#' . $purchase->id)),
                    'date' => optional($purchase->purchase_date)->toDateString(),
                    'amount' => (float) ($purchase->total ?? 0),
                    'money_type' => 'expense',
                    'location' => $purchase->location?->name,
                    'sort_at' => optional($purchase->created_at)?->toDateTimeString(),
                ];
            });

        $recentExpenses = Expense::query()
            ->with('location:id,name')
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($expense) {
                return [
                    'id' => 'expense-' . $expense->id,
                    'type' => 'expense',
                    'label' => 'Gasto: ' . ($expense->category_label ?? $expense->category),
                    'date' => optional($expense->expense_date)->toDateString(),
                    'amount' => (float) ($expense->amount ?? 0),
                    'money_type' => 'expense',
                    'location' => $expense->location?->name,
                    'sort_at' => optional($expense->created_at)?->toDateTimeString(),
                ];
            });

        $recentCashMovements = CashMovement::query()
            ->with('location:id,name')
            ->where('company_id', $companyId)
            ->whereBetween('movement_date', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_id', $locationId);
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($movement) {
                $moneyType = $movement->type === 'income' ? 'income' : ($movement->type === 'expense' ? 'expense' : 'none');
                return [
                    'id' => 'cash-' . $movement->id,
                    'type' => 'cash_' . $movement->type,
                    'label' => 'Caja: ' . ($movement->concept ?? 'Movimiento'),
                    'date' => optional($movement->movement_date)->toDateString(),
                    'amount' => (float) ($movement->amount ?? 0),
                    'money_type' => $moneyType,
                    'location' => $movement->location?->name,
                    'sort_at' => optional($movement->created_at)?->toDateTimeString(),
                ];
            });

        $recentTransfers = InventoryTransfer::query()
            ->with(['fromLocation:id,name', 'toLocation:id,name'])
            ->where('company_id', $companyId)
            ->whereBetween('created_at', $dateRange)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where(function ($q) use ($locationId) {
                    $q->where('from_location_id', $locationId)
                        ->orWhere('to_location_id', $locationId);
                });
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($transfer) {
                return [
                    'id' => 'transfer-' . $transfer->id,
                    'type' => 'transfer',
                    'label' => 'Transferencia ' . ($transfer->transfer_number ?? ('#' . $transfer->id)),
                    'date' => optional($transfer->created_at)?->toDateString(),
                    'amount' => (float) ($transfer->total_cost ?? 0),
                    'money_type' => 'none',
                    'location' => trim(($transfer->fromLocation?->name ?? '-') . ' -> ' . ($transfer->toLocation?->name ?? '-')),
                    'sort_at' => optional($transfer->created_at)?->toDateTimeString(),
                ];
            });

        $recentActivity = collect()
            ->merge($recentSales)
            ->merge($recentPurchases)
            ->merge($recentExpenses)
            ->merge($recentCashMovements)
            ->merge($recentTransfers)
            ->sortByDesc('sort_at')
            ->take(12)
            ->values()
            ->map(function ($row) {
                unset($row['sort_at']);
                return $row;
            });

        return response()->json([
            'data' => [
                'kpis' => [
                    'sales' => round($salesTotal, 2),
                    'purchases' => round($purchasesTotal, 2),
                    'profit' => round($salesTotal - $purchasesTotal, 2),
                    'net_profit' => round($salesTotal - $purchasesTotal - $expensesTotal, 2),
                ],
                'mini_kpis' => [
                    'movements_count' => $salesCount + $purchasesCount + $expensesCount + $cashMovementsCount + $transfersCount,
                    'inventory_value' => round((float) $inventoryValue, 2),
                    'low_stock_products' => (int) $lowStockProducts,
                    'total_stock' => round((float) $totalStock, 2),
                ],
                'cash' => [
                    'income' => round($cashIncome, 2),
                    'expense' => round($cashExpense, 2),
                    'balance' => round($cashIncome - $cashExpense, 2),
                ],
                'expenses' => [
                    'total' => round($expensesTotal, 2),
                    'count' => $expensesCount,
                ],
                'sales_trend' => $salesTrend,
                'sales_by_location' => $salesByLocation,
                'top_products' => $topProducts,
                'payment_methods' => $paymentMethods,
                'recent_activity' => $recentActivity,
                'filters' => [
                    'scope' => $scope,
                    'period' => $period,
                ],
            ]
        ]);
    }

    // Helper methods

    private function getDateRange($period)
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay()
            ],
            'week' => [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek()
            ],
            'month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth()
            ],
            default => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth()
            ]
        };
    }

    private function getSalesV2Grouping($period)
    {
        return match ($period) {
            'today' => "DATE_FORMAT(created_at, '%H:00')",
            'week' => "DATE(sale_date)",
            'month' => "DATE(sale_date)",
            default => "DATE(sale_date)"
        };
    }

    /**
     * Get inventory statistics
     */
    public function inventoryStats(Request $request)
    {
        if (!CurrentWorker::hasPermission('reports_view')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $this->resolveScope($request);

        // Total de productos activos. El estatus "activo" vive en el pivote
        // product_location (columna `active`), no en la tabla products.
        $totalProductsQuery = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->where('product_location.active', true);

        if ($scope === 'location' && $locationId) {
            $totalProductsQuery->where('product_location.location_id', $locationId);
        }

        $totalProducts = (clone $totalProductsQuery)
            ->distinct()
            ->count('product_location.product_id');

        // Total de stock actual
        $totalStockQuery = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId);

        if ($scope === 'location' && $locationId) {
            $totalStockQuery->where('product_location.location_id', $locationId);
        }

        $totalStock = $totalStockQuery->sum('product_location.current_stock');

        // Valor total del inventario
        $inventoryValue = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->sum(DB::raw('product_location.current_stock * products.purchase_price'));

        // Productos con stock bajo
        $lowStockProducts = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->whereRaw('product_location.current_stock < product_location.minimum_stock')
            //->whereRaw('product_location.current_stock > 0')
            ->count();

        // Productos sin stock
        $outOfStockProducts = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->where('product_location.current_stock', '<=', 0)
            ->count();

        // Productos por categoría
        $productsByCategory = DB::table('products')
            ->leftJoin('product_location', 'products.id', '=', 'product_location.product_id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('products.company_id', $companyId)
            ->where('product_location.active', true)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->selectRaw('
                COALESCE(categories.name, "Sin categoría") as category,
                COUNT(DISTINCT products.id) as count
            ')
            ->groupBy('category')
            ->get()
            ->pluck('count', 'category')
            ->toArray();

        // Salud del stock (porcentajes)
        $stockHealthQuery = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            });

        $totalProductsInLocation = (clone $stockHealthQuery)->count();

        if ($totalProductsInLocation > 0) {
            // Sin existencia es "agotado" aunque el mínimo sea 0: si no, un
            // producto contaba como óptimo y agotado a la vez.
            $optimal = (clone $stockHealthQuery)
                ->whereRaw('product_location.current_stock >= product_location.minimum_stock')
                ->where('product_location.current_stock', '>', 0)
                ->count();

            $low = (clone $stockHealthQuery)
                ->whereRaw('product_location.current_stock < product_location.minimum_stock')
                ->whereRaw('product_location.current_stock >= (product_location.minimum_stock * 0.5)')
                ->count();

            $critical = (clone $stockHealthQuery)
                ->whereRaw('product_location.current_stock < (product_location.minimum_stock * 0.5)')
                ->whereRaw('product_location.current_stock > 0')
                ->count();

            $out = (clone $stockHealthQuery)
                ->where('product_location.current_stock', '<=', 0)
                ->count();

            // Conteos reales por estado. El frontend calcula los porcentajes
            // a partir de estos valores (value / total * 100).
            $stockHealth = [
                'optimal' => $optimal,
                'low' => $low,
                'critical' => $critical,
                'out' => $out,
            ];
        } else {
            $stockHealth = [
                'optimal' => 0,
                'low' => 0,
                'critical' => 0,
                'out' => 0,
            ];
        }

        return response()->json([
            'data' => [
                'total_products' => $totalProducts,
                'total_stock' => round($totalStock, 2),
                'inventory_value' => round($inventoryValue, 2),
                'low_stock_products' => $lowStockProducts,
                'out_of_stock_products' => $outOfStockProducts,
                'products_by_category' => $productsByCategory,
                'stock_health' => $stockHealth,
            ]
        ]);
    }

    /**
     * 'general' agrega todas las sucursales de la empresa; solo los
     * administradores pueden pedirlo. A un usuario básico se le limita a su
     * sucursal aunque la app mande scope=general.
     */
    /**
     * Get transfer statistics
     */
    public function transferStats(Request $request)
    {
        if (!CurrentWorker::hasPermission('reports_view')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        // Determinar el período
        $period = $request->get('period', 'month'); // 'today', 'week', 'month', 'year'
        $dateRange = $this->getDateRange($period);

        $baseQuery = InventoryTransfer::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', $dateRange)
            ->when($locationId, function ($query) use ($locationId) {
                $query->where(function ($q) use ($locationId) {
                    $q->where('from_location_id', $locationId)
                        ->orWhere('to_location_id', $locationId);
                });
            });

        // Total de transferencias en el período
        $totalTransfers = (clone $baseQuery)->count();

        // Transferencias enviadas
        $transfersSent = (clone $baseQuery)
            ->when($locationId, function ($query) use ($locationId) {
                $query->where('from_location_id', $locationId);
            }, function ($query) {
                $query->whereNotNull('from_location_id');
            })
            ->count();

        // Transferencias recibidas
        $transfersReceived = (clone $baseQuery)
            ->when($locationId, function ($query) use ($locationId) {
                $query->where('to_location_id', $locationId);
            }, function ($query) {
                $query->whereNotNull('to_location_id');
            })
            ->count();

        // Transferencias por estado
        $transfersByStatus = (clone $baseQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                $status = $item->status;
                if ($status instanceof \BackedEnum) {
                    $status = $status->value;
                }

                return [(string) $status => (int) $item->count];
            });

        // Productos más transferidos
        $topProducts = InventoryTransferDetail::query()
            ->join('inventory_transfers', 'inventory_transfer_details.transfer_id', '=', 'inventory_transfers.id')
            ->join('products', 'inventory_transfer_details.product_id', '=', 'products.id')
            ->where('inventory_transfers.company_id', $companyId)
            ->whereBetween('inventory_transfers.created_at', $dateRange)
            ->when($locationId, function ($query) use ($locationId) {
                $query->where(function ($q) use ($locationId) {
                    $q->where('inventory_transfers.from_location_id', $locationId)
                        ->orWhere('inventory_transfers.to_location_id', $locationId);
                });
            })
            ->select(
                'products.id',
                'products.name',
                'products.code',
                DB::raw('SUM(COALESCE(inventory_transfer_details.quantity_shipped, inventory_transfer_details.quantity_requested, 0)) as total_quantity'),
                DB::raw('COUNT(DISTINCT inventory_transfers.id) as transfer_count')
            )
            ->groupBy('products.id', 'products.name', 'products.code')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        // Ubicaciones con más transferencias
        $originLocationCounts = (clone $baseQuery)
            ->select(
                'from_location_id as location_id',
                DB::raw('COUNT(*) as transfer_count')
            )
            ->whereNotNull('from_location_id')
            ->groupBy('from_location_id')
            ->get();

        $destinationLocationCounts = (clone $baseQuery)
            ->select(
                'to_location_id as location_id',
                DB::raw('COUNT(*) as transfer_count')
            )
            ->whereNotNull('to_location_id')
            ->groupBy('to_location_id')
            ->get();

        $locationTotals = collect();

        foreach ($originLocationCounts as $row) {
            $id = (int) $row->location_id;
            $locationTotals[$id] = (int) ($locationTotals[$id] ?? 0) + (int) $row->transfer_count;
        }

        foreach ($destinationLocationCounts as $row) {
            $id = (int) $row->location_id;
            $locationTotals[$id] = (int) ($locationTotals[$id] ?? 0) + (int) $row->transfer_count;
        }

        $locationNames = DB::table('locations')
            ->whereIn('id', $locationTotals->keys()->all())
            ->pluck('name', 'id');

        $topLocations = $locationTotals
            ->map(function ($count, $locationId) use ($locationNames) {
                return [
                    'location_name' => $locationNames[$locationId] ?? ('Ubicación #' . $locationId),
                    'transfer_count' => (int) $count,
                ];
            })
            ->sortByDesc('transfer_count')
            ->take(5)
            ->values();

        // Transferencias por período (últimos 7 días o 12 meses según el período)
        if ($period === 'today' || $period === 'week') {
            // Últimos 7 días
            $transfersByPeriod = InventoryTransfer::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [now()->subDays(7), now()])
                ->when($locationId, function ($query) use ($locationId) {
                    $query->where(function ($q) use ($locationId) {
                        $q->where('from_location_id', $locationId)
                            ->orWhere('to_location_id', $locationId);
                    });
                })
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } else {
            // Últimos 12 meses
            $transfersByPeriod = InventoryTransfer::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [now()->subMonths(12), now()])
                ->when($locationId, function ($query) use ($locationId) {
                    $query->where(function ($q) use ($locationId) {
                        $q->where('from_location_id', $locationId)
                            ->orWhere('to_location_id', $locationId);
                    });
                })
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get();
        }

        // Tiempo promedio de procesamiento (diferencia entre creación y recepción para completadas)
        $completedTransfers = (clone $baseQuery)
            ->where('status', 'completed')
            ->get(['created_at', 'updated_at', 'content']);

        $avgProcessingTime = $completedTransfers
            ->map(function ($transfer) {
                $receivedAt = data_get($transfer->content, 'step_3.received_at');
                $endAt = $receivedAt ? Carbon::parse($receivedAt) : $transfer->updated_at;

                if (!$transfer->created_at || !$endAt) {
                    return null;
                }

                return max($transfer->created_at->diffInMinutes($endAt) / 60, 0);
            })
            ->reject(fn($value) => $value === null)
            ->avg();

        return response()->json([
            'data' => [
                'total_transfers' => $totalTransfers,
                'transfers_sent' => $transfersSent,
                'transfers_received' => $transfersReceived,
                'transfers_by_status' => $transfersByStatus,
                'top_products' => $topProducts,
                'top_locations' => $topLocations,
                'transfers_by_period' => $transfersByPeriod,
                'avg_processing_time_hours' => round($avgProcessingTime ?? 0, 1),
            ]
        ]);
    }

    private function resolveScope(Request $request): string
    {
        $scope = $request->get('scope', 'location');

        if ($scope === 'general' && !CurrentWorker::canAccessAllLocations()) {
            return 'location';
        }

        return $scope;
    }
}
