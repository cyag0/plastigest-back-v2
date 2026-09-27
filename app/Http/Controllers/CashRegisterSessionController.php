<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashRegisterSessionResource;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\CashRegisterSessionService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Turnos de caja.
 *
 * - El cajero (sales_pos) abre, consulta y cierra su propio turno.
 * - Un supervisor (cash_register_sessions_read/list) consulta cualquiera.
 * - Cerrar el turno que otro dejó abierto requiere cash_registers_update.
 */
class CashRegisterSessionController extends Controller
{
    private const RELATIONS = ['cashRegister', 'location', 'user', 'closedBy'];

    public function __construct(private readonly CashRegisterSessionService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('cash_register_sessions_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $query = CashRegisterSession::with(self::RELATIONS)
            ->where('company_id', CurrentCompany::id())
            ->orderByDesc('opened_at');

        if (!CurrentWorker::canAccessAllLocations()) {
            $query->where('location_id', CurrentLocation::id());
        } elseif ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->location_id);
        }

        foreach (['cash_register_id', 'user_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }

        $page = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => CashRegisterSessionResource::collection($page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Turno abierto del usuario (el POS lo consulta al entrar). */
    public function current(): JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $session = $this->service->current(Auth::user());

        return response()->json([
            'data' => $session ? new CashRegisterSessionResource($session->load(self::RELATIONS)) : null,
            'summary' => $session ? $this->service->summary($session) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            'cash_register_id' => 'required|integer',
            'opening_amount' => 'required|numeric|min:0',
        ]);

        // La caja tiene que ser de la sucursal en la que está trabajando.
        $register = CashRegister::where('company_id', CurrentCompany::id())
            ->where('location_id', CurrentLocation::id())
            ->find($validated['cash_register_id']);

        if (!$register) {
            return response()->json([
                'message' => 'La caja no pertenece a esta sucursal.',
                'errors' => ['cash_register_id' => ['La caja no pertenece a esta sucursal.']],
            ], 422);
        }

        $session = $this->service->open($register, (float) $validated['opening_amount'], Auth::user());

        return response()->json([
            'message' => "Turno abierto en {$register->name}",
            'data' => new CashRegisterSessionResource($session->load(self::RELATIONS)),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $session = $this->findForViewing($id);
        if (!$session) {
            return response()->json(['message' => 'Turno no encontrado.'], 404);
        }

        return response()->json([
            'data' => new CashRegisterSessionResource($session),
            // Abierto: totales en vivo. Cerrado: los congelados en `totals`.
            'summary' => $session->isOpen() ? $this->service->summary($session) : $session->totals,
        ]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $session = CashRegisterSession::where('company_id', CurrentCompany::id())->find($id);
        if (!$session) {
            return response()->json(['message' => 'Turno no encontrado.'], 404);
        }

        $own = $session->user_id === Auth::id() && CurrentWorker::hasPermission('sales_pos');
        if (!$own && !CurrentWorker::hasPermission('cash_registers_update')) {
            return response()->json(['message' => 'Solo el cajero del turno o un administrador pueden cerrarlo.'], 403);
        }

        $session = $this->service->close($session, (float) $validated['counted_cash'], $validated['notes'] ?? null, Auth::user());

        return response()->json([
            'message' => 'Turno cerrado',
            'data' => new CashRegisterSessionResource($session->load(self::RELATIONS)),
            'summary' => $session->totals,
        ]);
    }

    /** El propio turno, o cualquiera de la compañía para quien tiene permiso de consulta. */
    private function findForViewing(int $id): ?CashRegisterSession
    {
        $session = CashRegisterSession::with(self::RELATIONS)
            ->where('company_id', CurrentCompany::id())
            ->find($id);

        if (!$session) {
            return null;
        }

        $own = $session->user_id === Auth::id();
        if (!$own && !CurrentWorker::hasPermission('cash_register_sessions_read')) {
            return null;
        }

        return $session;
    }
}
