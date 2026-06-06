<?php

namespace App\Http\Controllers;

use App\Models\PurchaseV2;
use App\Models\PurchaseDetailV2;
use App\Services\CashMovementService;
use App\Services\MovementService;
use App\Services\TaskService;
use App\Notifications\NotificationEngine;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class PurchaseV2Controller extends Controller
{
    public function __construct(
        private TaskService $taskService
    ) {}

    /**
     * Crear o actualizar una compra en estado draft
     * Se llama cada vez que se agrega/modifica/elimina un producto
     */
    public function upsertDraft(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'purchase_id' => 'nullable|exists:purchases,id',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'notes' => 'nullable|string',
                'payment_method' => 'nullable|in:cash,card,transfer,other',
                'items' => 'nullable|array',
                'items.*.id' => 'required_with:items|string',
                'items.*.product_id' => 'required_with:items|integer|exists:products,id',
                'items.*.package_id' => 'nullable|integer|exists:product_packages,id',
                'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
                'items.*.unit_id' => 'required_with:items|integer|exists:units,id',
                'items.*.price' => 'required_with:items|numeric|min:0',
            ]);

            DB::beginTransaction();

            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;
            $userId = Auth::id();

            // Buscar o crear compra en draft
            $purchase = null;
            if (!empty($validated['purchase_id'])) {
                $purchase = PurchaseV2::draft()
                    ->where('id', $validated['purchase_id'])
                    ->where('company_id', $companyId)
                    ->where('location_id', $locationId)
                    ->first();
            }

            if (!$purchase) {
                $purchase = PurchaseV2::create([
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'purchase_number' => PurchaseV2::generatePurchaseNumber(),
                    'purchase_date' => now(),
                    'status' => PurchaseV2::STATUS_DRAFT,
                    'notes' => $validated['notes'] ?? null,
                    'user_id' => $userId,
                ]);
            } else {
                // Actualizar notas y supplier si cambiaron
                $purchase->update([
                    'supplier_id' => $validated['supplier_id'] ?? $purchase->supplier_id,
                    'notes' => $validated['notes'] ?? $purchase->notes,
                    'payment_method' => $validated['payment_method'] ?? $purchase->payment_method,
                ]);
            }

            // Sincronizar items solo si se enviaron
            if (!empty($validated['items'])) {
                $this->syncPurchaseDetails($purchase, $validated['items']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'purchase_id' => $purchase->id,
                    'purchase_number' => $purchase->purchase_number,
                    'total' => $purchase->total,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar la compra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar los detalles de compra según los items del frontend
     */
    private function syncPurchaseDetails(PurchaseV2 $purchase, array $items)
    {
        // Obtener IDs de items existentes
        $existingDetails = $purchase->details()->get()->keyBy(function ($detail) {
            return $detail->package_id
                ? "package_{$detail->package_id}"
                : "product_{$detail->product_id}";
        });

        $itemsToKeep = [];

        foreach ($items as $item) {
            $itemKey = $item['id'];
            $itemsToKeep[] = $itemKey;

            $detailData = [
                'product_id' => $item['product_id'],
                'package_id' => $item['package_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_id' => $item['unit_id'],
                'unit_price' => $item['price'],
            ];

            if ($existingDetails->has($itemKey)) {
                // Actualizar existente
                $existingDetails[$itemKey]->update($detailData);
            } else {
                // Crear nuevo
                $purchase->details()->create($detailData);
            }
        }

        // Eliminar items que ya no están en el carrito
        $existingDetails->each(function ($detail) use ($itemsToKeep) {
            $key = $detail->package_id
                ? "package_{$detail->package_id}"
                : "product_{$detail->product_id}";

            if (!in_array($key, $itemsToKeep)) {
                $detail->delete();
            }
        });
    }

    /**
     * Obtener compra draft actual o crear una nueva
     */
    public function getDraft(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::get()->id;
        $locationId = CurrentLocation::get()->id;

        $purchase = PurchaseV2::draft()
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->with(['details.product', 'details.package', 'details.unit'])
            ->latest()
            ->first();

        if (!$purchase) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $purchase,
        ]);
    }

    /**
     * Mostrar datos de una compra específica
     */
    public function show($id)
    {
        if (!CurrentWorker::hasPermission('purchases_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;

            $purchase = PurchaseV2::where('id', $id)
                ->where('company_id', $companyId)
                ->where('location_id', $locationId)
                ->with(['details.product.mainImage', 'details.package', 'details.unit', 'supplier'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => [
                        'id' => $purchase->id,
                        'supplier_id' => $purchase->supplier_id,
                        'supplier_name' => $purchase->supplier?->name,
                        'purchase_date' => $purchase->purchase_date,
                        'purchase_number' => $purchase->purchase_number,
                        'notes' => $purchase->notes,
                        'document_number' => $purchase->document_number,
                        'status' => $purchase->status,
                        'total' => $purchase->total,
                        'company_id' => $purchase->company_id,
                        'location_id' => $purchase->location_id,
                        'details' => $purchase->details->map(function ($detail) {
                            $productImage = null;
                            if ($detail->product && $detail->product->relationLoaded('mainImage')) {
                                $mainImagePath = $detail->product->mainImage?->image_path;
                                if ($mainImagePath) {
                                    $productImage = AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $mainImagePath);
                                }
                            }

                            return [
                                'id' => $detail->id,
                                'product_id' => $detail->product_id,
                                'product_name' => $detail->product?->name,
                                'product_code' => $detail->product?->code,
                                'product_image' => $productImage,
                                'package_id' => $detail->package_id,
                                'package_name' => $detail->package?->package_name,
                                'quantity' => $detail->quantity,
                                'quantity_received' => $detail->quantity_received,
                                'unit_id' => $detail->unit_id,
                                'unit_name' => $detail->unit?->name,
                                'unit_abbreviation' => $detail->unit?->abbreviation,
                                'unit_price' => $detail->unit_price,
                                'subtotal' => $detail->total ?? ($detail->quantity * $detail->unit_price),
                            ];
                        }),
                        'payment_method' => $purchase->payment_method,
                        'details_count' => $purchase->details->count(),
                        'status_history' => $purchase->metadata ?? [],
                        'discrepancy_resolution' => $this->taskService->getLatestPurchaseDiscrepancyResolution($purchase),
                    ],
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Compra no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Confirmar compra (cambiar de draft a ordered)
     */
    public function confirm(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'expected_delivery_date' => 'nullable|date|after_or_equal:today',
                'document_number' => 'nullable|string|max:255',
                'payment_method' => 'nullable|in:cash,card,transfer,other',
            ]);

            $purchase = PurchaseV2::draft()->findOrFail($id);

            if ($purchase->details()->count() === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede confirmar una compra sin productos'
                ], 422);
            }

            $purchase->appendStatusLog(PurchaseV2::STATUS_ORDERED, Auth::id());

            $purchase->update([
                'status' => PurchaseV2::STATUS_ORDERED,
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'document_number' => $validated['document_number'] ?? null,
                'payment_method' => $validated['payment_method'] ?? $purchase->payment_method ?? 'other',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Compra confirmada exitosamente',
                'data' => $purchase,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar la compra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marcar como en tránsito
     */
    public function markInTransit(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $purchase = PurchaseV2::ordered()->findOrFail($id);

            $purchase->appendStatusLog(PurchaseV2::STATUS_IN_TRANSIT, Auth::id());

            $purchase->update([
                'status' => PurchaseV2::STATUS_IN_TRANSIT,
            ]);

            try {
                $this->taskService->createFromPurchaseV2($purchase);
            } catch (Exception $taskException) {
                Log::warning('Could not create receive_purchase task for PurchaseV2 in transit', [
                    'purchase_id' => $purchase->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Compra marcada como en tránsito',
                'data' => $purchase,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recibir compra y actualizar inventario
     */
    public function receive(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'details' => 'required|array',
                'details.*.id' => 'required|exists:purchase_details,id',
                'details.*.quantity_received' => 'required|numeric|min:0',
            ]);

            DB::beginTransaction();

            $purchase = PurchaseV2::inTransit()->findOrFail($id);
            $movementService = new MovementService();

            // Actualizar cantidades recibidas e incrementar stock
            foreach ($validated['details'] as $detailData) {
                $detail = $purchase->details()->findOrFail($detailData['id']);
                $detail->update([
                    'quantity_received' => $detailData['quantity_received'],
                    'received_at' => now(),
                ]);

                // Incrementar stock usando MovementService
                $movementService->increment(
                    $purchase->location_id,
                    $detail->product_id,
                    $detail->unit_id,
                    $detail->quantity_received,
                    $detail->package_id
                );
            }

            $purchase->appendStatusLog(PurchaseV2::STATUS_RECEIVED, Auth::id());

            $purchase->update([
                'status' => PurchaseV2::STATUS_RECEIVED,
                'delivery_date' => now(),
                'received_by' => Auth::id(),
            ]);

            // Registrar egreso en caja
            CashMovementService::fromPurchaseV2($purchase);

            // Notificar recepción de compra
            $purchase->load(['supplier', 'details.product']);

            $discrepancies = $purchase->details
                ->filter(function ($detail) {
                    return (float) $detail->quantity_received < (float) $detail->quantity;
                })
                ->map(function ($detail) {
                    return [
                        'detail_id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product?->name ?? 'Producto',
                        'ordered_quantity' => (float) $detail->quantity,
                        'received_quantity' => (float) $detail->quantity_received,
                    ];
                })
                ->values()
                ->toArray();

            try {
                $this->taskService->completeRelatedTask(
                    PurchaseV2::class,
                    (int) $purchase->id,
                    'receive_purchase',
                    Auth::id() ? (int) Auth::id() : null
                );

                if ($discrepancies !== []) {
                    $this->taskService->createPurchaseDiscrepancyTask($purchase, $discrepancies);
                }
            } catch (Exception $taskException) {
                Log::warning('Could not sync tasks after PurchaseV2 receive', [
                    'purchase_id' => $purchase->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            $products = $purchase->details->map(function ($detail) {
                return [
                    'name'     => $detail->product?->name ?? '',
                    'quantity' => $detail->quantity_received,
                ];
            })->toArray();

            NotificationEngine::dispatch('purchase_update', $purchase->company_id, [
                'purchase'      => $purchase,
                'supplier_name' => $purchase->supplier?->name ?? '',
                'sub_type'      => 'received',
                'products'      => $products,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Compra recibida exitosamente',
                'data' => $purchase->fresh(['details']),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al recibir la compra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agregar un detalle individual a la compra draft
     */
    public function addDetail(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'purchase_id' => 'nullable|exists:purchases,id',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'product_id' => 'required|integer|exists:products,id',
                'package_id' => 'nullable|integer|exists:product_packages,id',
                'quantity' => 'required|numeric|min:0.0001',
                'unit_id' => 'required|integer|exists:units,id',
                'price' => 'required|numeric|min:0',
            ]);

            DB::beginTransaction();

            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;
            $userId = Auth::id();

            // Buscar o crear compra en draft
            $purchase = null;
            if (!empty($validated['purchase_id'])) {
                $purchase = PurchaseV2::draft()
                    ->where('id', $validated['purchase_id'])
                    ->where('company_id', $companyId)
                    ->where('location_id', $locationId)
                    ->first();
            }

            if (!$purchase) {
                $purchase = PurchaseV2::create([
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'purchase_number' => PurchaseV2::generatePurchaseNumber(),
                    'purchase_date' => now(),
                    'status' => PurchaseV2::STATUS_DRAFT,
                    'user_id' => $userId,
                ]);
            }

            // Crear el detalle
            $detail = $purchase->details()->create([
                'product_id' => $validated['product_id'],
                'package_id' => $validated['package_id'] ?? null,
                'quantity' => $validated['quantity'],
                'unit_id' => $validated['unit_id'],
                'unit_price' => $validated['price'],
            ]);

            $purchase->updateTotal();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto agregado',
                'data' => [
                    'purchase_id' => $purchase->id,
                    'detail_id' => $detail->id,
                    'total' => $purchase->total,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un detalle individual de la compra draft
     */
    public function updateDetail(Request $request, $detailId)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            // Log para debug
            Log::info('UpdateDetail Request', [
                'detail_id' => $detailId,
                'all_data' => $request->all(),
                'input' => $request->input(),
                'json' => $request->json()->all(),
            ]);

            $validated = $request->validate([
                'quantity' => 'nullable|numeric|min:0.0001',
                'unit_id' => 'nullable|integer|exists:units,id',
                'price' => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

            $detail = PurchaseDetailV2::findOrFail($detailId);
            $purchase = $detail->purchase;

            // Verificar que la compra esté en draft
            if ($purchase->status !== PurchaseV2::STATUS_DRAFT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar compras en borrador',
                ], 400);
            }

            Log::info("message", ['validated' => $validated, 'data' => $request->all()]);

            // Actualizar campos enviados
            if (isset($validated['quantity'])) {
                $detail->quantity = $validated['quantity'];
            }
            if (isset($validated['unit_id'])) {
                $detail->unit_id = $validated['unit_id'];
            }
            if (isset($validated['price'])) {
                $detail->unit_price = $validated['price'];
            }

            $detail->save();
            $purchase->updateTotal();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado',
                'data' => [
                    'detail_id' => $detail->id,
                    'total' => $purchase->total,
                    'detail' => $detail,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un detalle individual de la compra draft
     */
    public function removeDetail(Request $request, $detailId)
    {
        if (!CurrentWorker::hasPermission('purchases_delete')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            DB::beginTransaction();

            $detail = PurchaseDetailV2::findOrFail($detailId);
            $purchase = $detail->purchase;

            // Verificar que la compra esté en draft
            if ($purchase->status !== PurchaseV2::STATUS_DRAFT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar compras en borrador',
                ], 400);
            }

            $detail->delete();
            $purchase->updateTotal();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado',
                'data' => [
                    'total' => $purchase->total,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar compra
     */
    public function cancel(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $purchase = PurchaseV2::whereIn('status', [
                PurchaseV2::STATUS_DRAFT,
                PurchaseV2::STATUS_ORDERED,
                PurchaseV2::STATUS_IN_TRANSIT
            ])->findOrFail($id);

            $purchase->appendStatusLog(
                PurchaseV2::STATUS_CANCELLED,
                Auth::id(),
                $validated['reason'] ?? null
            );

            $purchase->update([
                'status' => PurchaseV2::STATUS_CANCELLED,
            ]);

            try {
                $this->taskService->cancelRelatedTasks(
                    PurchaseV2::class,
                    (int) $purchase->id,
                    ['receive_purchase']
                );
            } catch (Exception $taskException) {
                Log::warning('Could not cancel related tasks for cancelled PurchaseV2', [
                    'purchase_id' => $purchase->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Compra cancelada',
                'data' => $purchase,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar la compra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolver la discrepancia de recepción de una compra.
     *
     * Cierra la tarea de discrepancia que se creó al recibir la compra
     * con faltantes. La fuente de verdad de la resolución es la tarea:
     * se guarda en su metadata (resolution) y se marca como completed.
     *
     * resolution:
     *   - credit_note: el proveedor emitió nota de crédito
     *   - adjustment:  se creó un ajuste de inventario (referenciar con adjustment_id)
     *   - no_action:   se acepta el faltante sin acción
     *   - other:       cualquier otra razón
     */
    public function resolveDiscrepancy(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $validated = $request->validate([
                'resolution' => 'required|in:credit_note,adjustment,no_action,other',
                'notes' => 'nullable|string|max:1000',
                'adjustment_id' => 'nullable|integer|exists:movements,id',
            ]);

            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;

            $purchase = PurchaseV2::where('id', $id)
                ->where('company_id', $companyId)
                ->where('location_id', $locationId)
                ->firstOrFail();

            // Solo tiene sentido resolver la discrepancia de una compra recibida.
            if ($purchase->status !== PurchaseV2::STATUS_RECEIVED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se puede resolver la discrepancia de una compra recibida',
                ], 422);
            }

            $task = $this->taskService->findOpenPurchaseDiscrepancyTask($purchase);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay una discrepancia pendiente para esta compra',
                ], 422);
            }

            if ($validated['resolution'] === 'adjustment' && empty($validated['adjustment_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Si la resolución es "adjustment" debes proporcionar adjustment_id',
                ], 422);
            }

            DB::beginTransaction();

            // Persistir la resolución en la metadata de la tarea.
            $taskMetadata = $task->metadata ?? [];
            $taskMetadata['resolution'] = [
                'type' => $validated['resolution'],
                'notes' => $validated['notes'] ?? null,
                'adjustment_id' => $validated['adjustment_id'] ?? null,
                'resolved_by_user_id' => Auth::id(),
                'resolved_at' => now()->toIso8601String(),
            ];
            $task->metadata = $taskMetadata;
            $task->save();

            // Cerrar la tarea (setea status, completed_at, completed_by).
            $closed = $task->complete(Auth::user());

            if (!$closed) {
                throw new Exception('No se pudo cerrar la tarea de discrepancia');
            }

            // Notificar a quien asignó la tarea.
            if ($task->assigned_by && $task->assigned_by !== Auth::id()) {
                try {
                    NotificationEngine::dispatch('task_event', $task->company_id, [
                        'task'       => $task,
                        'sub_type'   => 'completed',
                        'actor_name' => Auth::user()->name,
                    ], userId: $task->assigned_by);
                } catch (Exception $e) {
                    Log::warning('No se pudo notificar resolución de discrepancia', [
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Discrepancia resuelta',
                'data' => [
                    'task_id' => $task->id,
                    'resolution' => $taskMetadata['resolution'],
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al resolver la discrepancia',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar compras
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $location = CurrentLocation::get();

        $query = PurchaseV2::with(['supplier', 'user', 'details'])
            ->where('company_id', CurrentCompany::get()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('location_id') || $location) {
            $locationId = $request->has('location_id') ? $request->location_id : $location->id;
            $query->where('location_id', $locationId);
        }

        $purchases = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($purchases);
    }

    /**
     * Ver detalle de compra
     */
    /*  public function show($id)
    {
        $purchase = PurchaseV2::with([
            'supplier',
            'user',
            'receivedBy',
            'details.product',
            'details.package',
            'details.unit'
        ])->findOrFail($id);

        return response()->json($purchase);
    } */

    /**
     * Generar URL firmada para el PDF de la compra (válida 1 hora).
     */
    public function generatePdfUrl($id)
    {
        if (!CurrentWorker::hasPermission('purchases_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $companyId = CurrentCompany::id();
            $locationId = CurrentLocation::get()?->id;

            // Validar que la compra pertenece a la compañía actual (anti-IDOR cross-tenant).
            $purchase = PurchaseV2::where('company_id', $companyId)
                ->where('location_id', $locationId)
                ->findOrFail($id);

            $signedUrl = URL::temporarySignedRoute(
                'purchases-v2.pdf',
                now()->addHour(),
                [
                    'purchases_v2' => $purchase->id,
                    'company_id' => $companyId,
                ]
            );

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ]);
        } catch (Exception $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            Log::error('Error al generar URL de PDF de compra: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al generar URL del PDF'
            ], 500);
        }
    }

    /**
     * Generar PDF de la compra. Ruta pública-firmada (no requiere auth:sanctum).
     */
    public function generatePdf(Request $request, $id)
    {
        try {
            // Defensa en profundidad: validar que el company_id firmado coincide.
            $companyId = $request->query('company_id');
            abort_if(
                $companyId === null || !PurchaseV2::where('id', $id)->where('company_id', $companyId)->exists(),
                404
            );

            $purchase = PurchaseV2::with([
                'supplier',
                'user',
                'receivedBy',
                'location',
                'details.product',
                'details.package',
                'details.unit',
            ])->findOrFail($id);

            $discrepancyResolution = $this->taskService->getLatestPurchaseDiscrepancyResolution($purchase);

            $pdf = Pdf::loadView('pdf.purchase', [
                'purchase' => $purchase,
                'discrepancyResolution' => $discrepancyResolution,
            ]);

            $pdf->setPaper('letter', 'portrait');

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="compra-' . ($purchase->purchase_number ?? $purchase->id) . '-' . now()->format('Y-m-d') . '.pdf"',
            ]);
        } catch (Exception $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            Log::error('Error al generar PDF de compra: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al generar el PDF'
            ], 500);
        }
    }
}
