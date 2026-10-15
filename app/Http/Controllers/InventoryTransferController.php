<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Concerns\EvidencePhotoMessages;
use App\Models\Admin\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\InventoryAdjustmentDetail;
use App\Http\Resources\InventoryTransferResource;
use App\Services\TaskService;
use App\Services\TransferService;
use App\Utils\AppUploadUtil;
use App\Enums\TransferStatus;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;

class InventoryTransferController extends Controller
{
    use EvidencePhotoMessages;

    protected TransferService $transferService;
    protected TaskService $taskService;

    public function __construct(TransferService $transferService, TaskService $taskService)
    {
        $this->transferService = $transferService;
        $this->taskService = $taskService;
    }

    /**
     * Listar transferencias ("Solicitudes pendientes" e "Historial" de la app).
     *
     * Filtros: status (separados por coma), direction (sent = salen de la
     * sucursal actual, received = llegan a ella; como las flechas ↑/↓ de las
     * pantallas), search (folio, notas, sucursales, quien la pidió o
     * producto), from_location_id, to_location_id y fechas (date_from/date_to
     * o start_date/end_date). Con per_page responde con la forma estándar de
     * los listados paginados ({data, links, meta} con from/to), la que lee
     * AppList.
     */
    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $currentLocationId = CurrentLocation::id();
        $seesAllLocations = CurrentWorker::canAccessAllLocations();

        // Sin sucursal actual el filtro de sucursal no tiene contra qué
        // comparar: antes, en ese caso, se listaban las de todas.
        if (!$seesAllLocations && !$currentLocationId) {
            return response()->json(['message' => 'Selecciona una sucursal para continuar.'], 403);
        }

        try {
            $query = InventoryTransfer::with([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'details.product'
            ]);

            // Aislamiento por empresa: SIEMPRE la empresa del contexto, nunca el
            // company_id que mande el cliente (antes se podían listar las
            // transferencias de cualquier empresa).
            $query->where('company_id', CurrentCompany::id());

            // Aislamiento por sucursal: quien no es administrador solo ve las
            // transferencias de su sucursal (entrantes o salientes).
            if (!$seesAllLocations) {
                $query->where(function ($q) use ($currentLocationId) {
                    $q->where('from_location_id', $currentLocationId)
                        ->orWhere('to_location_id', $currentLocationId);
                });
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

            // Dirección respecto a la sucursal actual: "Enviadas" (salen de
            // aquí) o "Recibidas" (llegan aquí).
            $direction = (string) $request->input('direction', '');
            if ($currentLocationId && $direction === 'sent') {
                $query->where('from_location_id', $currentLocationId);
            } elseif ($currentLocationId && $direction === 'received') {
                $query->where('to_location_id', $currentLocationId);
            }

            if ($request->filled('from_location_id')) {
                $query->where('from_location_id', (int) $request->input('from_location_id'));
            }

            if ($request->filled('to_location_id')) {
                $query->where('to_location_id', (int) $request->input('to_location_id'));
            }

            $search = trim((string) $request->input('search', ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('transfer_number', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhereHas('fromLocation', fn($location) => $location->where('name', 'like', $like))
                        ->orWhereHas('toLocation', fn($location) => $location->where('name', 'like', $like))
                        ->orWhereHas('requestedByUser', fn($user) => $user->where('name', 'like', $like))
                        ->orWhereHas('details.product', fn($product) => $product->where('name', 'like', $like)->orWhere('code', 'like', $like));
                });
            }

            // Rango de fechas (cada extremo es opcional).
            $dateFrom = $request->input('date_from', $request->input('start_date'));
            $dateTo = $request->input('date_to', $request->input('end_date'));
            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Ordenar (el id desempata las creadas en el mismo segundo).
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

            // Paginar o retornar todo
            if ($request->filled('per_page')) {
                $perPage = max(1, min((int) $request->input('per_page'), 100));

                return InventoryTransferResource::collection($query->paginate($perPage))->response();
            }

            $transfers = $query->get();
            return response()->json([
                'data' => InventoryTransferResource::collection($transfers),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'Error al obtener las transferencias');
        }
    }

    /**
     * Sucursales a las que se les puede pedir mercancía ("Pedir a" en la
     * solicitud de transferencia): las activas de la empresa menos la actual.
     *
     * Basta transfers_create. El formulario usaba la lista de sucursales, que
     * a quien no tiene acceso a todas solo le trae la suya: el personal de
     * sucursal veía el menú vacío y no podía pedir mercancía.
     */
    public function origins(): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compañía actual'], 400);
        }

