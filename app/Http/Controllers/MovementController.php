<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use App\Http\Resources\MovementResource;
use App\Models\Adjustment;
use App\Models\MovementDetail;
use App\Models\Transfer;
use App\Models\User;
use App\Utils\AppUploadUtil;
use App\Services\MovementService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovementController extends CrudController
{
    protected string $resource = MovementResource::class;
    protected string $model = Transfer::class;
    protected MovementService $movementService;

    public function __construct(MovementService $movementService)
    {
        $this->movementService = $movementService;
    }

    /**
     * Relaciones a cargar en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company',
            'locationOrigin',
            'locationDestination',
            'user',
            'details.product.mainImage',
            'details.product.unit',
            'details.unit'
        ];
    }

    /**
     * Relaciones a cargar en show
     */
    protected function getShowRelations(): array
    {
        return [
            'company',
            'locationOrigin',
            'locationDestination',
            'supplier',
            'customer',
            'user',
            'details.product.mainImage',
            'details.product.unit',
            'details.unit',
            //'kardexRecords'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        $mode = $params['mode'] ?? null;
        $location = CurrentLocation::get();
        $company = CurrentCompany::get();

        if ($company) {
            $query->where('company_id', $company->id);
        }

        if ($mode === 'petitions') {
            // Solo transferencias solicitadas para mi ubicación
            $query->where('location_destination_id', $location->id)
                ->whereIn('status', ['ordered', 'in_transit', 'rejected']);
            return;
        } else if ($mode === 'shipments') {
            // Solo transferencias enviadas desde mi ubicación
            $query->where('location_origin_id', $location->id)
                ->whereIn('status', ['closed', 'rejected']);
            return;
        } else if ($mode === 'receipts') {
            // Solo transferencias por recibir en mi ubicación
            $query->where('location_origin_id', $location->id)
                ->whereIn('status', ['ordered', 'in_transit']);
            return;
        } else if ($mode === 'transfers') {
            // Solo transferencias recibidas en mi ubicación
            $query->where('location_destination_id', $location->id)
                ->whereIn('status', ['closed', 'rejected']);
            return;
        }

        // Solo movimientos de transferencia
        $query->where('movement_type', 'transfer')
            ->where('movement_reason', 'transfer');

        // Filtro por ubicación de origen
        if (isset($params['from_location_id'])) {
            $query->where('location_origin_id', $params['from_location_id']);
        }

        // Filtro por ubicación de destino
        if (isset($params['to_location_id'])) {
            $query->where('location_destination_id', $params['to_location_id']);
        }

        // Filtro por estado
        if (isset($params['status'])) {
            if (is_array($params['status'])) {
                $query->whereIn('status', $params['status']);
            } else {
                $query->where('status', $params['status']);
            }
        }

        // Filtro por rango de fechas
        if (isset($params['date_from'])) {
            $query->whereDate('movement_date', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('movement_date', '<=', $params['date_to']);
        }

        // Búsqueda en notas
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('notes', 'like', '%' . $params['search'] . '%')
                    ->orWhereRaw('JSON_EXTRACT(content, "$.transfer_number") LIKE ?', ['%' . $params['search'] . '%']);
            });
        }
    }

    /**
     * Validación para crear movimiento/transferencia
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id|different:from_location_id',
            'company_id' => 'required|exists:companies,id',
            'requested_at' => 'required|date',
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity_requested' => 'required|numeric|min:0.001',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.notes' => 'nullable|string',
            'details.*.batch_number' => 'nullable|string',
            'details.*.expiry_date' => 'nullable|date',
        ]);
    }

    /**
     * Validación para actualizar movimiento/transferencia
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        // Solo permitir editar en estado draft u ordered
        if (!in_array($model->status, ['draft', 'ordered'])) {
            abort(422, 'No se puede editar una transferencia en estado: ' . $model->status);
        }

        return $request->validate([
            'from_location_id' => 'sometimes|required|exists:locations,id',
            'to_location_id' => 'sometimes|required|exists:locations,id|different:from_location_id',
            'requested_at' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'details' => 'sometimes|required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity_requested' => 'required|numeric|min:0.001',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.notes' => 'nullable|string',
            'details.*.batch_number' => 'nullable|string',
            'details.*.expiry_date' => 'nullable|date',
        ]);
    }

    public function process($callback, array $data, $method): Model
    {
        $details = $data['details'] ?? [];
        unset($data['details']);

        // Configurar datos del movimiento
        $data['movement_type'] = 'transfer';
        $data['movement_reason'] = 'transfer';
        $data['reference_type'] = 'transfer';
        $data['location_origin_id'] = $data['from_location_id'];
        $data['location_destination_id'] = $data['to_location_id'];
        $data['movement_date'] = $data['requested_at'];
        $data['user_id'] = Auth::id();
        $data['status'] = 'ordered';

        // Generar número de transferencia
        $transferNumber = 'TRANS-' . date('Ymd') . '-' . str_pad(Transfer::count() + 1, 4, '0', STR_PAD_LEFT);

        // Guardar metadata en content
        $data['content'] = [
            'transfer_number' => $transferNumber,
            'requested_by' => Auth::id(),
            'requested_at' => now()->toISOString(),
            'workflow' => [
                'step_1' => [
                    'action' => 'request_created',
                    'actor_id' => Auth::id(),
                    'actor_name' => Auth::user()?->name,
                    'at' => now()->toISOString(),
                ],
            ],
        ];

        $item = $callback($data);

        foreach ($details as $detail) {
            $quantity = $detail['quantity_requested'];
            $unitCost = 0;
            $totalCost = 0;

            $item->details()->create([
                'product_id' => $detail['product_id'],
                'unit_id' => $detail['unit_id'] ?? null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'batch_number' => $detail['batch_number'] ?? null,
                'expiry_date' => $detail['expiry_date'] ?? null,
                'content' => [
                    'quantity_requested' => $quantity,
                    'notes' => $detail['notes'] ?? null,
                ],
            ]);
        }

        return $item;
    }

    /**
     * Procesar datos antes de actualizar
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Extraer detalles si se enviaron
        if (isset($validatedData['details'])) {
            $details = $validatedData['details'];
            unset($validatedData['details']);
            $validatedData['_details'] = $details;
            $validatedData['_update_details'] = true;
        }

        // Actualizar referencias de ubicaciones si cambiaron
        if (isset($validatedData['from_location_id'])) {
            $validatedData['location_origin_id'] = $validatedData['from_location_id'];
        }
        if (isset($validatedData['to_location_id'])) {
            $validatedData['location_destination_id'] = $validatedData['to_location_id'];
        }
        if (isset($validatedData['requested_at'])) {
            $validatedData['movement_date'] = $validatedData['requested_at'];
        }

        return $validatedData;
    }

    /**
     * Acciones después de actualizar
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Si se actualizaron los detalles, reemplazarlos
        if (!empty($model->_update_details)) {
            $model->details()->delete();

            $details = $model->_details ?? [];
            $totalCost = 0;

            foreach ($details as $detail) {
                $quantity = $detail['quantity_requested'];
                $unitCost = $detail['unit_cost'] ?? 0;
                $detailTotal = $quantity * $unitCost;
                $totalCost += $detailTotal;

                $model->details()->create([
                    'product_id' => $detail['product_id'],
                    'unit_id' => $detail['unit_id'] ?? null,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $detailTotal,
                    'batch_number' => $detail['batch_number'] ?? null,
                    'expiry_date' => $detail['expiry_date'] ?? null,
                    'content' => [
                        'quantity_requested' => $quantity,
                        'notes' => $detail['notes'] ?? null,
                    ],
                ]);
            }

            $model->total_cost = $totalCost;
            $model->save();
        }

        unset($model->_details, $model->_update_details);
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // Solo permitir eliminar en estado draft
        if ($model->status !== 'draft') {
            return [
                'can_delete' => false,
                'message' => 'Solo se pueden eliminar transferencias en estado borrador'
            ];
        }

        return [
            'can_delete' => true,
            'message' => null
        ];
    }

    /**
     * ENDPOINTS ESPECÍFICOS PARA MÓDULOS DEL FRONTEND
     */

    /**
     * Peticiones: Transferencias que solicité (location_origin_id = mi ubicación)
     * Estados: ordered, in_transit
     */
    public function petitions(Request $request)
    {
        $locationId = $request->input('location_id') ?? Auth::user()->location_id;

        if (!$locationId) {
            return response()->json([
                'message' => 'No se pudo determinar la ubicación actual',
                'data' => []
            ], 400);
        }

        $query = Transfer::with($this->indexRelations())
            ->petitions($locationId);

        $this->handleQuery($query, $request->all());
        $this->applyOrdering($query, $request->all());

        $results = $this->getResults($query, $request->all());

        return $this->resource::collection($results);
    }

    /**
     * Envíos: Transferencias que solicité y ya fueron completadas o rechazadas
     * Estados: closed, rejected
     */
    public function shipments(Request $request)
    {
        $locationId = $request->input('location_id') ?? Auth::user()->location_id;

        if (!$locationId) {
            return response()->json([
                'message' => 'No se pudo determinar la ubicación actual',
                'data' => []
            ], 400);
        }

        $query = Transfer::with($this->indexRelations())
            ->shipments($locationId);

        $this->handleQuery($query, $request->all());
        $this->applyOrdering($query, $request->all());

        $results = $this->getResults($query, $request->all());

        return $this->resource::collection($results);
    }

    /**
     * Recepciones: Transferencias que recibiré (location_destination_id = mi ubicación)
     * Estados: ordered, in_transit
     */
    public function receipts(Request $request)
    {
        $locationId = $request->input('location_id') ?? Auth::user()->location_id;

        if (!$locationId) {
            return response()->json([
                'message' => 'No se pudo determinar la ubicación actual',
                'data' => []
            ], 400);
        }

        $query = Transfer::with($this->indexRelations())
            ->receipts($locationId);

        $this->handleQuery($query, $request->all());
        $this->applyOrdering($query, $request->all());

        $results = $this->getResults($query, $request->all());

        return $this->resource::collection($results);
    }

    /**
     * Transferencias: Transferencias recibidas completadas o rechazadas (historial)
     * Estados: closed, rejected
     */
    public function transfers(Request $request)
    {
        $locationId = $request->input('location_id') ?? Auth::user()->location_id;

        if (!$locationId) {
            return response()->json([
                'message' => 'No se pudo determinar la ubicación actual',
                'data' => []
            ], 400);
        }

        $query = Transfer::with($this->indexRelations())
            ->transferHistory($locationId);

        $this->handleQuery($query, $request->all());
        $this->applyOrdering($query, $request->all());

        $results = $this->getResults($query, $request->all());

        return $this->resource::collection($results);
    }

    /**
     * ACCIONES DE WORKFLOW
     */

    /**
     * Aprobar transferencia
     */
    public function approve(Request $request, int $id)
    {
        try {
            $movement = Transfer::findOrFail($id);
            $approved = $this->movementService->approve($movement);

            return response()->json([
                'message' => 'Transferencia aprobada exitosamente',
                'data' => new $this->resource($approved->load($this->getShowRelations()))
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al aprobar la transferencia',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Rechazar transferencia
     */
    public function reject(Request $request, int $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string'
            ]);

            $movement = Transfer::findOrFail($id);
            $rejected = $this->movementService->reject($movement, $validated['rejection_reason']);

            return response()->json([
                'message' => 'Transferencia rechazada',
                'data' => new $this->resource($rejected->load($this->getShowRelations()))
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al rechazar la transferencia',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Enviar transferencia
     */
    public function ship(Request $request, int $id)
    {
        try {
            $movement = Transfer::with('details')->findOrFail($id);
            if (!in_array($movement->status, ['ordered', 'approved'])) {
                return response()->json([
                    'message' => 'Solo se puede enviar una transferencia en estado ordered/approved',
                ], 422);
            }

            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.detail_id' => 'required|exists:movements_details,id',
                'items.*.quantity_shipped' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string|max:500',
                'shipping_notes' => 'nullable|string|max:1000',
                'evidence' => 'required|array|min:1',
                'evidence.*' => 'file|max:10240',
            ]);

            $detailMap = $movement->details->keyBy('id');
            $content = $movement->content ?? [];
            $nowIso = now()->toISOString();
            $user = Auth::user();
            $shippedItems = [];

            DB::beginTransaction();

            foreach ($validated['items'] as $item) {
                $detailId = (int) $item['detail_id'];
                $detail = $detailMap->get($detailId);

                if (!$detail || (int) $detail->movement_id !== (int) $movement->id) {
                    throw new \Exception("El detalle {$detailId} no pertenece a la transferencia");
                }

                $detailContent = $detail->content ?? [];
                $requested = (float) ($detailContent['quantity_requested'] ?? $detail->quantity);
                $toShip = (float) $item['quantity_shipped'];

                if ($toShip > $requested) {
                    throw new \Exception("La cantidad enviada no puede superar la solicitada en el detalle {$detailId}");
                }

                if ($toShip > 0) {
                    $this->movementService->decrement(
                        (int) $movement->location_origin_id,
                        (int) $detail->product_id,
                        (int) ($detail->unit_id ?? $detail->product?->unit_id),
                        $toShip
                    );
                }

                $difference = $requested - $toShip;
                $detail->content = array_merge($detailContent, [
                    'quantity_shipped' => $toShip,
                    'has_difference' => $difference > 0,
                    'difference' => $difference,
                    'notes' => $item['notes'] ?? ($detailContent['notes'] ?? null),
                ]);
                $detail->quantity = $toShip;
                $detail->save();

                $shippedItems[] = [
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'quantity_requested' => $requested,
                    'quantity_shipped' => $toShip,
                    'difference' => $difference,
                ];
            }

            $evidence = $this->saveEvidenceFiles($request, 'evidence', "transfer_{$movement->id}_step2");

            $content['shipped_by'] = Auth::id();
            $content['shipped_by_name'] = $user?->name;
            $content['shipped_at'] = $nowIso;
            $content['shipping_notes'] = $validated['shipping_notes'] ?? null;
            $content['shipping_evidence'] = $evidence;
            $content['step_2'] = [
                'actor_id' => Auth::id(),
                'actor_name' => $user?->name,
                'at' => $nowIso,
                'items' => $shippedItems,
            ];
            $content['workflow']['step_2'] = [
                'action' => 'shipment_registered',
                'actor_id' => Auth::id(),
                'actor_name' => $user?->name,
                'at' => $nowIso,
                'items_count' => count($shippedItems),
            ];

            $movement->status = 'in_transit';
            $movement->content = $content;
            $movement->save();

            DB::commit();

            $shipped = $movement;

            return response()->json([
                'message' => 'Transferencia enviada exitosamente',
                'data' => new $this->resource($shipped->load($this->getShowRelations()))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error shipping transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al enviar la transferencia',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Recibir transferencia
     */
    public function receive(Request $request, int $id)
    {
        try {
            $movement = Transfer::with('details')->findOrFail($id);
            if ($movement->status !== 'in_transit') {
                return response()->json([
                    'message' => 'Solo se puede recibir una transferencia en tránsito',
                ], 422);
            }

            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.detail_id' => 'required|exists:movements_details,id',
                'items.*.quantity_received' => 'required|numeric|min:0',
                'items.*.adjustment_reason' => 'nullable|in:loss,damage',
                'items.*.adjustment_notes' => 'nullable|string|max:500',
                'receiving_notes' => 'nullable|string|max:1000',
                'evidence' => 'required|array|min:1',
                'evidence.*' => 'file|max:10240',
            ]);

            $detailMap = $movement->details->keyBy('id');
            $content = $movement->content ?? [];
            $nowIso = now()->toISOString();
            $user = Auth::user();
            $receivedItems = [];
            $adjustmentsByReason = [
                'loss' => [],
                'damage' => [],
            ];

            DB::beginTransaction();

            foreach ($validated['items'] as $item) {
                $detailId = (int) $item['detail_id'];
                $detail = $detailMap->get($detailId);

                if (!$detail || (int) $detail->movement_id !== (int) $movement->id) {
                    throw new \Exception("El detalle {$detailId} no pertenece a la transferencia");
                }

                $detailContent = $detail->content ?? [];
                $shipped = (float) ($detailContent['quantity_shipped'] ?? $detailContent['quantity_requested'] ?? $detail->quantity);
                $received = (float) $item['quantity_received'];

                if ($received > $shipped) {
                    throw new \Exception("La cantidad recibida no puede superar la enviada en el detalle {$detailId}");
                }

                if ($received > 0) {
                    $this->movementService->increment(
                        (int) $movement->location_destination_id,
                        (int) $detail->product_id,
                        (int) ($detail->unit_id ?? $detail->product?->unit_id),
                        $received
                    );
                }

                $difference = $shipped - $received;
                $adjustmentReason = $item['adjustment_reason'] ?? null;
                $adjustmentNotes = $item['adjustment_notes'] ?? null;

                if ($difference > 0 && !$adjustmentReason) {
                    throw new \Exception("Debes indicar motivo de ajuste (loss/damage) para faltantes en el detalle {$detailId}");
                }

                if ($difference > 0 && in_array($adjustmentReason, ['loss', 'damage'])) {
                    $adjustmentsByReason[$adjustmentReason][] = [
                        'detail' => $detail,
                        'quantity' => $difference,
                        'notes' => $adjustmentNotes,
                    ];
                }

                $detail->content = array_merge($detailContent, [
                    'quantity_received' => $received,
                    'has_difference' => $difference > 0,
                    'difference' => $difference,
                    'adjustment_reason' => $adjustmentReason,
                    'adjustment_notes' => $adjustmentNotes,
                ]);
                $detail->save();

                $receivedItems[] = [
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'quantity_shipped' => $shipped,
                    'quantity_received' => $received,
                    'difference' => $difference,
                    'adjustment_reason' => $adjustmentReason,
                ];
            }

            $evidence = $this->saveEvidenceFiles($request, 'evidence', "transfer_{$movement->id}_step3");

            $createdAdjustments = [];
            foreach (['loss', 'damage'] as $reason) {
                if (empty($adjustmentsByReason[$reason])) {
                    continue;
                }

                $adjustment = Adjustment::create([
                    'company_id' => $movement->company_id,
                    'location_origin_id' => $movement->location_destination_id,
                    'location_destination_id' => $movement->location_destination_id,
                    'movement_type' => 'adjustment',
                    'movement_reason' => $reason,
                    'reference_type' => 'transfer',
                    'reference_id' => $movement->id,
                    'user_id' => Auth::id(),
                    'movement_date' => now(),
                    'status' => 'closed',
                    'content' => [
                        'comments' => "Ajuste generado al recibir transferencia #{$movement->id}",
                        'transfer_id' => $movement->id,
                        'reason' => $reason,
                        'created_by' => Auth::id(),
                        'created_at' => $nowIso,
                        'evidence' => $evidence,
                    ],
                ]);

                foreach ($adjustmentsByReason[$reason] as $entry) {
                    /** @var MovementDetail $detail */
                    $detail = $entry['detail'];
                    $currentStock = (float) (DB::table('product_location')
                        ->where('location_id', $movement->location_destination_id)
                        ->where('product_id', $detail->product_id)
                        ->value('current_stock') ?? 0);

                    MovementDetail::create([
                        'movement_id' => $adjustment->id,
                        'product_id' => $detail->product_id,
                        'unit_id' => $detail->unit_id,
                        'quantity' => (float) $entry['quantity'],
                        'unit_cost' => (float) ($detail->unit_cost ?? 0),
                        'total_cost' => 0,
                        'previous_stock' => $currentStock,
                        'new_stock' => $currentStock,
                        'content' => [
                            'source' => 'transfer_receive_difference',
                            'transfer_id' => $movement->id,
                            'detail_id' => $detail->id,
                            'notes' => $entry['notes'],
                        ],
                    ]);
                }

                $createdAdjustments[] = [
                    'id' => $adjustment->id,
                    'reason' => $reason,
                    'items_count' => count($adjustmentsByReason[$reason]),
                ];
            }

            $hasDifferences = collect($receivedItems)->contains(fn ($item) => (float) $item['difference'] > 0);

            $content['received_by'] = Auth::id();
            $content['received_by_name'] = $user?->name;
            $content['received_at'] = $nowIso;
            $content['receiving_notes'] = $validated['receiving_notes'] ?? null;
            $content['receiving_evidence'] = $evidence;
            $content['has_differences'] = $hasDifferences;
            $content['received_complete'] = !$hasDifferences;
            $content['received_partial'] = $hasDifferences;
            $content['step_3'] = [
                'actor_id' => Auth::id(),
                'actor_name' => $user?->name,
                'at' => $nowIso,
                'items' => $receivedItems,
                'adjustments' => $createdAdjustments,
            ];
            $content['workflow']['step_3'] = [
                'action' => 'receipt_confirmed',
                'actor_id' => Auth::id(),
                'actor_name' => $user?->name,
                'at' => $nowIso,
                'has_differences' => $hasDifferences,
            ];

            $movement->status = 'closed';
            $movement->content = $content;
            $movement->save();

            DB::commit();

            $received = $movement;

            return response()->json([
                'message' => 'Transferencia recibida exitosamente',
                'data' => new $this->resource($received->load($this->getShowRelations()))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error receiving transfer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al recibir la transferencia',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Guarda evidencia de transferencia usando AppUploadUtil.
     */
    private function saveEvidenceFiles(Request $request, string $field, string $prefix): array
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

            $customName = $prefix . '_' . $index . '_' . now()->format('Ymd_His') . '.' . $file->getClientOriginalExtension();
            $result = AppUploadUtil::saveFile($file, Files::TRANSFER_EVIDENCE_PATH, $customName);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'No se pudo guardar evidencia');
            }

            $saved[] = [
                'name' => basename($result['path']),
                'path' => $result['path'],
                'url' => url('/storage/' . $result['path']),
                'mime_type' => $file->getMimeType(),
            ];
        }

        if (empty($saved)) {
            throw new \Exception('Debes adjuntar al menos una evidencia válida');
        }

        return $saved;
    }
}
