<?php

namespace App\Http\Controllers;

use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Consulta puntual de existencias.
 *
 * Lo demás que vivía aquí (movimientos, transferencias y reportes sobre la
 * tabla movements) era del sistema anterior: la app usa inventory-transfers,
 * los ajustes y el libro mayor de existencias.
 */
class InventoryController extends Controller
{
    /**
     * Existencia de un producto en una sucursal (GET inventory/stock/current).
     */
    public function getCurrentStock(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'location_id' => 'required|exists:locations,id',
            'company_id' => 'required|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // location_id viene en la consulta: un usuario básico no debe poder
        // ver la existencia de otra sucursal cambiándolo.
        if (!CurrentWorker::canAccessLocation((int) $request->location_id)) {
            return response()->json(['message' => 'No tienes acceso a esta sucursal.'], 403);
        }

        // product_location es la fuente de verdad del stock; sin fila, es 0.
        $stock = (float) (DB::table('product_location')
            ->where('product_id', $request->product_id)
            ->where('location_id', $request->location_id)
            ->value('current_stock') ?? 0);

        return response()->json([
            'success' => true,
            'data' => ['current_stock' => $stock],
        ]);
    }
}
