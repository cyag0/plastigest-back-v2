<?php

namespace App\Http\Controllers;

use App\Models\CustomerNote;
use App\Http\Resources\CustomerNoteResource;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerNoteController extends Controller
{
    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
    }

    /**
     * Query base aislada por la empresa del contexto. Antes el company_id venía
     * del cliente, así que se podían leer/editar las notas de otra empresa.
     */
    private function scoped()
    {
        return CustomerNote::query()->where('company_id', CurrentCompany::id());
    }

    /**
     * Obtener todas las notas de un cliente
     */
    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('customers_read')) {
            return $this->forbidden();
        }

        $customerId = $request->input('customer_id');

        $notes = $this->scoped()
            ->when($customerId, fn($query) => $query->where('customer_id', $customerId))
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
        if (!CurrentWorker::hasPermission('customers_create')) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:pending,paid',
            'due_date' => 'nullable|date',
        ]);

        // La empresa sale del contexto, no del cliente.
        $validated['company_id'] = CurrentCompany::id();

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
        if (!CurrentWorker::hasPermission('customers_read')) {
            return $this->forbidden();
        }

        $note = $this->scoped()->findOrFail($id);

        return response()->json([
            'data' => new CustomerNoteResource($note)
        ]);
    }

    /**
     * Actualizar una nota
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('customers_update')) {
            return $this->forbidden();
        }

        $note = $this->scoped()->findOrFail($id);

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
        if (!CurrentWorker::hasPermission('customers_delete')) {
            return $this->forbidden();
        }

        $note = $this->scoped()->findOrFail($id);
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
        if (!CurrentWorker::hasPermission('customers_read')) {
            return $this->forbidden();
        }

        $customerId = $request->input('customer_id');

        $total = $this->scoped()
            ->where('customer_id', $customerId)
            ->where('status', 'pending')
            ->where('is_active', true)
            ->sum('amount');

        return response()->json([
            'total_pending' => $total
        ]);
    }
}
