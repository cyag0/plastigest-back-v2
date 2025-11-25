<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Movement;
use Carbon\Carbon;

class ReportsController extends Controller
{
    /**
     * Dashboard con estadísticas generales
     */
    public function dashboard(Request $request)
    {
        $companyId = $request->input('company_id');
        $locationId = $request->input('location_id');
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->input('end_date', Carbon::now()->endOfMonth());

        // Total de productos
        $totalProducts = DB::table('products')
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->count();

        // Total de stock
        $totalStock = DB::table('product_location')
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('location_id', $locationId);
            })
            ->sum('current_stock');

        // Valor total del inventario (usando precio de venta)
        $inventoryValue = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('product_location.location_id', $locationId);
            })
            ->selectRaw('SUM(product_location.current_stock * products.sale_price) as total')
            ->value('total') ?? 0;

        // Movimientos del período
        $movementsCount = Movement::query()
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', [$startDate, $endDate])
            ->count();

        // Ingresos (ventas)
        $sales = Movement::query()
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->where('movement_type', 'exit')
            ->where('movement_reason', 'sale')
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('location_origin_id', $locationId);
            })
            ->whereBetween('movement_date', [$startDate, $endDate])
            ->sum('total_cost') ?? 0;

        // Egresos (compras)
        $purchases = Movement::query()
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->where('movement_type', 'entry')
            ->where('movement_reason', 'purchase')
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('location_destination_id', $locationId);
            })
            ->whereBetween('movement_date', [$startDate, $endDate])
            ->sum('total_cost') ?? 0;

        // Productos con bajo stock
        $lowStockProducts = DB::table('product_location')
            ->join('products', 'product_location.product_id', '=', 'products.id')
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where('product_location.location_id', $locationId);
            })
            ->whereRaw('product_location.current_stock <= product_location.minimum_stock')
            ->count();

        return response()->json([
            'data' => [
                'total_products' => $totalProducts,
                'total_stock' => $totalStock,
                'inventory_value' => $inventoryValue,
                'movements_count' => $movementsCount,
                'sales' => $sales,
                'purchases' => $purchases,
                'profit' => $sales - $purchases,
                'low_stock_products' => $lowStockProducts,
            ]
        ]);
    }

    /**
     * Últimos movimientos
     */
    public function recentMovements(Request $request)
    {
        $companyId = $request->input('company_id');
        $locationId = $request->input('location_id');
        $limit = $request->input('limit', 20);

        $movements = Movement::with([
            'details.product:id,name,code'
        ])
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where(function ($q) use ($locationId) {
                    $q->where('location_origin_id', $locationId)
                        ->orWhere('location_destination_id', $locationId);
                });
            })
            ->orderBy('movement_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $formattedMovements = $movements->map(function ($movement) {
            // Determinar si generó dinero
            $generatedMoney = false;
            $moneyType = 'none'; // none, income, expense

            if (in_array($movement->movement_reason, ['sale'])) {
                $generatedMoney = true;
                $moneyType = 'income';
            } elseif (in_array($movement->movement_reason, ['purchase'])) {
                $generatedMoney = true;
                $moneyType = 'expense';
            }

            // Etiquetas de tipo de movimiento
            $typeLabels = [
                'entry' => 'Entrada',
                'exit' => 'Salida',
                'transfer' => 'Transferencia',
                'adjustment' => 'Ajuste',
                'production' => 'Producción',
            ];

            // Etiquetas de razón
            $reasonLabels = [
                'purchase' => 'Compra',
                'sale' => 'Venta',
                'transfer_in' => 'Transferencia Entrada',
                'transfer_out' => 'Transferencia Salida',
                'adjustment' => 'Ajuste',
                'return' => 'Devolución',
                'damage' => 'Daño',
                'loss' => 'Pérdida',
                'initial' => 'Inicial',
                'production' => 'Producción',
                'shrinkage' => 'Merma',
            ];

            // Obtener la ubicación según el tipo de movimiento
            $location = null;
            if ($movement->location_origin_id) {
                $locationData = DB::table('locations')
                    ->where('id', $movement->location_origin_id)
                    ->first(['id', 'name']);
                if ($locationData) {
                    $location = [
                        'id' => $locationData->id,
                        'name' => $locationData->name,
                    ];
                }
            } elseif ($movement->location_destination_id) {
                $locationData = DB::table('locations')
                    ->where('id', $movement->location_destination_id)
                    ->first(['id', 'name']);
                if ($locationData) {
                    $location = [
                        'id' => $locationData->id,
                        'name' => $locationData->name,
                    ];
                }
            }

            return [
                'id' => $movement->id,
                'movement_type' => $movement->movement_type,
                'movement_type_label' => $typeLabels[$movement->movement_type] ?? $movement->movement_type,
                'movement_reason' => $movement->movement_reason,
                'movement_reason_label' => $reasonLabels[$movement->movement_reason] ?? $movement->movement_reason,
                'movement_date' => $movement->movement_date,
                'total_cost' => $movement->total_cost ?? 0,
                'status' => $movement->status,
                'location' => $location,
                'products_count' => $movement->details->count(),
                'generated_money' => $generatedMoney,
                'money_type' => $moneyType,
                'document_number' => $movement->document_number,
            ];
        });

        return response()->json([
            'data' => $formattedMovements->values()
        ]);
    }

    /**
     * Movimientos por tipo (para gráficas)
     */
    public function movementsByType(Request $request)
    {
        $companyId = $request->input('company_id');
        $locationId = $request->input('location_id');
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->input('end_date', Carbon::now()->endOfMonth());

        $movements = Movement::query()
            ->when($companyId, function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })
            ->when($locationId, function ($query) use ($locationId) {
                return $query->where(function ($q) use ($locationId) {
                    $q->where('location_origin_id', $locationId)
                        ->orWhere('location_destination_id', $locationId);
                });
            })
            ->whereBetween('movement_date', [$startDate, $endDate])
            ->select('movement_type', DB::raw('count(*) as count'))
            ->groupBy('movement_type')
            ->get();

        // Transformar a un objeto con claves
        $result = [
            'entry' => 0,
            'exit' => 0,
            'transfer' => 0,
            'adjustment' => 0,
            'production' => 0,
        ];

        foreach ($movements as $movement) {
            $result[$movement->movement_type] = $movement->count;
        }

        return response()->json([
            'data' => $result
        ]);
    }
}