        $currentLocationId = CurrentLocation::id();

        $origins = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($currentLocationId, fn($query) => $query->where('id', '!=', $currentLocationId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $origins->map(fn(Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
            ])->values(),
        ]);
    }

    /**
     * Crear nueva transferencia (solicitud)
     *
     * Origen y destino tienen que ser sucursales activas de la empresa, y
     * quien la crea tiene que estar operando en una de las dos: la solicitud
     * normal la hace el destino ("Pedir a") y "Qué pedir" también deja que el
     * origen proponga un envío. Quien tiene acceso a todas las sucursales
     * puede crearla entre cualesquiera.
     */
    public function store(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compañía actual'], 400);
        }

        $validated = $request->validate(array_merge([
            'from_location_id' => ['required', 'integer', $this->activeLocationRule($companyId)],
            'to_location_id' => ['required', 'integer', 'different:from_location_id', $this->activeLocationRule($companyId)],
            'notes' => 'nullable|string',
        ], $this->detailRules($companyId, true)), $this->transferMessages());

        $fromLocationId = (int) $validated['from_location_id'];
        $toLocationId = (int) $validated['to_location_id'];

        if (!$this->actsFrom($fromLocationId, $toLocationId)) {
            return $this->wrongLocationResponse('Solo puedes pedir mercancía para tu sucursal o enviarla desde ella.');
        }

        try {
            // Validar stock disponible en ubicación origen (422 con el motivo).
            $this->validateStockAvailability($validated['details'], $fromLocationId);

            $transfer = DB::transaction(function () use ($validated, $companyId, $fromLocationId, $toLocationId) {
                $transfer = InventoryTransfer::create([
                    'company_id' => $companyId,
                    'from_location_id' => $fromLocationId,
                    'to_location_id' => $toLocationId,
                    'current_step' => 1,
                    'content' => InventoryTransfer::defaultWorkflowContent((int) Auth::id()),
                    'requested_by' => Auth::id(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                $this->replaceDetails($transfer, $validated['details']);

                return $transfer;
            });

            // Cargar relaciones para el response
            $transfer->load([
                'fromLocation',
                'toLocation',
                'requestedByUser',
                'details.product.mainImage'
            ]);

            try {
                $this->taskService->createFromInventoryTransfer($transfer, 'approve');
            } catch (Exception $taskException) {
                Log::warning('Could not create approve_transfer task after transfer creation', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia creada exitosamente',
                'data' => new InventoryTransferResource($transfer),
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'Error al crear la transferencia');
        }
    }

    /**
     * Mostrar transferencia específica
     */
    public function show(int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $transfer = $this->findVisibleTransfer($id, [
            'fromLocation',
            'toLocation',
            'requestedByUser',
            'details.product.activePackages',
            'details.product.mainImage',
            'details.package',
            'details.unit'
        ]);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        try {
            return response()->json([
                'data' => new InventoryTransferResource($transfer),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'Error al obtener la transferencia');
        }
    }

    /**
     * Actualizar transferencia (solo en estado PENDING)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = (int) CurrentCompany::id();
        $transfer = $this->findVisibleTransfer($id);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La edita una de las dos partes, desde su sucursal.
        if (!$this->actsFrom((int) $transfer->from_location_id, (int) $transfer->to_location_id)) {
            return $this->wrongLocationResponse('Esta solicitud solo se edita desde la sucursal de origen o la de destino.');
        }

        $validated = $request->validate(array_merge([
            'from_location_id' => ['sometimes', 'integer', $this->activeLocationRule($companyId)],
            'to_location_id' => ['sometimes', 'integer', $this->activeLocationRule($companyId)],
            'notes' => 'nullable|string',
        ], $this->detailRules($companyId, false)), $this->transferMessages());

        $fromLocationId = (int) ($validated['from_location_id'] ?? $transfer->from_location_id);
        $toLocationId = (int) ($validated['to_location_id'] ?? $transfer->to_location_id);

        if ($fromLocationId === $toLocationId) {
            return response()->json([
                'message' => 'El origen y el destino deben ser sucursales distintas.',
                'errors' => ['to_location_id' => ['El origen y el destino deben ser sucursales distintas.']],
            ], 422);
        }

        // Con origen o destino nuevos, quien edita tiene que seguir siendo una
        // de las dos partes (misma regla que al crearla).
        if (!$this->actsFrom($fromLocationId, $toLocationId)) {
            return $this->wrongLocationResponse('Solo puedes pedir mercancía para tu sucursal o enviarla desde ella.');
        }

        try {
            if ($transfer->status !== TransferStatus::PENDING) {
                return response()->json([
                    'message' => 'Solo se pueden editar transferencias pendientes'
                ], 422);
            }

            // Validar stock disponible en ubicación origen si se actualizan los detalles
            if (isset($validated['details'])) {
                $this->validateStockAvailability($validated['details'], $fromLocationId);
            }

            DB::transaction(function () use ($transfer, $validated, $fromLocationId, $toLocationId) {
                // Relectura con bloqueo: la solicitud pudo aprobarse mientras tanto.
                $locked = InventoryTransfer::whereKey($transfer->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== TransferStatus::PENDING) {
                    throw new BusinessRuleException('Solo se pueden editar transferencias pendientes', 'invalid_status');
                }

                $transfer->update([
                    'from_location_id' => $fromLocationId,
                    'to_location_id' => $toLocationId,
                    'notes' => $validated['notes'] ?? $transfer->notes,
                ]);

                // Si se envían detalles, reemplazarlos
                if (isset($validated['details'])) {
                    $transfer->details()->delete();
                    $this->replaceDetails($transfer, $validated['details']);
                }
            });

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
            return $this->errorResponse($e, 'Error al actualizar la transferencia');
        }
    }

    /**
     * Cancelar transferencia (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_delete')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $transfer = $this->findVisibleTransfer($id);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La cancela quien la pidió (destino) o el origen, desde su sucursal.
        if (!$this->actsFrom((int) $transfer->from_location_id, (int) $transfer->to_location_id)) {
            return $this->wrongLocationResponse('Esta transferencia solo se cancela desde la sucursal de origen o la de destino.');
        }

        try {
            if (!$transfer->status->canCancel()) {
                return response()->json([
                    'message' => 'Esta transferencia no puede ser cancelada',
                    'code' => 'invalid_status',
                ], 422);
            }

            $transfer = $this->transferService->cancel($transfer, 'Cancelada por el usuario');

            try {
                $this->taskService->cancelRelatedTasks(
                    InventoryTransfer::class,
                    (int) $transfer->id,
                    ['approve_transfer', 'send_transfer', 'receive_transfer']
                );
            } catch (Exception $taskException) {
                Log::warning('Could not cancel related tasks for cancelled transfer', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia cancelada exitosamente',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'Error al cancelar la transferencia');
        }
    }

    /**
     * Aprobar transferencia
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Quien aprueba puede ajustar la cantidad de cada renglón.
        $request->validate([
            'items' => 'nullable|array',
            'items.*.detail_id' => 'required_with:items|integer',
            'items.*.quantity_requested' => 'required_with:items|numeric|min:0',
        ]);

        $transfer = $this->findVisibleTransfer($id, ['fromLocation']);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La aprueba el origen, que es quien va a mandar la mercancía.
        if (!$this->actsFrom((int) $transfer->from_location_id)) {
            return $this->wrongLocationResponse("Esta transferencia la aprueba la sucursal de origen ({$transfer->fromLocation?->name}).");
        }

        try {
            $transfer = $this->transferService->approve($transfer, $request->all());

            try {
                $this->taskService->completeRelatedTask(
                    InventoryTransfer::class,
                    (int) $transfer->id,
                    'approve_transfer',
                    Auth::id() ? (int) Auth::id() : null
                );
                $this->taskService->createFromInventoryTransfer($transfer, 'send');
            } catch (Exception $taskException) {
                Log::warning('Could not advance transfer tasks after approve', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia aprobada exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'No se pudo aprobar la transferencia');
        }
    }

    /**
     * Rechazar transferencia
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        $transfer = $this->findVisibleTransfer($id);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La rechaza el origen (o la retira el destino), desde su sucursal.
        if (!$this->actsFrom((int) $transfer->from_location_id, (int) $transfer->to_location_id)) {
            return $this->wrongLocationResponse('Esta transferencia solo se rechaza desde la sucursal de origen o la de destino.');
        }

        try {
            $transfer = $this->transferService->reject($transfer, $request->rejection_reason);

            try {
                $this->taskService->cancelRelatedTasks(
                    InventoryTransfer::class,
                    (int) $transfer->id,
                    ['approve_transfer', 'send_transfer', 'receive_transfer']
                );
            } catch (Exception $taskException) {
                Log::warning('Could not cancel transfer tasks after reject', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia rechazada',
                'data' => new InventoryTransferResource($transfer)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'No se pudo rechazar la transferencia');
        }
    }

    /**
     * Enviar transferencia (con productos específicos)
     */
    public function ship(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $transfer = $this->findVisibleTransfer($id, ['details', 'fromLocation']);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La envía el origen: es su stock el que sale.
        if (!$this->actsFrom((int) $transfer->from_location_id)) {
            return $this->wrongLocationResponse("Esta transferencia la envía la sucursal de origen ({$transfer->fromLocation?->name}).");
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.detail_id' => 'required|distinct|exists:inventory_transfer_details,id',
            'items.*.quantity_shipped' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
            'shipping_notes' => 'nullable|string|max:1000',
            'evidence' => 'required|array|min:1',
            'evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], array_merge([
            'evidence.required' => 'Sube al menos una foto de evidencia del envío.',
            'evidence.min' => 'Sube al menos una foto de evidencia del envío.',
        ], $this->evidencePhotoMessages(['evidence.*'])));

        try {
            $detailMap = $transfer->details->keyBy('id');
            $shipments = [];
            $auditItems = [];

            foreach ($validated['items'] as $item) {
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

            $this->validateStockAvailability($shipments, (int) $transfer->from_location_id, 'quantity_shipped');

            // Envío, evidencias y bitácora en una sola transacción: si algo
            // falla (p. ej. no se puede guardar una foto) el stock no sale.
            $transfer = DB::transaction(function () use ($request, $transfer, $shipments, $auditItems, $validated) {
                $transfer = $this->transferService->ship($transfer, $shipments);

                $savedEvidence = $this->saveEvidenceFiles($request, 'evidence', $transfer->id, 'step_2');
                $savedEvidenceNames = array_values(array_filter(array_map(
                    static fn(array $file) => (string) ($file['name'] ?? ''),
                    $savedEvidence
                )));

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

                return $transfer;
            });

            try {
                $this->taskService->completeRelatedTask(
                    InventoryTransfer::class,
                    (int) $transfer->id,
                    'send_transfer',
                    Auth::id() ? (int) Auth::id() : null
                );
                $this->taskService->createFromInventoryTransfer($transfer, 'receive');
            } catch (Exception $taskException) {
                Log::warning('Could not advance transfer tasks after ship', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia enviada exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'No se pudo enviar la transferencia');
        }
    }

    /**
     * Recibir transferencia (confirmar cantidades recibidas)
     *
     * Tiene que venir cada renglón enviado. Antes un renglón omitido no se
     * acreditaba en el destino, no pedía motivo ni evidencia y la
     * transferencia quedaba "recibida completa": lo que salió del origen
     * desaparecía sin dejar rastro.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $transfer = $this->findVisibleTransfer($id, ['details.product', 'toLocation']);

        if (!$transfer) {
            return $this->transferNotFoundResponse();
        }

        // La recibe el destino: la mercancía entra a su stock.
        if (!$this->actsFrom((int) $transfer->to_location_id)) {
            return $this->wrongLocationResponse("Esta transferencia la recibe la sucursal de destino ({$transfer->toLocation?->name}).");
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.detail_id' => 'required|distinct|exists:inventory_transfer_details,id',
            'items.*.quantity_received' => 'required|numeric|min:0',
            'items.*.adjustment_reason' => 'nullable|in:loss,damage',
            'items.*.adjustment_comment' => 'nullable|string|max:500',
            'items.*.adjustment_notes' => 'nullable|string|max:500',
            'items.*.adjustment_evidence' => 'nullable|array',
            'items.*.adjustment_evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'receiving_notes' => 'nullable|string|max:1000',
            'evidence' => 'nullable|array',
            'evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], $this->evidencePhotoMessages(['evidence.*', 'items.*.adjustment_evidence.*']));

        try {
            $detailMap = $transfer->details->keyBy('id');

            $submitted = collect($validated['items'])->map(fn($item) => (int) $item['detail_id']);
            $missing = $transfer->details->filter(
                fn($detail) => (float) $detail->quantity_shipped > 0 && !$submitted->contains((int) $detail->id)
            );

            if ($missing->isNotEmpty()) {
                $names = $missing->map(fn($detail) => $detail->product?->name ?? "renglón {$detail->id}")->implode(', ');

                return response()->json([
                    'message' => "Falta indicar cuánto llegó de: {$names}.",
                    'code' => 'missing_lines',
                    'details' => ['detail_ids' => $missing->pluck('id')->values()->all()],
                ], 422);
            }

            // Primero se revisa todo; las fotos se guardan hasta que la
            // recepción se aplica (así un renglón inválido no deja archivos).
            $lines = [];

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
                    // La app manda todos los renglones (TransferStepThreeReceive);
                    // los que no se enviaron no tienen nada que recibir, así que
                    // se ignoran en vez de responder 422 y bloquear la recepción.
                    continue;
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
                $evidenceField = "items.{$index}.adjustment_evidence";

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

                if ($difference > 0 && count($this->uploadedFiles($request, $evidenceField)) === 0) {
                    return response()->json([
                        'message' => "Debes subir evidencia para el detalle {$detailId}",
                    ], 422);
                }

                $lines[] = compact('detail', 'shippedQty', 'receivedQty', 'difference', 'reason', 'comment', 'notes', 'evidenceField');
            }

            // Recepción, evidencias, ajustes de registro y bitácora en una sola
            // transacción: antes, si algo fallaba después de recibir, la
            // transferencia quedaba completada sin sus ajustes ni su evidencia.
            [$transfer, $auditItems] = DB::transaction(function () use ($request, $transfer, $lines, $validated) {
                $received = array_map(fn(array $line) => [
                    'detail_id' => $line['detail']->id,
                    'quantity_received' => $line['receivedQty'],
                    'damage_report' => $line['difference'] > 0 ? ($line['notes'] ?? "Faltante: {$line['difference']}") : null,
                ], $lines);

                $transfer = $this->transferService->receive($transfer, $received);

                $auditItems = [];
                $adjustmentsByReason = [
                    'loss' => [],
                    'damage' => [],
                ];

                foreach ($lines as $line) {
                    $detail = $line['detail'];
                    $incidentEvidence = $this->saveEvidenceFiles(
                        $request,
                        $line['evidenceField'],
                        $transfer->id,
                        "step_3_item_{$detail->id}",
                        false
                    );

                    $auditItems[] = [
                        'detail_id' => $detail->id,
                        'quantity_shipped' => $line['shippedQty'],
                        'quantity_received' => $line['receivedQty'],
                        'difference' => $line['difference'],
                        'adjustment_reason' => $line['reason'],
                        'adjustment_comment' => $line['comment'],
                        'adjustment_notes' => $line['notes'],
                        'adjustment_evidence' => $incidentEvidence,
                    ];

                    if ($line['difference'] > 0 && in_array($line['reason'], ['loss', 'damage'])) {
                        $adjustmentsByReason[$line['reason']][] = [
                            'detail' => $detail,
                            'quantity' => $line['difference'],
                            'comment' => $line['comment'],
                            'notes' => $line['notes'],
                            'evidence' => $incidentEvidence,
                        ];
                    }
                }

                $savedEvidence = $this->saveEvidenceFiles($request, 'evidence', $transfer->id, 'step_3', false);
                $savedEvidenceNames = array_values(array_filter(array_map(
                    static fn(array $file) => (string) ($file['name'] ?? ''),
                    $savedEvidence
                )));

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

                return [$transfer, $auditItems];
            });

            $differenceTasksData = array_values(array_filter(
                $auditItems,
                static fn(array $item): bool => (float) ($item['difference'] ?? 0) > 0
            ));

            try {
                $this->taskService->completeRelatedTask(
                    InventoryTransfer::class,
                    (int) $transfer->id,
                    'receive_transfer',
                    Auth::id() ? (int) Auth::id() : null
                );

                if ($differenceTasksData !== []) {
                    $this->taskService->createTransferDiscrepancyTask($transfer, $differenceTasksData);
                }
            } catch (Exception $taskException) {
                Log::warning('Could not finalize transfer tasks after receive', [
                    'transfer_id' => $transfer->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Transferencia recibida exitosamente',
                'data' => new InventoryTransferResource($transfer)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e, 'No se pudo recibir la transferencia');
        }
    }

    /**
     * Transferencia de la empresa que el usuario puede ver: con acceso a todas
     * las sucursales, cualquiera; si no, solo si está asignado a la sucursal
     * de origen o a la de destino. null si no existe o no la puede ver (se
     * responde 404 sin confirmar que existe). Antes cualquiera con el permiso
     * abría, aprobaba, enviaba o recibía la transferencia de otras sucursales
     * con solo conocer el id.
     */
    private function findVisibleTransfer(int $id, array $with = []): ?InventoryTransfer
    {
        $transfer = InventoryTransfer::with($with)
            ->where('company_id', CurrentCompany::id())
            ->find($id);

        if (!$transfer) {
            return null;
        }

        $visible = CurrentWorker::canAccessAllLocations()
            || CurrentWorker::canAccessLocation((int) $transfer->from_location_id)
            || CurrentWorker::canAccessLocation((int) $transfer->to_location_id);

        return $visible ? $transfer : null;
    }

    private function transferNotFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Transferencia no encontrada'], 404);
    }

    /**
     * ¿El usuario opera desde alguna de estas sucursales? Quien tiene acceso a
     * todas, siempre; los demás, solo si la sucursal actual es una de ellas.
     *
     * Es la regla de la app (isSourceLocationUser / isTargetLocationUser): cada
     * paso se hace desde la sucursal que le toca. No basta con estar asignado
     * a ella, porque los permisos se evalúan con el rol de la sucursal actual.
     */
    private function actsFrom(int ...$locationIds): bool
    {
        if (CurrentWorker::canAccessAllLocations()) {
            return true;
        }

        $currentLocationId = CurrentLocation::id();

        return $currentLocationId !== null && in_array($currentLocationId, $locationIds, true);
    }

    private function wrongLocationResponse(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'wrong_location'], 403);
    }

    /** Sucursal activa de la empresa (origen o destino de una transferencia). */
    private function activeLocationRule(int $companyId): Exists
    {
        return Rule::exists('locations', 'id')
            ->where('company_id', $companyId)
            ->where('is_active', true);
    }

    /**
     * Reglas de los renglones de la solicitud. Productos y presentaciones de
     * la empresa; unidades de la empresa o globales.
     */
    private function detailRules(int $companyId, bool $required): array
    {
        return [
            'details' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'details.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'details.*.package_id' => ['nullable', 'integer', Rule::exists('product_packages', 'id')->where('company_id', $companyId)],
            'details.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where(
                fn($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
            )],
            'details.*.quantity_requested' => 'required|numeric|min:0.001',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.batch_number' => 'nullable|string|max:50',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.notes' => 'nullable|string',
        ];
    }

    private function transferMessages(): array
    {
        return [
            'from_location_id.exists' => 'La sucursal de origen no existe, no es de tu empresa o está inactiva.',
            'to_location_id.exists' => 'La sucursal de destino no existe, no es de tu empresa o está inactiva.',
            'to_location_id.different' => 'El origen y el destino deben ser sucursales distintas.',
            'details.*.product_id.exists' => 'Uno de los productos no existe en tu empresa.',
            'details.*.package_id.exists' => 'Una de las presentaciones no existe en tu empresa.',
            'details.*.unit_id.exists' => 'Una de las unidades no existe.',
        ];
    }

    /** Crea los renglones de la solicitud y recalcula su total. */
    private function replaceDetails(InventoryTransfer $transfer, array $details): void
    {
        $totalCost = 0;

        foreach ($details as $detailData) {
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

    /**
     * Valida que el origen tenga la existencia que se pide (al crear o editar
     * la solicitud) o que se va a enviar. Los renglones del mismo producto se
     * suman en su unidad base: 10 piezas y una caja de 12 necesitan 22.
     *
     * @param string $quantityKey quantity_requested (solicitud) o quantity_shipped (envío)
     * @throws InsufficientStockException 422 con el producto y lo disponible
     * @throws BusinessRuleException si una unidad o paquete no corresponde al producto
     */
    protected function validateStockAvailability(array $details, int $locationId, string $quantityKey = 'quantity_requested'): void
    {
        $needed = [];

        foreach ($details as $detail) {
            $quantity = (float) ($detail[$quantityKey] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $productId = (int) $detail['product_id'];
            $packageId = isset($detail['package_id']) ? (int) $detail['package_id'] : null;

            $needed[$productId] = ($needed[$productId] ?? 0)
                + $this->convertToBaseUnits($productId, (int) $detail['unit_id'], $quantity, $packageId ?: null);
        }

        foreach ($needed as $productId => $quantityInBaseUnits) {
            $availableStock = (float) (DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('current_stock') ?? 0);

            // Misma escala que product_location.current_stock (MovementService
            // redondea igual al mover).
            $quantityInBaseUnits = round($quantityInBaseUnits, 3);

            if ($availableStock < $quantityInBaseUnits) {
                throw InsufficientStockException::forProduct($productId, $availableStock, $quantityInBaseUnits, $locationId);
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

        if (!$product->unit_id || !$product->unit) {
            throw new BusinessRuleException("El producto \"{$product->name}\" no tiene unidad base definida", 'invalid_unit');
        }

        // Si hay paquete, usar quantity_per_package
        if ($packageId) {
            $package = \App\Models\ProductPackage::findOrFail($packageId);

            if ((int) $package->product_id !== $productId) {
                throw new BusinessRuleException("La presentación elegida no corresponde a \"{$product->name}\"", 'invalid_package');
            }

            return $quantity * $package->quantity_per_package;
        }

        // Si no hay paquete, convertir unidades
        if ($unitId === (int) $product->unit_id) {
            return $quantity;
        }

        // Conversión entre unidades
        $fromUnit = \App\Models\Unit::findOrFail($unitId);
        $toUnit = $product->unit;

        if (!$this->unitsAreCompatible($fromUnit, $toUnit)) {
            throw new BusinessRuleException(
                sprintf(
                    'La unidad seleccionada %s no es compatible con la unidad base %s de "%s"',
                    $this->formatUnitLabel($fromUnit),
                    $this->formatUnitLabel($toUnit),
                    $product->name,
                ),
                'invalid_unit'
            );
        }

        $fromFactor = (float) ($fromUnit->factor_to_base ?? 1);
        $toFactor = (float) ($toUnit->factor_to_base ?? 1);

        if ($fromFactor <= 0 || $toFactor <= 0) {
            throw new BusinessRuleException(
                "Factor de conversión inválido (unidad {$unitId} a unidad {$product->unit_id})",
                'invalid_unit'
            );
        }

        $quantityInBaseUnit = $quantity * $fromFactor;
        $convertedQuantity = $quantityInBaseUnit / $toFactor;

        return $convertedQuantity;
    }

    protected function unitsAreCompatible(\App\Models\Unit $fromUnit, \App\Models\Unit $toUnit): bool
    {
        $fromType = $this->normalizeUnitType($fromUnit->unit_type);
        $toType = $this->normalizeUnitType($toUnit->unit_type);

        if ($fromType !== '' && $toType !== '' && $fromType === $toType) {
            return true;
        }

        $fromFamilyId = $this->resolveUnitFamilyId($fromUnit);
        $toFamilyId = $this->resolveUnitFamilyId($toUnit);

        return $fromFamilyId !== null
            && $toFamilyId !== null
            && $fromFamilyId === $toFamilyId;
    }

    protected function resolveUnitFamilyId(\App\Models\Unit $unit): ?int
    {
        if ($unit->is_base_unit) {
            return (int) $unit->id;
        }

        if ($unit->base_unit_id) {
            return (int) $unit->base_unit_id;
        }

        $normalizedType = $this->normalizeUnitType($unit->unit_type);
        if ($normalizedType === '') {
            return null;
        }

        return crc32($normalizedType);
    }

    protected function normalizeUnitType(?string $unitType): string
    {
        return strtolower(trim((string) $unitType));
    }

    protected function formatUnitLabel(\App\Models\Unit $unit): string
    {
        $abbreviation = trim((string) ($unit->abbreviation ?? ''));
        $name = trim((string) ($unit->name ?? ''));
        $type = $this->normalizeUnitType($unit->unit_type);

        $label = $abbreviation !== '' ? $abbreviation : ($name !== '' ? $name : '#' . $unit->id);

        if ($type === '') {
            return $label;
        }

        return sprintf('%s (%s)', $label, $type);
    }

    /**
     * Guarda evidencia usando AppUploadUtil para transferencias legacy.
     */
    protected function saveEvidenceFiles(Request $request, string $field, int $transferId, string $step, bool $required = true): array
    {
        $saved = [];
        foreach ($this->uploadedFiles($request, $field) as $index => $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $safeExtension = $extension !== '' ? $extension : 'jpg';
            // Nombre con sufijo aleatorio: las evidencias se sirven por una ruta
            // pública, y un nombre predecible (image_1.jpg) permitía adivinarlas.
            $customName = 'image_' . ($index + 1) . '_' . \Illuminate\Support\Str::random(24) . '.' . $safeExtension;
            $stepPath = rtrim(Files::TRANSFER_EVIDENCE_PATH, '/') . '/transfer_' . $transferId . '/' . $step;
            $result = AppUploadUtil::saveFile($file, $stepPath, $customName);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'No se pudo guardar evidencia');
            }

            $saved[] = [
                'name' => basename($result['path']),
                'path' => $result['path'],
                'url' => url('/api/auth/files/' . $result['path']),
                'mime_type' => $file->getMimeType(),
            ];
        }

        if (empty($saved) && $required) {
            throw new BusinessRuleException('Debes adjuntar al menos una evidencia válida', 'missing_evidence');
        }

        return $saved;
    }

    /**
     * Archivos subidos en un campo (uno o varios), ya sin lo que no es archivo.
     *
     * @return array<int, UploadedFile>
     */
    protected function uploadedFiles(Request $request, string $field): array
    {
        $files = $request->file($field, []);

        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        return array_filter(is_array($files) ? $files : [], fn($file) => $file instanceof UploadedFile);
    }
}
