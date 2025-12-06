<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Http\Resources\InventoryTransferResource;
use App\Services\TransferService;
use App\Enums\TransferStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class InventoryTransferController extends Controller
{
    protected TransferService $transferService;

    public function __construct(TransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    /**
     * Listar transferencias con filtros
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'approvedByUser',
                'shippedByUser',
                'receivedByUser',
                'details.product'
            ]);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por estado
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filtrar por ubicación de origen
            if ($request->has('from_location_id')) {
                $query->where('from_location_id', $request->from_location_id);
            }

            // Filtrar por ubicación de destino
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
            }

            // Filtrar envíos salientes (desde mi ubicación)
            if ($request->has('is_outgoing') && $request->is_outgoing) {
                $userLocationId = $request->user_location_id ?? auth()->user()->location_id;
                if ($userLocationId) {
                    $query->where('from_location_id', $userLocationId);
                }
            }

            // Filtrar recepciones entrantes (hacia mi ubicación)
            if ($request->has('is_incoming') && $request->is_incoming) {
                $userLocationId = $request->user_location_id ?? auth()->user()->location_id;
                if ($userLocationId) {
                    $query->where('to_location_id', $userLocationId);
                }
            }

            // Filtrar por usuario (si se solicita, mostrar solo las transferencias del usuario actual)
            if ($request->has('my_transfers') && $request->my_transfers) {
                $query->where('requested_by', auth()->id());
            }

            // Filtrar por rango de fechas
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('requested_at', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            // Ordenar
            $query->orderBy('requested_at', 'desc');

            // Paginar o retornar todo
            if ($request->has('per_page')) {
                $transfers = $query->paginate($request->per_page);
                return response()->json([
                    'data' => InventoryTransferResource::collection($transfers->items()),
                    'meta' => [
                        'current_page' => $transfers->currentPage(),
                        'last_page' => $transfers->lastPage(),
                        'per_page' => $transfers->perPage(),
                        'total' => $transfers->total(),
                    ],
                ]);
            }

            $transfers = $query->get();
            return response()->json([
                'data' => InventoryTransferResource::collection($transfers),
            ]);

        } catch (Exception $e) {
            Log::error('Error listing transfers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener las transferencias',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nueva transferencia
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Log temporal para debug
            Log::info('Transfer Store Request Data', $request->all());
            
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'from_location_id' => 'required|exists:locations,id',
                'to_location_id' => 'required|exists:locations,id|different:from_location_id',
                'notes' => 'nullable|string',
                'details' => 'required|array|min:1',
                'details.*.product_id' => 'required|exists:products,id',
                'details.*.quantity_requested' => 'required|numeric|min:0.001',
                'details.*.unit_cost' => 'nullable|numeric|min:0',
                'details.*.batch_number' => 'nullable|string|max:50',
                'details.*.expiry_date' => 'nullable|date',
                'details.*.notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            // Crear transferencia
            $transfer = InventoryTransfer::create([
                'company_id' => $validated['company_id'],
                'from_location_id' => $validated['from_location_id'],
                'to_location_id' => $validated['to_location_id'],
                'requested_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Crear detalles
            $totalCost = 0;
            foreach ($validated['details'] as $detailData) {
                $detail = new InventoryTransferDetail([
                    'product_id' => $detailData['product_id'],
                    'quantity_requested' => $detailData['quantity_requested'],
                    'unit_cost' => $detailData['unit_cost'] ?? 0,
                    'batch_number' => $detailData['batch_number'] ?? null,
                    'expiry_date' => $detailData['expiry_date'] ?? null,
                    'notes' => $detailData['notes'] ?? null,
                ]);
                
                $detail->calculateTotal();
                $transfer->details()->save($detail);
                $totalCost += $detail->total_cost;
            }

            // Actualizar total de la transferencia
            $transfer->total_cost = $totalCost;
            $transfer->save();

            DB::commit();

            // Cargar relaciones para el response
            $transfer->load([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'details.product'
            ]);

            return response()->json([
                'message' => 'Transferencia creada exitosamente',
                'data' => new InventoryTransferResource($transfer),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error creating transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al crear la transferencia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar transferencia específica
     */
    public function show(int $id): JsonResponse
    {
        try {
            $transfer = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'approvedByUser',
                'shippedByUser',
                'receivedByUser',
                'details.product',
                'details.shipments.product'
            ])->findOrFail($id);

            return response()->json([
                'data' => new InventoryTransferResource($transfer),
            ]);

        } catch (Exception $e) {
            Log::error('Error showing transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Transferencia no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar transferencia (solo en estado PENDING)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $transfer = InventoryTransfer::findOrFail($id);

            if ($transfer->status !== TransferStatus::PENDING) {
                return response()->json([
                    'message' => 'Solo se pueden editar transferencias pendientes'
                ], 422);
            }

            $validated = $request->validate([
                'from_location_id' => 'sometimes|exists:locations,id',
                'to_location_id' => 'sometimes|exists:locations,id|different:from_location_id',
                'notes' => 'nullable|string',
                'details' => 'sometimes|array|min:1',
                'details.*.product_id' => 'required|exists:products,id',
                'details.*.quantity_requested' => 'required|numeric|min:0.001',
                'details.*.unit_cost' => 'nullable|numeric|min:0',
                'details.*.batch_number' => 'nullable|string|max:50',
                'details.*.expiry_date' => 'nullable|date',
                'details.*.notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            // Actualizar transferencia
            $transfer->update([
                'from_location_id' => $validated['from_location_id'] ?? $transfer->from_location_id,
                'to_location_id' => $validated['to_location_id'] ?? $transfer->to_location_id,
                'notes' => $validated['notes'] ?? $transfer->notes,
            ]);

            // Si se envían detalles, reemplazarlos
            if (isset($validated['details'])) {
                // Eliminar detalles anteriores
                $transfer->details()->delete();

                // Crear nuevos detalles
                $totalCost = 0;
                foreach ($validated['details'] as $detailData) {
                    $detail = new InventoryTransferDetail([
                        'product_id' => $detailData['product_id'],
                        'quantity_requested' => $detailData['quantity_requested'],
                        'unit_cost' => $detailData['unit_cost'] ?? 0,
                        'batch_number' => $detailData['batch_number'] ?? null,
                        'expiry_date' => $detailData['expiry_date'] ?? null,
                        'notes' => $detailData['notes'] ?? null,
                    ]);
                    
                    $detail->calculateTotal();
                    $transfer->details()->save($detail);
                    $totalCost += $detail->total_cost;
                }

                $transfer->total_cost = $totalCost;
                $transfer->save();
            }

            DB::commit();

            $transfer->load([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'details.product'
            ]);

            return response()->json([
                'message' => 'Transferencia actualizada exitosamente',
                'data' => new InventoryTransferResource($transfer),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error updating transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar la transferencia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar transferencia (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $transfer = InventoryTransfer::findOrFail($id);

            if (!$transfer->status->canCancel()) {
                return response()->json([
                    'message' => 'Esta transferencia no puede ser cancelada'
                ], 422);
            }

            $transfer->cancel('Cancelada por el usuario');

            return response()->json([
                'message' => 'Transferencia cancelada exitosamente',
            ]);

        } catch (Exception $e) {
            Log::error('Error canceling transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al cancelar la transferencia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar requisiciones pendientes de aprobación (para la matriz)
     */
    public function pendingRequests(Request $request): JsonResponse
    {
        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'details.product'
            ])
            ->where('status', TransferStatus::PENDING);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por ubicación de destino (requisiciones de una sucursal específica)
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
            }

            // Ordenar por más recientes
            $query->orderBy('requested_at', 'desc');

            $transfers = $query->get();

            return response()->json([
                'data' => InventoryTransferResource::collection($transfers),
                'meta' => [
                    'total' => $transfers->count(),
                    'message' => 'Requisiciones pendientes de aprobación'
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Error listing pending requests: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener requisiciones pendientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar transferencias en tránsito para recibir (para sucursales)
     */
    public function inTransit(Request $request): JsonResponse
    {
        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'shippedByUser',
                'details.product'
            ])
            ->where('status', TransferStatus::IN_TRANSIT);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por ubicación de destino (transferencias que vienen hacia esta sucursal)
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
            }

            // Ordenar por fecha de envío
            $query->orderBy('shipped_at', 'desc');

            $transfers = $query->get();

            return response()->json([
                'data' => InventoryTransferResource::collection($transfers),
                'meta' => [
                    'total' => $transfers->count(),
                    'message' => 'Transferencias en tránsito para recibir'
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Error listing in-transit transfers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener transferencias en tránsito',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener peticiones pendientes para la ubicación actual
     */
    public function petitions(Request $request): JsonResponse
    {
        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation', 
                'requestedByUser',
                'details.product'
            ])
            ->where('status', TransferStatus::PENDING);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por ubicación de destino si es necesario
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
            }

            $petitions = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'data' => InventoryTransferResource::collection($petitions)
            ]);

        } catch (Exception $e) {
            Log::error('Error getting petitions: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener las peticiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener envíos para la ubicación actual
     */
    public function shipments(Request $request): JsonResponse
    {
        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'approvedByUser',
                'shippedByUser',
                'details.product'
            ])
            ->whereIn('status', [TransferStatus::APPROVED, TransferStatus::IN_TRANSIT, TransferStatus::COMPLETED]);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por ubicación de origen si es necesario
            if ($request->has('from_location_id')) {
                $query->where('from_location_id', $request->from_location_id);
            }

            $shipments = $query->orderBy('updated_at', 'desc')->get();

            return response()->json([
                'data' => InventoryTransferResource::collection($shipments)
            ]);

        } catch (Exception $e) {
            Log::error('Error getting shipments: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener los envíos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener recibos para la ubicación actual
     */
    public function receipts(Request $request): JsonResponse
    {
        try {
            Log::info('📦 Solicitud de recibos recibida', $request->all());
            
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'shippedByUser',
                'receivedByUser',
                'details.product'
            ])
            ->whereIn('status', [TransferStatus::IN_TRANSIT, TransferStatus::COMPLETED, TransferStatus::REJECTED]);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
                Log::info('📊 Filtro por company_id aplicado', ['company_id' => $request->company_id]);
            }

            // Filtrar por ubicación de destino si es necesario
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
                Log::info('📍 Filtro por to_location_id aplicado', ['to_location_id' => $request->to_location_id]);
            }

            $receipts = $query->orderBy('updated_at', 'desc')->get();
            
            Log::info('🧾 Recibos encontrados:', [
                'total' => $receipts->count(),
                'transfers' => $receipts->map(function($transfer) {
                    return [
                        'id' => $transfer->id,
                        'transfer_number' => $transfer->transfer_number,
                        'from_location' => $transfer->fromLocation->name,
                        'to_location' => $transfer->toLocation->name,
                        'status' => $transfer->status->value,
                        'company_id' => $transfer->company_id
                    ];
                })
            ]);

            return response()->json([
                'data' => InventoryTransferResource::collection($receipts)
            ]);

        } catch (Exception $e) {
            Log::error('Error getting receipts: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener los recibos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aprobar transferencia
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $transfer = InventoryTransfer::findOrFail($id);

            $transfer = $this->transferService->approve($transfer, $request->all());

            return response()->json([
                'message' => 'Transferencia aprobada exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);

        } catch (Exception $e) {
            Log::error('Error approving transfer: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Rechazar transferencia
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transfer = InventoryTransfer::findOrFail($id);

            $transfer = $this->transferService->reject($transfer, $request->rejection_reason);

            return response()->json([
                'message' => 'Transferencia rechazada',
                'data' => new InventoryTransferResource($transfer)
            ]);

        } catch (Exception $e) {
            Log::error('Error rejecting transfer: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Enviar transferencia (con productos específicos)
     */
    public function ship(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'shipments' => 'required|array|min:1',
                'shipments.*.transfer_detail_id' => 'required|exists:inventory_transfer_details,id',
                'shipments.*.product_id' => 'required|exists:products,id',
                'shipments.*.quantity_shipped' => 'required|numeric|min:0.001',
                'shipments.*.unit_cost' => 'nullable|numeric|min:0',
                'shipments.*.batch_number' => 'nullable|string|max:100',
                'shipments.*.expiry_date' => 'nullable|date',
                'shipments.*.notes' => 'nullable|string|max:500',
                'package_number' => 'nullable|string|max:100',
                'shipping_evidence' => 'nullable|array',
                'shipping_evidence.*.type' => 'required_with:shipping_evidence|string|in:photo,document,signature',
                'shipping_evidence.*.url' => 'required_with:shipping_evidence|string',
                'shipping_evidence.*.description' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transfer = InventoryTransfer::findOrFail($id);

            $transfer = $this->transferService->ship($transfer, $request->shipments);

            return response()->json([
                'message' => 'Transferencia enviada exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);

        } catch (Exception $e) {
            Log::error('Error shipping transfer: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Recibir transferencia (confirmar cantidades recibidas)
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'received' => 'required|array|min:1',
                'received.*.shipment_id' => 'required|exists:inventory_transfer_shipments,id',
                'received.*.quantity_received' => 'required|numeric|min:0',
                'received.*.damage_report' => 'nullable|string|max:500',
                'received_complete' => 'required|boolean',
                'has_differences' => 'required|boolean',
                'difference_notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transfer = InventoryTransfer::findOrFail($id);

            $transfer = $this->transferService->receive($transfer, $request->received);

            return response()->json([
                'message' => 'Transferencia recibida exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);

        } catch (Exception $e) {
            Log::error('Error receiving transfer: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
