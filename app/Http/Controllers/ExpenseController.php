<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;
use App\Services\CashMovementService;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    private const MONEY_EDIT = 'cambiar el monto, el método de pago, la fecha ni "pagado con caja"';

    /**
     * Display a listing of expenses.
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('expenses_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        $query = Expense::with(['user', 'location'])
            ->where('company_id', $companyId);

        // Filtrar por location si está presente
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        // Filtrar por fecha (expense_date es DATE: comparación directa, que
        // usa el índice; whereDate() lo envolvía en DATE() y no lo usaba).
        $request->validate(['expense_date' => 'nullable|date']);
        if ($request->filled('expense_date')) {
            $query->where('expense_date', Carbon::parse($request->expense_date)->toDateString());
        }

        // Filtrar por rango de fechas (acepta ambas nomenclaturas)
        $startDate = $request->input('start_date') ?? $request->input('date_range_from');
        $endDate = $request->input('end_date') ?? $request->input('date_range_to');

        if ($startDate && $endDate) {
            $query->whereBetween('expense_date', [$startDate, $endDate]);
        }

        // Filtros con valor: "Todas" llega vacío (?category=) y con has() se
        // filtraba por categoría NULL, así que la lista salía vacía.
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filtrar por método de pago
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Búsqueda por descripción
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        // Ordenar por fecha descendente
        $query->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc');

        // Paginación
        $perPage = $request->get('per_page', 15);
        $expenses = $query->paginate($perPage);

        return ExpenseResource::collection($expenses);
    }

    /**
     * Store a newly created expense.
     */
    public function store(Request $request)
    {
        if (!CurrentWorker::hasPermission('expenses_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Llega como "true"/"false" (FormData) o 1/0; boolean() acepta todos.
        if ($request->filled('paid_from_cash')) {
            $request->merge(['paid_from_cash' => $request->boolean('paid_from_cash')]);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'required|string|in:' . implode(',', array_keys(Expense::getCategories())),
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:' . implode(',', array_keys(Expense::getPaymentMethods())),
            'description' => 'required|string|max:1000',
            'expense_date' => 'required|date',
            'paid_from_cash' => 'nullable|boolean',
            'receipt_image' => 'nullable|image|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        if (!$locationId) {
            return response()->json([
                'message' => 'No se ha seleccionado una ubicación'
            ], 400);
        }

        $data = $validator->validated();
        // Vacío = no se indicó: se queda el default (pagado con dinero de caja).
        if (array_key_exists('paid_from_cash', $data) && $data['paid_from_cash'] === null) {
            unset($data['paid_from_cash']);
        }
        $data['company_id'] = $companyId;
        $data['location_id'] = $locationId;
        $data['user_id'] = Auth::id();

        // Manejar imagen de recibo si existe
        if ($request->hasFile('receipt_image')) {
            $data['receipt_image'] = $request->file('receipt_image')
                ->store('expenses/receipts', 'public');
        }

        $expense = DB::transaction(function () use ($data) {
            $expense = Expense::create($data);
            CashMovementService::syncExpense($expense);
            return $expense;
        });
        $expense->load(['user', 'location']);

        return new ExpenseResource($expense);
    }

    /**
     * Display the specified expense.
     */
    public function show($id)
    {
        if (!CurrentWorker::hasPermission('expenses_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $expense = $this->branchQuery()->with(['user', 'location', 'company'])->findOrFail($id);

        return new ExpenseResource($expense);
    }

    /**
     * Update the specified expense.
     */
    public function update(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('expenses_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $expense = $this->branchQuery()->findOrFail($id);

        // Llega como "true"/"false" (FormData) o 1/0; boolean() acepta todos.
        if ($request->filled('paid_from_cash')) {
            $request->merge(['paid_from_cash' => $request->boolean('paid_from_cash')]);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|string|in:' . implode(',', array_keys(Expense::getCategories())),
            'amount' => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|string|in:' . implode(',', array_keys(Expense::getPaymentMethods())),
            'description' => 'sometimes|string|max:1000',
            'expense_date' => 'sometimes|date',
            'paid_from_cash' => 'nullable|boolean',
            'receipt_image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        if (array_key_exists('paid_from_cash', $data) && $data['paid_from_cash'] === null) {
            unset($data['paid_from_cash']);
        }

        // Solo el monto, el método, la fecha o "pagado con caja" mueven la caja.
        // La app manda el formulario completo: lo que llega igual a lo guardado
        // no cuenta como cambio.
        $moneyChanged = $this->moneyFieldsChanged($expense, $data);
        if ($moneyChanged && CashMovementService::expenseInClosedShift($expense)) {
            return $this->closedShiftException(self::MONEY_EDIT)->render();
        }

        // Manejar nueva imagen de recibo
        if ($request->hasFile('receipt_image')) {
            // Eliminar imagen anterior si existe
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }
            $data['receipt_image'] = $request->file('receipt_image')
                ->store('expenses/receipts', 'public');
        }

        DB::transaction(function () use ($expense, $data, $moneyChanged) {
            // Se vuelve a revisar con el turno bloqueado: un cierre simultáneo
            // espera, o el cambio ve el turno ya cerrado y se rechaza.
            if ($moneyChanged && CashMovementService::expenseInClosedShift($expense, true)) {
                throw $this->closedShiftException(self::MONEY_EDIT);
            }

            $expense->update($data);

            // Antes se borraba y recreaba el egreso en cada edición, aunque solo
            // cambiara la descripción (y aunque su turno ya estuviera cerrado).
            if ($moneyChanged) {
                CashMovementService::syncExpense($expense->fresh());
            }
        });
        $expense->load(['user', 'location']);

        return new ExpenseResource($expense);
    }

    /**
     * Remove the specified expense.
     */
    public function destroy($id)
    {
        if (!CurrentWorker::hasPermission('expenses_delete')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $expense = $this->branchQuery()->findOrFail($id);

        if (CashMovementService::expenseInClosedShift($expense)) {
            return $this->closedShiftException('eliminarlo')->render();
        }

        DB::transaction(function () use ($expense) {
            if (CashMovementService::expenseInClosedShift($expense, true)) {
                throw $this->closedShiftException('eliminarlo');
            }

            CashMovementService::removeExpense($expense);
            $expense->delete();
        });

        // La imagen se borra hasta que el gasto ya no existe.
        if ($expense->receipt_image) {
            Storage::disk('public')->delete($expense->receipt_image);
        }

        return response()->json([
            'message' => 'Gasto eliminado correctamente'
        ]);
    }

    /**
     * Gastos de la empresa y de la sucursal actual (todas, para quien tiene
     * acceso a todas las sucursales). Antes ver, editar o borrar un gasto solo
     * revisaba la empresa: se podía cambiar el de otra sucursal (y su caja).
     */
    private function branchQuery()
    {
        return Expense::query()
            ->where('company_id', CurrentCompany::id())
            ->when(!CurrentWorker::canAccessAllLocations(), fn($query) => $query->where('location_id', CurrentLocation::id()));
    }

    /**
     * ¿La edición cambia algo que mueve la caja? Compara con lo guardado: el
     * monto en centavos, el método, la fecha y "pagado con caja".
     */
    private function moneyFieldsChanged(Expense $expense, array $data): bool
    {
        if (array_key_exists('amount', $data) && Money::toCents($data['amount']) !== Money::toCents($expense->amount)) {
            return true;
        }

        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== $expense->payment_method) {
            return true;
        }

        if (array_key_exists('expense_date', $data)
            && Carbon::parse($data['expense_date'])->toDateString() !== $expense->expense_date?->toDateString()) {
            return true;
        }

        return array_key_exists('paid_from_cash', $data) && (bool) $data['paid_from_cash'] !== (bool) $expense->paid_from_cash;
    }

    /**
     * C15: el egreso del gasto está en un turno de caja ya cerrado. Ese corte
     * ya se contó; la categoría y la descripción sí se pueden corregir.
     */
    private function closedShiftException(string $action): BusinessRuleException
    {
        return new BusinessRuleException(
            "Este gasto ya está en un turno de caja cerrado: no se puede {$action}. Puedes editar la categoría y la descripción.",
            'closed_session',
        );
    }

    /**
     * Get expense categories
     */
    public function categories()
    {
        return response()->json([
            'data' => Expense::getCategories()
        ]);
    }

    /**
     * Get expense statistics
     */
    public function statistics(Request $request)
    {
        if (!CurrentWorker::hasPermission('expenses_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $query = Expense::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate]);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        // Total por categoría
        $byCategory = (clone $query)
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->category => [
                    'label' => Expense::getCategories()[$item->category] ?? $item->category,
                    'total' => (float) $item->total,
                    'count' => $item->count,
                ]];
            });

        // Total por método de pago
        $byPaymentMethod = (clone $query)
            ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->payment_method => [
                    'label' => Expense::getPaymentMethods()[$item->payment_method] ?? $item->payment_method,
                    'total' => (float) $item->total,
                    'count' => $item->count,
                ]];
            });

        // Total general
        $summary = [
            'total_expenses' => (float) $query->sum('amount'),
            'total_count' => $query->count(),
            'average_expense' => (float) $query->avg('amount'),
        ];

        return response()->json([
            'data' => [
                'summary' => $summary,
                'by_category' => $byCategory,
                'by_payment_method' => $byPaymentMethod,
            ]
        ]);
    }
}
