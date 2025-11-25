<?php

namespace App\Http\Controllers;

use App\Models\CustomerNote;
use App\Http\Resources\CustomerNoteResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerNoteController extends Controller
{
    /**
     * Obtener todas las notas de un cliente
     */
    public function index(Request $request): JsonResponse
    {
        $customerId = $request->input('customer_id');
        $companyId = $request->input('company_id');

        $notes = CustomerNote::query()
            ->when($customerId, function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            })
            ->when($companyId, function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => CustomerNoteResource::collection($notes)
        ]);
    }

    /**
     * Crear una nueva nota
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:pending,paid',
            'due_date' => 'nullable|date',
            'company_id' => 'required|exists:companies,id',
        ]);

        $note = CustomerNote::create($validated);

        return response()->json([
            'message' => 'Nota creada exitosamente',
            'data' => new CustomerNoteResource($note)
        ], 201);
    }

    /**
     * Obtener una nota específica
     */
    public function show($id): JsonResponse
    {
        $note = CustomerNote::findOrFail($id);

        return response()->json([
            'data' => new CustomerNoteResource($note)
        ]);
    }

    /**
     * Actualizar una nota
     */
    public function update(Request $request, $id): JsonResponse
    {
        $note = CustomerNote::findOrFail($id);

        $validated = $request->validate([
            'description' => 'sometimes|required|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:pending,paid',
            'due_date' => 'nullable|date',
        ]);

        // Solo actualizar los campos validados
        $note->update($validated);

        return response()->json([
            'message' => 'Nota actualizada exitosamente',
            'data' => new CustomerNoteResource($note->fresh())
        ]);
    }

    /**
     * Eliminar una nota (soft delete)
     */
    public function destroy($id): JsonResponse
    {
        $note = CustomerNote::findOrFail($id);
        $note->update(['is_active' => false]);

        return response()->json([
            'message' => 'Nota eliminada exitosamente'
        ]);
    }

    /**
     * Obtener el total pendiente de un cliente
     */
    public function getTotalPending(Request $request): JsonResponse
    {
        $customerId = $request->input('customer_id');
        $companyId = $request->input('company_id');

        $total = CustomerNote::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('is_active', true)
            ->sum('amount');

        return response()->json([
            'total_pending' => $total
        ]);
    }
}
