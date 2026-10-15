<?php

namespace App\Http\Controllers;

use App\Enums\StockLedgerReason;
use App\Http\Resources\StockLedgerEntryResource;
use App\Models\Admin\Location;
use App\Models\StockLedgerEntry;
use App\Support\Activity\ReferenceLabels;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kárdex general: todos los movimientos de inventario de la empresa, con
 * filtros por fecha, sucursal, producto, tipo y usuario.
 *
 * Lee el mismo libro mayor que la trazabilidad de un producto
 * (ProductMovementController); aquí no se limita a un solo producto.
 */
class InventoryMovementController extends Controller
{
    /** Tope de renglones del CSV: más que eso ya no se abre bien en Excel. */
    private const EXPORT_LIMIT = 20000;

    /** Días que muestra el kárdex cuando no se elige un rango de fechas. */
    private const DEFAULT_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = $this->locationScope($request->input('location_id'));
        $filters = $this->withDefaultRange($request->all());

        $query = $this->filtered(StockLedgerEntry::query(), $filters, $companyId, $locationId);

        // Un solo conteo por petición: el total del resumen es también el de
        // la paginación (antes paginate() volvía a contar todo).
        $summaryQuery = (clone $query)->toBase()
            ->selectRaw("COUNT(*) as total, SUM(direction = 'in') as entries_in, SUM(direction = 'out') as entries_out");

