<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\InventoryAdjustmentDetail;
use App\Http\Resources\InventoryTransferResource;
use App\Services\TransferService;
use App\Utils\AppUploadUtil;
use App\Enums\TransferStatus;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
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
                'details.product'
            ]);

            // Filtrar por company_id
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            // Filtrar por estado
            if ($request->has('status')) {
                $statusesInput = $request->input('status');
                $statuses = is_array($statusesInput)
                    ? $statusesInput
                    : explode(',', (string) $statusesInput);

                $statuses = array_values(array_filter(array_map(
                    static fn($status) => trim((string) $status),
                    $statuses
                )));

                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
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
                $userLocationId = $request->user_location_id ?? Auth::user()?->location_id;
                if ($userLocationId) {
                    $query->where('from_location_id', $userLocationId);
                }
            }

            // Filtrar recepciones entrantes (hacia mi ubicación)
            if ($request->has('is_incoming') && $request->is_incoming) {
                $userLocationId = $request->user_location_id ?? Auth::user()?->location_id;
                if ($userLocationId) {
                    $query->where('to_location_id', $userLocationId);
                }
            }

            // Filtrar por usuario (si se solicita, mostrar solo las transferencias del usuario actual)
            if ($request->has('my_transfers') && $request->my_transfers) {
                $query->where('requested_by', Auth::id());
            }

            // Filtrar por rango de fechas
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('created_at', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            // Ordenar
            $query->orderBy('created_at', 'desc');

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
            // Obtener compañía actual automáticamente
            $currentCompany = CurrentCompany::get();

            if (!$currentCompany || !$currentCompany->id) {
                Log::error('CurrentCompany is null or has no ID');
                return response()->json([
                    'message' => 'No se pudo obtener la compañía actual',
                    'error' => 'CurrentCompany not set'
                ], 400);
            }

            $companyId = $currentCompany->id;

            // Log temporal para debug - ANTES de la validación
            Log::info('=== TRANSFER STORE STARTED ===');
            Log::info('Company ID from CurrentCompany:', ['company_id' => $companyId]);
            Log::info('Request Data:', $request->all());

            $validated = $request->validate([
                'from_location_id' => 'required|exists:locations,id',
                'to_location_id' => 'required|exists:locations,id|different:from_location_id',
                'notes' => 'nullable|string',
                'details' => 'required|array|min:1',
                'details.*.product_id' => 'required|exists:products,id',
                'details.*.package_id' => 'nullable|exists:product_packages,id',
                'details.*.unit_id' => 'required|exists:units,id',
                'details.*.quantity_requested' => 'required|numeric|min:0.001',
                'details.*.unit_cost' => 'nullable|numeric|min:0',
                'details.*.batch_number' => 'nullable|string|max:50',
                'details.*.expiry_date' => 'nullable|date',
                'details.*.notes' => 'nullable|string',
            ]);

            Log::info('Validation passed successfully');
            Log::info('Validated Data:', $validated);

            // Validar stock disponible en ubicación origen
            $this->validateStockAvailability(
                $validated['details'],
                $validated['from_location_id'],
                $companyId
            );

            DB::beginTransaction();

            // Crear transferencia
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'from_location_id' => $validated['from_location_id'],
                'to_location_id' => $validated['to_location_id'],
                'current_step' => 1,
                'content' => InventoryTransfer::defaultWorkflowContent((int) Auth::id()),
                'requested_by' => Auth::id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Crear detalles
            $totalCost = 0;
            foreach ($validated['details'] as $detailData) {
                $detail = new InventoryTransferDetail([
                    'product_id' => $detailData['product_id'],
                    'package_id' => $detailData['package_id'] ?? null,
                    'unit_id' => $detailData['unit_id'],
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
                'details.product.mainImage'
            ]);

            return response()->json([
                'message' => 'Transferencia creada exitosamente',
                'data' => new InventoryTransferResource($transfer),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('=== VALIDATION ERROR ===');
            Log::error('Validation errors:', $e->errors());
            throw $e; // Re-lanzar para que Laravel lo maneje
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('=== GENERAL ERROR ===');
            Log::error('Error creating transfer: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
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
                'details.product.activePackages',
                'details.product.mainImage',
                'details.package',
                'details.unit'
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

            // Obtener compañía actual automáticamente
            $companyId = CurrentCompany::get()->id;

            $validated = $request->validate([
                'from_location_id' => 'sometimes|exists:locations,id',
                'to_location_id' => 'sometimes|exists:locations,id|different:from_location_id',
                'notes' => 'nullable|string',
                'details' => 'sometimes|array|min:1',
                'details.*.product_id' => 'required|exists:products,id',
                'details.*.package_id' => 'nullable|exists:product_packages,id',
                'details.*.unit_id' => 'required|exists:units,id',
                'details.*.quantity_requested' => 'required|numeric|min:0.001',
                'details.*.unit_cost' => 'nullable|numeric|min:0',
                'details.*.batch_number' => 'nullable|string|max:50',
                'details.*.expiry_date' => 'nullable|date',
                'details.*.notes' => 'nullable|string',
            ]);

            // Validar stock disponible en ubicación origen si se actualizan los detalles
            if (isset($validated['details'])) {
                $this->validateStockAvailability(
                    $validated['details'],
                    $validated['from_location_id'] ?? $transfer->from_location_id,
                    $companyId
                );
            }

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
                        'package_id' => $detailData['package_id'] ?? null,
                        'unit_id' => $detailData['unit_id'],
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
                'details.product.mainImage'
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
            $transfer = InventoryTransfer::with('details')->findOrFail($id);

            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.detail_id' => 'required|exists:inventory_transfer_details,id',
                'items.*.quantity_shipped' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string|max:500',
                'shipping_notes' => 'nullable|string|max:1000',
                'evidence' => 'required|array|min:1',
                'evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            $detailMap = $transfer->details->keyBy('id');
            $shipments = [];
            $auditItems = [];

            foreach ($validated['items'] as $index => $item) {
                $detailId = (int) $item['detail_id'];
                $detail = $detailMap->get($detailId);

                if (!$detail || (int) $detail->transfer_id !== (int) $transfer->id) {
                    return response()->json([
                        'message' => "El detalle {$detailId} no pertenece a esta transferencia",
                    ], 422);
                }

                $requested = (float) $detail->quantity_requested;
                $toShip = (float) $item['quantity_shipped'];

                if ($toShip > $requested) {
                    return response()->json([
                        'message' => "La cantidad enviada no puede superar la solicitada en el detalle {$detailId}",
                    ], 422);
                }

                $shipments[] = [
                    'transfer_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'package_id' => $detail->package_id,
                    'unit_id' => $detail->unit_id,
                    'quantity_shipped' => $toShip,
                    'unit_cost' => (float) ($detail->unit_cost ?? 0),
                    'batch_number' => $detail->batch_number,
                    'expiry_date' => $detail->expiry_date,
                    'notes' => $item['notes'] ?? $detail->notes,
                ];

                $auditItems[] = [
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'quantity_requested' => $requested,
                    'quantity_shipped' => $toShip,
                    'difference' => $requested - $toShip,
                ];
            }

            $this->validateShipmentStock(
                $shipments,
                $transfer->from_location_id,
                $transfer->company_id
            );

            $savedEvidence = $this->saveEvidenceFiles($request, 'evidence', $transfer->id, 'step_2');
            $savedEvidenceNames = array_values(array_map(
                static fn(array $file) => (string) ($file['name'] ?? ''),
                $savedEvidence
            ));
            $savedEvidenceNames = array_values(array_filter($savedEvidenceNames));

            $transfer = $this->transferService->ship($transfer, $shipments);

            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }

            if (!is_array($content['step_2'] ?? null)) {
                $content['step_2'] = [];
            }

            $content['step_2']['evidence'] = $savedEvidenceNames;
            $content['step_2']['evidence_count'] = count($savedEvidenceNames);
            $content['step_2']['shipping_notes'] = $validated['shipping_notes'] ?? null;
            $content['workflow']['step_2'] = [
                'actor_id' => Auth::id(),
                'actor_name' => Auth::user()?->name,
                'at' => now()->toISOString(),
                'items' => $auditItems,
                'evidence' => $savedEvidence,
            ];

            $transfer->content = $content;
            $transfer->save();

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
            $transfer = InventoryTransfer::with('details')->findOrFail($id);

            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.detail_id' => 'required|exists:inventory_transfer_details,id',
                'items.*.quantity_received' => 'required|numeric|min:0',
                'items.*.adjustment_reason' => 'nullable|in:loss,damage',
                'items.*.adjustment_comment' => 'nullable|string|max:500',
                'items.*.adjustment_notes' => 'nullable|string|max:500',
                'items.*.adjustment_evidence' => 'nullable|array',
                'items.*.adjustment_evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
                'receiving_notes' => 'nullable|string|max:1000',
                'evidence' => 'nullable|array',
                'evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            $detailMap = $transfer->details->keyBy('id');
            $received = [];
            $auditItems = [];
            $adjustmentsByReason = [
                'loss' => [],
                'damage' => [],
            ];

            foreach ($validated['items'] as $index => $item) {
                $detailId = (int) $item['detail_id'];
                $detail = $detailMap->get($detailId);

                if (!$detail || (int) $detail->transfer_id !== (int) $transfer->id) {
                    return response()->json([
                        'message' => "El detalle {$detailId} no pertenece a esta transferencia",
                    ], 422);
                }

                $shippedQty = (float) $detail->quantity_shipped;
                if ($shippedQty <= 0) {
                    return response()->json([
                        'message' => "El detalle {$detailId} no tiene cantidad enviada registrada",
                    ], 422);
                }

                $receivedQty = (float) $item['quantity_received'];

                if ($receivedQty > $shippedQty) {
                    return response()->json([
                        'message' => "La cantidad recibida no puede superar la enviada en el detalle {$detailId}",
                    ], 422);
                }

                $difference = $shippedQty - $receivedQty;
                $reason = $item['adjustment_reason'] ?? null;
                $comment = $item['adjustment_comment'] ?? null;
                $notes = $item['adjustment_notes'] ?? null;
                $incidentEvidence = $this->saveEvidenceFiles(
                    $request,
                    "items.{$index}.adjustment_evidence",
                    $transfer->id,
                    "step_3_item_{$detailId}"
                );

                if ($difference > 0 && !$reason) {
                    return response()->json([
                        'message' => "Debes indicar motivo de ajuste (loss/damage) para faltantes en el detalle {$detailId}",
                    ], 422);
                }

                if ($difference > 0 && (!$comment || trim((string) $comment) === '')) {
                    return response()->json([
                        'message' => "Debes indicar que paso en el detalle {$detailId}",
                    ], 422);
                }

                if ($difference > 0 && count($incidentEvidence) === 0) {
                    return response()->json([
                        'message' => "Debes subir evidencia para el detalle {$detailId}",
                    ], 422);
                }

                $received[] = [
                    'detail_id' => $detail->id,
                    'quantity_received' => $receivedQty,
                    'damage_report' => $difference > 0 ? ($notes ?? "Faltante: {$difference}") : null,
                ];

                $auditItems[] = [
                    'detail_id' => $detail->id,
                    'quantity_shipped' => $shippedQty,
                    'quantity_received' => $receivedQty,
                    'difference' => $difference,
                    'adjustment_reason' => $reason,
                    'adjustment_comment' => $comment,
                    'adjustment_notes' => $notes,
                    'adjustment_evidence' => $incidentEvidence,
                ];

                if ($difference > 0 && in_array($reason, ['loss', 'damage'])) {
                    $adjustmentsByReason[$reason][] = [
                        'detail' => $detail,
                        'quantity' => $difference,
                        'comment' => $comment,
                        'notes' => $notes,
                        'evidence' => $incidentEvidence,
                    ];
                }
            }

            $savedEvidence = $this->saveEvidenceFiles($request, 'evidence', $transfer->id, 'step_3');
            $savedEvidenceNames = array_values(array_map(
                static fn(array $file) => (string) ($file['name'] ?? ''),
                $savedEvidence
            ));
            $savedEvidenceNames = array_values(array_filter($savedEvidenceNames));

            $transfer = $this->transferService->receive($transfer, $received);

            $content = $transfer->content;
            if (!is_array($content)) {
                $content = InventoryTransfer::defaultWorkflowContent((int) ($transfer->requested_by ?? 0));
            }

            if (!is_array($content['step_3'] ?? null)) {
                $content['step_3'] = [];
            }

            $content['step_3']['evidence'] = $savedEvidenceNames;
            $content['step_3']['evidence_count'] = count($savedEvidenceNames);
            $content['step_3']['receiving_notes'] = $validated['receiving_notes'] ?? null;

            $createdAdjustments = [];
            foreach ($adjustmentsByReason as $reason => $entries) {
                if (empty($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    /** @var InventoryTransferDetail $detail */
                    $detail = $entry['detail'];
                    $currentStock = (float) (DB::table('product_location')
                        ->where('location_id', $transfer->to_location_id)
                        ->where('product_id', $detail->product_id)
                        ->value('current_stock') ?? 0);

                    // Solo registrar el ajuste; no mutar stock porque la recepción ya maneja existencias.
                    $createdDetail = InventoryAdjustmentDetail::create([
                        'company_id' => $transfer->company_id,
                        'location_id' => $transfer->to_location_id,
                        'created_by' => Auth::id(),
                        'product_id' => $detail->product_id,
                        'direction' => 'out',
                        'quantity' => (float) ($entry['quantity'] ?? 0),
                        'unit_id' => $detail->unit_id,
                        'previous_stock' => $currentStock,
                        'new_stock' => $currentStock,
                        'reason_code' => $reason,
                        'notes' => $entry['notes'] ?? $entry['comment'] ?? null,
                        'content' => [
                            'source' => 'inventory_transfer_receive_difference',
                            'transfer_id' => $transfer->id,
                            'transfer_detail_id' => $detail->id,
                            'adjustment_comment' => $entry['comment'] ?? null,
                            'evidence_files' => $entry['evidence'] ?? [],
                            'created_from_transfer' => true,
                        ],
                        'applied_at' => now(),
                    ]);

                    $createdAdjustments[] = [
                        'id' => $createdDetail->id,
                        'reason' => $reason,
                        'detail_id' => $detail->id,
                        'type' => 'inventory_adjustment_detail',
                    ];

                    foreach ($auditItems as &$auditItem) {
                        if ((int) ($auditItem['detail_id'] ?? 0) === (int) $detail->id) {
                            $auditItem['adjustment_id'] = $createdDetail->id;
                            $auditItem['adjustment_type'] = 'inventory_adjustment_detail';
                        }
                    }
                    unset($auditItem);
                }
            }

            $hasDifferences = collect($auditItems)->contains(fn($item) => (float) $item['difference'] > 0);
            $content['step_3']['has_differences'] = $hasDifferences;
            $content['step_3']['received_complete'] = !$hasDifferences;
            $content['step_3']['received_partial'] = $hasDifferences;
            $content['workflow']['step_3'] = [
                'actor_id' => Auth::id(),
                'actor_name' => Auth::user()?->name,
                'at' => now()->toISOString(),
                'items' => $auditItems,
                'adjustments' => $createdAdjustments,
                'has_differences' => $hasDifferences,
                'evidence' => $savedEvidence,
            ];

            $transfer->content = $content;
            $transfer->save();

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

    /**
     * Validar disponibilidad de stock al crear transferencia
     */
    protected function validateStockAvailability(array $details, int $locationId, int $companyId): void
    {
        foreach ($details as $index => $detail) {
            $productId = $detail['product_id'];
            $packageId = $detail['package_id'] ?? null;
            $unitId = $detail['unit_id'];
            $quantityRequested = $detail['quantity_requested'];

            // Obtener producto para nombre en mensajes de error
            $product = \App\Models\Product::find($productId);

            // Convertir cantidad solicitada a unidad base del producto
            try {
                $quantityInBaseUnits = $this->convertToBaseUnits(
                    $productId,
                    $unitId,
                    $quantityRequested,
                    $packageId
                );
            } catch (Exception $e) {
                throw new Exception(
                    "Error convirtiendo unidades para {$product->name}: {$e->getMessage()}"
                );
            }

            // Obtener stock actual en la ubicación
            $stockRecord = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            $availableStock = $stockRecord ? (float) $stockRecord->current_stock : 0;

            // Validar disponibilidad
            if ($availableStock < $quantityInBaseUnits) {
                $unit = \App\Models\Unit::find($unitId);
                $packageName = $packageId ? \App\Models\ProductPackage::find($packageId)->package_name : null;

                $requestedDisplay = $packageName
                    ? "{$quantityRequested} {$packageName}"
                    : "{$quantityRequested} {$unit->abbreviation}";

                throw new Exception(
                    "Stock insuficiente para {$product->name}. " .
                        "Disponible: {$availableStock} {$product->unit->abbreviation}, " .
                        "Solicitado: {$requestedDisplay} ({$quantityInBaseUnits} {$product->unit->abbreviation})"
                );
            }
        }
    }

    /**
     * Validar stock disponible al enviar (shipment)
     */
    protected function validateShipmentStock(array $shipments, int $locationId, int $companyId): void
    {
        foreach ($shipments as $shipment) {
            $productId = $shipment['product_id'];
            $packageId = $shipment['package_id'] ?? null;
            $unitId = $shipment['unit_id'];
            $quantityShipped = $shipment['quantity_shipped'];

            $product = \App\Models\Product::find($productId);

            // Convertir a unidad base
            $quantityInBaseUnits = $this->convertToBaseUnits(
                $productId,
                $unitId,
                $quantityShipped,
                $packageId
            );

            // Validar stock
            $stockRecord = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            $availableStock = $stockRecord ? (float) $stockRecord->current_stock : 0;

            if ($availableStock < $quantityInBaseUnits) {
                $unit = \App\Models\Unit::find($unitId);
                $packageName = $packageId ? \App\Models\ProductPackage::find($packageId)->package_name : null;

                $shippedDisplay = $packageName
                    ? "{$quantityShipped} {$packageName}"
                    : "{$quantityShipped} {$unit->abbreviation}";

                throw new Exception(
                    "Stock insuficiente para enviar {$product->name}. " .
                        "Disponible: {$availableStock} {$product->unit->abbreviation}, " .
                        "Intentando enviar: {$shippedDisplay} ({$quantityInBaseUnits} {$product->unit->abbreviation})"
                );
            }
        }
    }

    /**
     * Convertir cantidad a unidad base del producto
     * Replica la lógica de MovementService para validaciones
     */
    protected function convertToBaseUnits(int $productId, int $unitId, float $quantity, ?int $packageId): float
    {
        $product = \App\Models\Product::with('unit')->findOrFail($productId);

        if (!$product->unit_id) {
            throw new Exception("El producto no tiene unidad base definida");
        }

        // Si hay paquete, usar quantity_per_package
        if ($packageId) {
            $package = \App\Models\ProductPackage::findOrFail($packageId);

            if ($package->product_id !== $productId) {
                throw new Exception("El paquete no pertenece al producto");
            }

            return $quantity * $package->quantity_per_package;
        }

        // Si no hay paquete, convertir unidades
        if ($unitId === $product->unit_id) {
            return $quantity;
        }

        // Conversión entre unidades
        $fromUnit = \App\Models\Unit::with('baseUnit')->findOrFail($unitId);
        $toUnit = $product->unit;

        $fromBaseUnitId = $fromUnit->base_unit_id ?? $unitId;
        $toBaseUnitId = $toUnit->base_unit_id ?? $product->unit_id;

        if ($fromBaseUnitId !== $toBaseUnitId) {
            throw new Exception("No se pueden convertir unidades de diferentes tipos");
        }

        $fromFactor = $fromUnit->factor_to_base ?? 1;
        $quantityInBaseUnit = $quantity * $fromFactor;

        $toFactor = $toUnit->factor_to_base ?? 1;
        $convertedQuantity = $quantityInBaseUnit / $toFactor;

        return $convertedQuantity;
    }

    /**
     * Guarda evidencia usando AppUploadUtil para transferencias legacy.
     */
    protected function saveEvidenceFiles(Request $request, string $field, int $transferId, string $step): array
    {
        $files = $request->file($field, []);

        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        $saved = [];
        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            $safeExtension = $extension !== '' ? $extension : 'jpg';
            $customName = 'image_' . ($index + 1) . '.' . $safeExtension;
            $stepPath = rtrim(Files::TRANSFER_EVIDENCE_PATH, '/') . '/transfer_' . $transferId . '/' . $step;
            $result = AppUploadUtil::saveFile($file, $stepPath, $customName);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'No se pudo guardar evidencia');
            }

            $saved[] = [
                'name' => basename($result['path']),
                'path' => $result['path'],
                'url' => url('/storage/' . $result['path']),
                'mime_type' => $file->getMimeType(),
            ];
        }

        if (empty($saved)) {
            throw new Exception('Debes adjuntar al menos una evidencia válida');
        }

        return $saved;
    }
}
