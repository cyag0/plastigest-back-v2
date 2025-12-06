<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Movement;
use App\Models\Sale;
use App\Models\Purchase;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        // Determinar el scope (location o general)
        $scope = $request->get('scope', 'location'); // 'location' o 'general'

        // Determinar el período
        $period = $request->get('period', 'month'); // 'today', 'week', 'month'

        // Calcular fechas según el período
        $dateRange = $this->getDateRange($period);

        // Total de productos
        $totalProducts = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->count();

        // Total de stock actual (suma de current_stock en product_location)
        $totalStockQuery = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId);

        if ($scope === 'location' && $locationId) {
            $totalStockQuery->where('product_location.location_id', $locationId);
        }

        $totalStock = $totalStockQuery->sum('product_location.current_stock');

        // Valor total del inventario (current_stock * purchase_price)
        $inventoryValue = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->sum(DB::raw('product_location.current_stock * products.purchase_price'));

        // Conteo de movimientos en el período
        $movementsCount = Movement::where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->count();

        // Ventas en el período (total_cost de sales)
        $sales = Sale::where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->sum('total_cost');

        // Compras en el período (total_cost de purchases)
        $purchases = Purchase::where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->sum('total_cost');

        // Ganancia estimada (ventas - compras)
        $profit = $sales - $purchases;

        // Productos con stock bajo (current_stock < minimum_stock)
        $lowStockProducts = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->whereRaw('product_location.current_stock < product_location.minimum_stock')
            ->count();

        return response()->json([
            'data' => [
                'total_products' => $totalProducts,
                'total_stock' => round($totalStock, 2),
                'inventory_value' => round($inventoryValue, 2),
                'movements_count' => $movementsCount,
                'sales' => round($sales, 2),
                'purchases' => round($purchases, 2),
                'profit' => round($profit, 2),
                'low_stock_products' => $lowStockProducts,
            ]
        ]);
    }

    /**
     * Get recent movements
     */
    public function recentMovements(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $limit = $request->get('limit', 10);

        $movements = Movement::with(['location', 'user', 'details'])
            ->where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->orderBy('movement_date', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($movement) {
                $content = is_string($movement->content)
                    ? json_decode($movement->content, true)
                    : $movement->content;

                return [
                    'id' => $movement->id,
                    'movement_type' => $movement->movement_type,
                    'movement_type_label' => $this->getMovementTypeLabel($movement->movement_type),
                    'movement_reason' => $movement->movement_reason,
                    'movement_reason_label' => $this->getMovementReasonLabel($movement->movement_reason),
                    'movement_date' => $movement->movement_date?->toDateString(),
                    'total_cost' => $movement->total_cost,
                    'status' => $movement->status,
                    'location' => $movement->location ? [
                        'id' => $movement->location->id,
                        'name' => $movement->location->name,
                    ] : null,
                    'products_count' => $movement->details->count(),
                    'money_type' => $this->getMoneyType($movement->movement_type, $movement->movement_reason),
                    'generated_money' => $this->generatedMoney($movement->movement_type, $movement->movement_reason),
                    'document_number' => $movement->document_number,
                    'payment_method' => $content['payment_method'] ?? null,
                ];
            });

        return response()->json([
            'data' => $movements
        ]);
    }

    /**
     * Get movements grouped by type
     */
    public function movementsByType(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $period = $request->get('period', 'month');

        $dateRange = $this->getDateRange($period);

        $movements = Movement::select('movement_type', DB::raw('count(*) as total'))
            ->where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->groupBy('movement_type')
            ->get()
            ->pluck('total', 'movement_type')
            ->toArray();

        return response()->json([
            'data' => [
                'entry' => $movements['entry'] ?? 0,
                'exit' => $movements['exit'] ?? 0,
                'production' => $movements['production'] ?? 0,
                'adjustment' => $movements['adjustment'] ?? 0,
                'transfer' => $movements['transfer'] ?? 0,
            ]
        ]);
    }

    /**
     * Get top selling products
     */
    public function topProducts(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $period = $request->get('period', 'month');
        $limit = $request->get('limit', 5);

        $dateRange = $this->getDateRange($period);

        $topProducts = DB::table('movements_details')
            ->join('movements', 'movements_details.movement_id', '=', 'movements.id')
            ->join('products', 'movements_details.product_id', '=', 'products.id')
            ->where('movements.company_id', $companyId)
            ->where('movements.movement_type', 'exit')
            ->where('movements.movement_reason', 'sale')
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('movements.location_origin_id', $locationId);
            })
            ->whereBetween('movements.movement_date', $dateRange)
            ->select(
                'products.name',
                DB::raw('SUM(movements_details.total_cost) as sales'),
                DB::raw('SUM(movements_details.quantity) as quantity')
            )
            ->groupBy('products.id', 'products.name')
            ->orderBy('sales', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $topProducts
        ]);
    }

    /**
     * Get sales trend data
     */
    public function salesTrend(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $period = $request->get('period', 'month'); // 'today', 'week', 'month'

        $groupBy = $this->getSalesTrendGrouping($period);
        $dateRange = $this->getDateRange($period);

        $salesData = Sale::select(
            DB::raw($groupBy . ' as period'),
            DB::raw('SUM(total_cost) as total')
        )
            ->where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return response()->json([
            'data' => $salesData
        ]);
    }

    /**
     * Get sales by location
     */
    public function salesByLocation(Request $request)
    {
        $companyId = CurrentCompany::id();
        $period = $request->get('period', 'month');

        $dateRange = $this->getDateRange($period);

        $salesByLocation = Sale::select(
            'locations.name',
            DB::raw('SUM(movements.total_cost) as value')
        )
            ->join('locations', 'movements.location_origin_id', '=', 'locations.id')
            ->where('movements.company_id', $companyId)
            ->whereBetween('movements.movement_date', $dateRange)
            ->groupBy('locations.id', 'locations.name')
            ->orderBy('value', 'desc')
            ->get();

        return response()->json([
            'data' => $salesByLocation
        ]);
    }

    /**
     * Get low stock products details
     */
    public function lowStockProducts(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $limit = $request->get('limit', 5);

        $lowStock = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('product_location.location_id', $locationId);
            })
            ->whereRaw('product_location.current_stock < product_location.minimum_stock')
            ->select(
                'products.name',
                'product_location.current_stock as current',
                'product_location.minimum_stock as min',
                DB::raw('CASE
                    WHEN product_location.minimum_stock > 0
                    THEN product_location.current_stock / product_location.minimum_stock
                    ELSE 0
                END as percentage')
            )
            ->orderBy('percentage', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $lowStock
        ]);
    }

    /**
     * Get payment methods statistics from content field
     */
    public function paymentMethods(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');
        $period = $request->get('period', 'month');

        $dateRange = $this->getDateRange($period);

        // Obtener ventas con su contenido JSON
        $sales = Sale::where('company_id', $companyId)
            ->when($scope === 'location' && $locationId, function ($query) use ($locationId) {
                $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', $dateRange)
            ->get();

        // Agrupar por método de pago
        $paymentMethodsStats = [];

        foreach ($sales as $sale) {
            $content = is_string($sale->content)
                ? json_decode($sale->content, true)
                : $sale->content;

            $paymentMethod = $content['payment_method'] ?? 'sin_especificar';

            if (!isset($paymentMethodsStats[$paymentMethod])) {
                $paymentMethodsStats[$paymentMethod] = [
                    'name' => ucfirst($paymentMethod),
                    'value' => 0,
                ];
            }

            $paymentMethodsStats[$paymentMethod]['value'] += $sale->total_cost;
        }

        // Asignar colores según el tipo
        $colors = [
            'efectivo' => '#809671', // Matcha (success)
            'transferencia' => '#809671', // Matcha (primary)
            'tarjeta' => '#E5D2B8', // Vanilla (info)
            'sin_especificar' => '#725C3A', // Carob (secondary)
        ];

        foreach ($paymentMethodsStats as $key => $stat) {
            $paymentMethodsStats[$key]['color'] = $colors[$key] ?? '#725C3A';
        }

        return response()->json([
            'data' => array_values($paymentMethodsStats)
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

    private function getSalesTrendGrouping($period)
    {
        return match ($period) {
            'today' => "DATE_FORMAT(movement_date, '%H:00')",
            'week' => "DAYNAME(movement_date)",
            'month' => "WEEK(movement_date, 1)",
            default => "DATE(movement_date)"
        };
    }

    private function getMovementTypeLabel($type)
    {
        return match ($type) {
            'entry' => 'Entrada',
            'exit' => 'Salida',
            'production' => 'Producción',
            'adjustment' => 'Ajuste',
            'transfer' => 'Transferencia',
            default => ucfirst($type)
        };
    }

    private function getMovementReasonLabel($reason)
    {
        return match ($reason) {
            'purchase' => 'Compra',
            'sale' => 'Venta',
            'return' => 'Devolución',
            'production' => 'Producción',
            'adjustment_positive' => 'Ajuste Positivo',
            'adjustment_negative' => 'Ajuste Negativo',
            'internal_consumption' => 'Consumo Interno',
            'transfer' => 'Transferencia',
            default => ucfirst(str_replace('_', ' ', $reason))
        };
    }

    private function getMoneyType($movementType, $movementReason)
    {
        if ($movementReason === 'sale') {
            return 'income';
        }

        if ($movementReason === 'purchase') {
            return 'expense';
        }

        return 'none';
    }

    private function generatedMoney($movementType, $movementReason)
    {
        return in_array($movementReason, ['sale', 'purchase']);
    }

    /**
     * Get inventory statistics
     */
    public function inventoryStats(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();
        $scope = $request->get('scope', 'location');

        // Total de productos
        $totalProducts = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->count();

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
            ->whereRaw('product_location.current_stock > 0')
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
            ->where('products.is_active', true)
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
            $optimal = (clone $stockHealthQuery)
                ->whereRaw('product_location.current_stock >= product_location.minimum_stock')
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

            $stockHealth = [
                'optimal' => round(($optimal / $totalProductsInLocation) * 100, 0),
                'low' => round(($low / $totalProductsInLocation) * 100, 0),
                'critical' => round(($critical / $totalProductsInLocation) * 100, 0),
                'out' => round(($out / $totalProductsInLocation) * 100, 0),
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
}
