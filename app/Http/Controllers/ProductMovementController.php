<?php

namespace App\Http\Controllers;

use App\Enums\StockLedgerReason;
use App\Http\Resources\StockLedgerEntryResource;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trazabilidad de un producto: todo lo que le ha pasado a sus existencias.
 *
 * Lee del libro mayor (`stock_ledger_entries`), que se escribe desde
 * MovementService en cada cambio de stock. No recalcula nada: los saldos de
 * antes y después quedaron grabados en el momento del movimiento, que es lo
 * único que permite auditar de verdad.
 */
class ProductMovementController extends Controller
{
    public function index(Request $request, int $productId): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::get()?->id;

        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
        }

        $product = Product::with('unit')
            ->where('company_id', $companyId)
            ->find($productId);

        if (!$product) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $query = StockLedgerEntry::with(['location', 'counterpartLocation', 'user'])
            ->where('company_id', $companyId)
            ->forProduct($productId);

        if ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->input('location_id'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', (string) $request->input('direction'));
        }

        if ($request->filled('reason')) {
            $reasons = is_array($request->input('reason'))
                ? $request->input('reason')
                : explode(',', (string) $request->input('reason'));

            $query->whereIn('reason', array_filter(array_map('trim', $reasons)));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        // Del más reciente al más viejo. Se desempata por id porque varios
        // movimientos de un mismo guardado comparten timestamp al segundo.
        $entries = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => StockLedgerEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'unit' => $product->unit?->name,
                'unit_abbreviation' => $product->unit?->abbreviation,
                'current_stock' => $this->currentStock($productId, $request->input('location_id')),
            ],
            'filters' => [
                'reasons' => array_map(
                    fn(StockLedgerReason $r) => ['value' => $r->value, 'label' => $r->label()],
                    StockLedgerReason::cases()
                ),
            ],
        ]);
    }

    /**
     * Existencia actual: de una sucursal si se pidió filtrada, o la suma de
     * todas si no.
     */
    private function currentStock(int $productId, mixed $locationId): float
    {
        $query = \Illuminate\Support\Facades\DB::table('product_location')
            ->where('product_id', $productId);

        if ($locationId) {
            $query->where('location_id', (int) $locationId);
        }

        return (float) ($query->sum('current_stock') ?? 0);
    }
}
