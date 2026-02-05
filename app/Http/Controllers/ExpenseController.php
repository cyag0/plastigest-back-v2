<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    /**
     * Display a listing of expenses.
     */
    public function index(Request $request)
    {
        $companyId = CurrentCompany::id();
        $locationId = CurrentLocation::id();

        $query = Expense::with(['user', 'location'])
            ->where('company_id', $companyId);

        // Filtrar por location si está presente
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        // Filtrar por fecha
        if ($request->has('expense_date')) {
            $query->whereDate('expense_date', $request->expense_date);
        }

        // Filtrar por rango de fechas (acepta ambas nomenclaturas)
        $startDate = $request->input('start_date') ?? $request->input('date_range_from');
        $endDate = $request->input('end_date') ?? $request->input('date_range_to');
        
        if ($startDate && $endDate) {
            $query->whereBetween('expense_date', [$startDate, $endDate]);
        }

        // Filtrar por categoría
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filtrar por método de pago
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Búsqueda por descripción
        if ($request->has('search')) {
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
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|in:' . implode(',', array_keys(Expense::getCategories())),
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:' . implode(',', array_keys(Expense::getPaymentMethods())),
            'description' => 'required|string|max:1000',
            'expense_date' => 'required|date',
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
        $data['company_id'] = $companyId;
        $data['location_id'] = $locationId;
        $data['user_id'] = Auth::id();

        // Manejar imagen de recibo si existe
        if ($request->hasFile('receipt_image')) {
            $data['receipt_image'] = $request->file('receipt_image')
                ->store('expenses/receipts', 'public');
        }

        $expense = Expense::create($data);
        $expense->load(['user', 'location']);

        return new ExpenseResource($expense);
    }

    /**
     * Display the specified expense.
     */
    public function show($id)
    {
        $companyId = CurrentCompany::id();

        $expense = Expense::with(['user', 'location', 'company'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return new ExpenseResource($expense);
    }

    /**
     * Update the specified expense.
     */
    public function update(Request $request, $id)
    {
        $companyId = CurrentCompany::id();

        $expense = Expense::where('company_id', $companyId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|string|in:' . implode(',', array_keys(Expense::getCategories())),
            'amount' => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|string|in:' . implode(',', array_keys(Expense::getPaymentMethods())),
            'description' => 'sometimes|string|max:1000',
            'expense_date' => 'sometimes|date',
            'receipt_image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Manejar nueva imagen de recibo
        if ($request->hasFile('receipt_image')) {
            // Eliminar imagen anterior si existe
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }
            $data['receipt_image'] = $request->file('receipt_image')
                ->store('expenses/receipts', 'public');
        }

        $expense->update($data);
        $expense->load(['user', 'location']);

        return new ExpenseResource($expense);
    }

    /**
     * Remove the specified expense.
     */
    public function destroy($id)
    {
        $companyId = CurrentCompany::id();

        $expense = Expense::where('company_id', $companyId)->findOrFail($id);

        // Eliminar imagen de recibo si existe
        if ($expense->receipt_image) {
            Storage::disk('public')->delete($expense->receipt_image);
        }

        $expense->delete();

        return response()->json([
            'message' => 'Gasto eliminado correctamente'
        ]);
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
