<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use App\Models\Movement;
use App\Models\InventoryTransfer;
use App\Models\ProductKardex;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Process inventory movement (entry, exit, adjustment)
     */
    public function processMovement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'required|exists:locations,id',
            'movement_type' => 'required|in:entry,exit,adjustment',
            'movement_reason' => 'required|string|max:100',
            'document_number' => 'nullable|string|max:100',
            'movement_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.unit_cost' => 'nullable|numeric|min:0',
            'products.*.batch_number' => 'nullable|string|max:50',
            'products.*.expiry_date' => 'nullable|date',
            'products.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $movement = $this->inventoryService->processMovement($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Movement processed successfully',
                'data' => $movement
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing movement: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get movements list
     */
    public function getMovements(Request $request): JsonResponse
    {
        $query = Movement::with(['details.product', 'location', 'user']);

        // Apply filters
        if ($request->has('company_id')) {
            $query->byCompany($request->company_id);
        }

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('movement_type')) {
            $query->byType($request->movement_type);
        }

        if ($request->has('start_date')) {
            $query->where('movement_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('movement_date', '<=', $request->end_date);
        }

        $movements = $query->orderBy('movement_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $movements
        ]);
    }

    /**
     * Get specific movement
     */
    public function getMovement(int $id): JsonResponse
    {
        $movement = Movement::with(['details.product', 'location', 'user'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $movement
        ]);
    }

    /**
     * Create inventory transfer
     */
    public function createTransfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id|different:origin_location_id',
            'transfer_type' => 'nullable|in:internal,external',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.unit_cost' => 'nullable|numeric|min:0',
            'products.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transfer = $this->inventoryService->createTransfer($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Transfer created successfully',
                'data' => $transfer
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating transfer: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Approve transfer
     */
    public function approveTransfer(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'approvals' => 'required|array',
            'approvals.*' => 'numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transfer = $this->inventoryService->approveTransfer($id, $request->approvals);

            return response()->json([
                'success' => true,
                'message' => 'Transfer approved successfully',
                'data' => $transfer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error approving transfer: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Confirm transfer
     */
    public function confirmTransfer(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'confirmations' => 'required|array',
            'confirmations.*' => 'numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transfer = $this->inventoryService->confirmTransfer($id, $request->confirmations);

            return response()->json([
                'success' => true,
                'message' => 'Transfer confirmed successfully',
                'data' => $transfer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error confirming transfer: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get transfers list
     */
    public function getTransfers(Request $request): JsonResponse
    {
        $query = InventoryTransfer::with(['details.product', 'originLocation', 'destinationLocation', 'requestedBy']);

        // Apply filters
        if ($request->has('company_id')) {
            $query->byCompany($request->company_id);
        }

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('origin_location_id')) {
            $query->where('origin_location_id', $request->origin_location_id);
        }

        if ($request->has('destination_location_id')) {
            $query->where('destination_location_id', $request->destination_location_id);
        }

        $transfers = $query->orderBy('requested_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $transfers
        ]);
    }

    /**
     * Get specific transfer
     */
    public function getTransfer(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::with([
            'details.product',
            'originLocation',
            'destinationLocation',
            'requestedBy',
            'approvedBy',
            'confirmedBy'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $transfer
        ]);
    }

    /**
     * Get current stock for a product at a location
     */
    public function getCurrentStock(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'location_id' => 'required|exists:locations,id',
            'company_id' => 'required|exists:companies,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $stock = $this->inventoryService->getCurrentStock(
            $request->product_id,
            $request->location_id,
            $request->company_id
        );

        return response()->json([
            'success' => true,
            'data' => ['current_stock' => $stock]
        ]);
    }

    /**
     * Get inventory report for a location
     */
    public function getInventoryReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|exists:locations,id',
            'company_id' => 'required|exists:companies,id',
            'category_id' => 'nullable|exists:categories,id',
            'low_stock' => 'nullable|boolean',
            'out_of_stock' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $report = $this->inventoryService->getInventoryReport(
            $request->location_id,
            $request->company_id,
            $request->only(['category_id', 'low_stock', 'out_of_stock'])
        );

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }

    /**
     * Get kardex report for a product
     */
    public function getKardexReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'location_id' => 'required|exists:locations,id',
            'company_id' => 'required|exists:companies,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'operation_type' => 'nullable|in:entry,exit,adjustment'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $kardex = $this->inventoryService->getKardexReport(
            $request->product_id,
            $request->location_id,
            $request->company_id,
            $request->only(['start_date', 'end_date', 'operation_type'])
        );

        return response()->json([
            'success' => true,
            'data' => $kardex
        ]);
    }

    /**
     * Get inventory dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // TODO: Implement dashboard statistics logic
        // This could include:
        // - Total products in stock
        // - Low stock alerts
        // - Recent movements
        // - Top products by movement
        // - Stock value by category

        return response()->json([
            'success' => true,
            'message' => 'Dashboard stats - To be implemented',
            'data' => []
        ]);
    }
}