        // Con un solo producto las cantidades sí se pueden sumar (misma unidad).
        if ($request->filled('product_id')) {
            $summaryQuery
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity END), 0) as qty_in")
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN quantity END), 0) as qty_out");
        }

        $summary = $summaryQuery->first();
        $total = (int) ($summary->total ?? 0);

        $perPage = (int) $request->input('per_page', 25);
        $perPage = min($perPage > 0 ? $perPage : 25, 100);
        $page = max(1, (int) $request->input('page', 1));

        $items = $total > 0
            ? $query->with(['product.unit', 'location', 'counterpartLocation', 'user'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get()
            : collect();

        $entries = new LengthAwarePaginator($items, $total, $perPage, $page);

        ReferenceLabels::attach($entries->items());

        $productTotals = null;
        if ($request->filled('product_id')) {
            $productTotals = [
                'in' => (float) $summary->qty_in,
                'out' => (float) $summary->qty_out,
                'net' => (float) $summary->qty_in - (float) $summary->qty_out,
            ];
        }

        return response()->json([
            'data' => StockLedgerEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'from' => $entries->firstItem(),
                'to' => $entries->lastItem(),
            ],
            'summary' => [
                'total' => $total,
                'entries_in' => (int) ($summary->entries_in ?? 0),
                'entries_out' => (int) ($summary->entries_out ?? 0),
                'product' => $productTotals,
            ],
            'filters' => $this->filterOptions($companyId),
            // Fechas que se aplicaron; default = true cuando son los últimos
            // DEFAULT_DAYS días porque no se eligió rango.
            'range' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'default' => empty($request->input('date_from')) && empty($request->input('date_to')),
            ],
        ]);
    }

    /**
     * URL firmada (10 min) para descargar el kárdex filtrado en CSV. La
     * empresa y la sucursal permitida van dentro de la firma: la descarga es
     * pública (el navegador no manda el token) y no se pueden alterar.
     */
    public function exportUrl(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Con el mismo rango que la lista: sin fechas, los últimos días.
        $filters = collect($this->withDefaultRange($request->only(['search', 'product_id', 'reason', 'direction', 'user_id', 'date_from', 'date_to'])))
            ->filter(fn($v) => $v !== null && $v !== '' && $v !== [])
            ->map(fn($v) => is_array($v) ? implode(',', $v) : $v)
            ->all();

        $url = URL::temporarySignedRoute('inventory-movements.export', now()->addMinutes(10), [
            ...$filters,
            'company_id' => CurrentCompany::id(),
            'location_id' => $this->locationScope($request->input('location_id')) ?? 'all',
        ]);

        return response()->json(['url' => $url, 'expires_at' => now()->addMinutes(10)->toISOString()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $companyId = (int) $request->query('company_id');
        abort_if(!$companyId, 404);

        $location = $request->query('location_id');
        $locationId = $location === 'all' ? null : (int) $location;

        $query = $this->filtered(StockLedgerEntry::query(), $request->query(), $companyId, $locationId)
            ->with(['product.unit', 'location', 'counterpartLocation', 'user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT);

        $fileName = 'kardex-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel abra los acentos bien.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Sucursal', 'Producto', 'Código', 'Movimiento', 'Entrada', 'Salida', 'Unidad',
                'Existencia anterior', 'Existencia nueva', 'Costo unitario', 'Usuario', 'Documento', 'Otra sucursal', 'Notas']);

            $query->chunk(500, function ($entries) use ($out) {
                ReferenceLabels::attach($entries);
                foreach ($entries as $e) {
                    $in = $e->direction === StockLedgerEntry::DIRECTION_IN;
                    fputcsv($out, [
                        $e->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                        $e->location?->name,
                        $e->product?->name,
                        $e->product?->code,
                        $e->reason->label(),
                        $in ? (float) $e->quantity : '',
                        $in ? '' : (float) $e->quantity,
                        $e->product?->unit?->abbreviation,
                        (float) $e->previous_stock,
                        (float) $e->new_stock,
                        $e->unit_cost !== null ? (float) $e->unit_cost : '',
                        $e->user?->name,
                        $e->reference_label,
                        $e->counterpartLocation?->name,
                        $e->notes,
                    ]);
                }
            });

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Sin fechas, el kárdex muestra los últimos DEFAULT_DAYS días (hoy
     * incluido): antes cada página contaba y sumaba todo el historial, y al
     * abrir la pantalla eso pasaba dos veces (opciones de filtro y lista).
     * Con cualquiera de las dos fechas se respeta lo pedido.
     */
    private function withDefaultRange(array $filters): array
    {
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            $filters['date_from'] = now()->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        }

        return $filters;
    }

    /**
     * Sucursal a la que se limita la consulta: quien no ve todas las
     * sucursales sólo ve la suya, diga lo que diga el filtro.
     */
    private function locationScope(mixed $requested): ?int
    {
        if (!CurrentWorker::canAccessAllLocations()) {
            return CurrentLocation::id();
        }

        return ($requested === null || $requested === '' || $requested === 'all') ? null : (int) $requested;
    }

    private function filtered(Builder $query, array $f, ?int $companyId, ?int $locationId): Builder
    {
        $query->where('company_id', $companyId);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }
        if (!empty($f['product_id'])) {
            $query->where('product_id', (int) $f['product_id']);
        }
        if (!empty($f['search'])) {
            $search = $f['search'];
            $query->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if (!empty($f['reason'])) {
            $reasons = is_array($f['reason']) ? $f['reason'] : explode(',', (string) $f['reason']);
            $query->whereIn('reason', array_filter(array_map('trim', $reasons)));
        }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $query->where('direction', $f['direction']);
        }
        if (!empty($f['user_id'])) {
            $query->where('user_id', (int) $f['user_id']);
        }
        // Las fechas se guardan en la hora de la app (America/Mexico_City), así
        // que el día se compara tal cual, sin pasar a UTC.
        if (!empty($f['date_from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($f['date_from'])->startOfDay()->toDateTimeString());
        }
        if (!empty($f['date_to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($f['date_to'])->endOfDay()->toDateTimeString());
        }

        return $query;
    }

    private function filterOptions(?int $companyId): array
    {
        $users = DB::table('users')
            ->join('user_company', 'user_company.user_id', '=', 'users.id')
            ->where('user_company.company_id', $companyId)
            ->orderBy('users.name')
            ->get(['users.id as value', 'users.name as label']);

        return [
            'reasons' => array_map(
                fn(StockLedgerReason $r) => ['value' => $r->value, 'label' => $r->label()],
                StockLedgerReason::cases()
            ),
            'users' => $users,
            'locations' => CurrentWorker::canAccessAllLocations()
                ? Location::where('company_id', $companyId)->orderBy('name')->get(['id as value', 'name as label'])
                : [],
            'can_choose_location' => CurrentWorker::canAccessAllLocations(),
        ];
    }
}
