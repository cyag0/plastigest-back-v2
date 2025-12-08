<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\Task;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Services\WhatsAppService;
use App\Services\TaskService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PurchaseController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = PurchaseResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Purchase::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'details.product',
            'location',
            'supplier',
            'user'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'details.product.locations',
            'details.product.mainImage',
            'details.unit',
            'location',
            'user'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtros específicos para compras
        if (isset($params['location_id'])) {
            $query->where('location_origin_id', $params['location_id']);
        }

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['start_date'])) {
            $query->where('movement_date', '>=', $params['start_date']);
        }

        if (isset($params['end_date'])) {
            $query->where('movement_date', '<=', $params['end_date']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            // Campos principales del formulario
            //'company_id' => 'required|exists:companies,id',
            //'location_origin_id' => 'required|exists:locations,id',
            'purchase_date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'status' => 'nullable|in:draft,ordered,in_transit,received',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Productos seleccionados para la compra
            /* 'purchase_items' => 'required|array|min:1',
            'purchase_items.*.product_id' => 'required|exists:products,id',
            'purchase_items.*.quantity' => 'required|numeric|min:0.001',
            'purchase_items.*.unit_price' => 'required|numeric|min:0',
            'purchase_items.*.total_price' => 'required|numeric|min:0', */
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        // Verificar si la compra puede editarse
        if ($model->status !== 'draft' && $model->status !== \App\Enums\PurchaseStatus::DRAFT) {
            throw new \Exception('Solo se pueden editar compras en estado de borrador');
        }

        return $request->validate([
            // Campos principales del formulario
            'company_id' => 'sometimes|exists:companies,id',
            'location_origin_id' => 'exists:locations,id',
            'purchase_date' => 'sometimes|date',
            'supplier_id' => 'exists:suppliers,id',
            'status' => 'sometimes|in:draft,ordered,in_transit,received',
            'document_number' => 'nullable|string|max:255',
            'comments' => 'nullable|string',

            // Productos seleccionados para la compra
            'purchase_items' => 'sometimes|array|min:1',
            'purchase_items.*.product_id' => 'required|exists:products,id',
            'purchase_items.*.quantity' => 'required|numeric|min:0.001',
            'purchase_items.*.unit_price' => 'required|numeric|min:0',
            'purchase_items.*.total_price' => 'required|numeric|min:0',
        ]);
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras y maneja toda la lógica de compras
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();
            $company = CurrentCompany::get();
            $location = CurrentLocation::get();


            // Extraer purchase_items para procesamiento separado
            $purchaseItems = $data['purchase_items'] ?? [];
            unset($data['purchase_items']);

            // Mapear campos del formulario a la estructura de movements
            if (isset($data['purchase_date'])) {
                $data['movement_date'] = $data['purchase_date'];
                unset($data['purchase_date']);
            }

            // Establecer valores por defecto para movements
            $data['movement_type'] = 'entry';
            $data['movement_reason'] = 'purchase';
            $data['reference_type'] = 'purchase_order';
            $data['user_id'] = Auth::id() ?? 1;
            $data['location_destination_id'] = null; // No aplica para compras
            $data['location_origin_id'] = $location->id ?? null; // Ajustar según sea necesario
            $data['company_id'] = $company->id ?? null;

            // Establecer status por defecto si no se proporciona
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }

            // Mover document_number y comments a content
            $content = [];
            if (isset($data['document_number'])) {
                $content['document_number'] = $data['document_number'];
                unset($data['document_number']);
            }
            if (isset($data['comments'])) {
                $content['comments'] = $data['comments'];
                unset($data['comments']);
            }
            if (!empty($content)) {
                $data['content'] = $content;
            }

            $purchase = $callback($data);

            DB::commit();
            return $purchase;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // No se puede eliminar compras que han sido recibidas (afectan stock)
        if ($model->status === \App\Enums\PurchaseStatus::RECEIVED || $model->status === 'received') {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar una compra recibida porque afecta el stock'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Validar si se puede editar una compra
     */
    protected function canUpdate(Model $model): array
    {
        // Solo se pueden editar compras en borrador
        if ($model->status !== \App\Enums\PurchaseStatus::DRAFT && $model->status !== 'draft') {
            return [
                'can_update' => false,
                'message' => 'Solo se pueden editar compras en estado de borrador'
            ];
        }

        return [
            'can_update' => true,
            'message' => ''
        ];
    }

    /**
     * Crear tarea automática para recibir la compra
     */
    public function createReceivePurchaseTask(Purchase $purchase)
    {
        try {
            $purchase->load(['supplier', 'details.product']);

            $productsList = $purchase->details->map(function ($detail) {
                return "- {$detail->product->name} (x{$detail->quantity})";
            })->join("\n");

            // Determinar a quién asignar la tarea
            $assignedUserId = $purchase->user_id ?? Auth::id();
            $data = [
                'title' => "Recibir Compra #{$purchase->id} - {$purchase->supplier->name}",
                'description' => "Verificar y recibir los siguientes productos:\n\n{$productsList}\n\nProveedor: {$purchase->supplier->name}\nReferencia: {$purchase->reference}",
                'type' => 'stock_check',
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => now()->addDays(2),
                'company_id' => $purchase->company_id,
                'location_id' => $purchase->location_origin_id,
                'assigned_to' => $assignedUserId,
                'is_recurring' => false,
            ];

            Log::info('Creating receive purchase task', [
                'purchase_id' => $purchase->id,
                'assigned_user_id' => $assignedUserId,
                'data' => $data,
            ]);

            $task = Task::create($data);

            Log::info('Receive purchase task created from status change', [
                'task_id' => $task->id,
                'purchase_id' => $purchase->id,
            ]);

            // Notificar usando TaskService
            app(TaskService::class)->notifyPurchaseTaskCreated($task, $purchase);
        } catch (\Exception $e) {
            Log::error('Error creating receive purchase task', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Override del método update para verificar permisos de edición
     */
    public function update(Request $request, $id)
    {
        $model = $this->model::findOrFail($id);

        $canUpdate = $this->canUpdate($model);
        if (!$canUpdate['can_update']) {
            return response()->json([
                'success' => false,
                'message' => $canUpdate['message']
            ], 422);
        }

        return parent::update($request, $id);
    }

    /**
     * Avanzar al siguiente estado en el flujo
     */
    public function advance(Request $request, $id)
    {
        try {
            $purchase = Purchase::findOrFail($id);

            if ($purchase->advanceStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Estado actualizado a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede avanzar al siguiente estado'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Retroceder al estado anterior en el flujo
     */
    public function revert(Request $request, $id)
    {
        try {
            $purchase = Purchase::findOrFail($id);

            if ($purchase->revertStatus()) {
                return response()->json([
                    'success' => true,
                    'message' => "Estado revertido a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede retroceder al estado anterior'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Transicionar a un estado específico
     */
    public function transitionTo(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:draft,ordered,in_transit,received'
        ]);

        try {
            $purchase = Purchase::findOrFail($id);
            $previousStatus = $purchase->status;
            $newStatus = \App\Enums\PurchaseStatus::from($request->status);

            if ($purchase->transitionTo($newStatus)) {
                // Si cambió a in_transit, crear tarea de recepción
                if ($newStatus->value === 'in_transit' && $previousStatus->value !== 'in_transit') {
                    $this->createReceivePurchaseTask($purchase);
                }

                return response()->json([
                    'success' => true,
                    'message' => "Estado actualizado a {$purchase->status->label()}",
                    'data' => new $this->resource($purchase->load($this->getShowRelations()))
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se puede realizar la transición'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener información de estados disponibles
     */
    public function statusInfo()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Enums\PurchaseStatus::options()
        ]);
    }

    /**
     * Obtener estadísticas y reportes de compras
     */
    public function purchaseStats(Request $request)
    {
        try {
            $locationId = $request->input('location_id') ?? current_location_id();
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Query base
            $query = Purchase::where('location_origin_id', $locationId);

            if ($startDate) {
                $query->where('movement_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('movement_date', '<=', $endDate);
            }

            // Estadísticas generales
            $totalPurchases = (clone $query)->count();
            $totalAmount = (clone $query)->sum('total_cost');
            $averageAmount = $totalPurchases > 0 ? $totalAmount / $totalPurchases : 0;

            // Compras por estado
            $byStatus = (clone $query)
                ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_cost) as total'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(function ($item) {
                    $statusLabel = $item->status;

                    // Si ya es un enum, obtener el label directamente
                    if ($statusLabel instanceof \App\Enums\PurchaseStatus) {
                        $statusLabel = $statusLabel->label();
                    } elseif (is_string($statusLabel) && class_exists(\App\Enums\PurchaseStatus::class)) {
                        // Si es un string, intentar convertirlo a enum
                        try {
                            $statusLabel = \App\Enums\PurchaseStatus::from($statusLabel)->label();
                        } catch (\Exception $e) {
                            $statusLabel = ucfirst($statusLabel);
                        }
                    } else {
                        $statusLabel = ucfirst((string) $statusLabel);
                    }

                    return [$statusLabel => [
                        'count' => $item->count,
                        'total' => (float) $item->total
                    ]];
                });

            // Top 5 proveedores
            $topSuppliers = (clone $query)
                ->select(
                    'supplier_id',
                    DB::raw('COUNT(*) as purchase_count'),
                    DB::raw('SUM(total_cost) as total_amount')
                )
                ->whereNotNull('supplier_id')
                ->groupBy('supplier_id')
                ->orderByDesc('total_amount')
                ->limit(5)
                ->with('supplier:id,name')
                ->get()
                ->map(function ($item) {
                    return [
                        'supplier_name' => $item->supplier->name ?? 'Sin nombre',
                        'purchase_count' => $item->purchase_count,
                        'total_amount' => (float) $item->total_amount
                    ];
                });

            // Productos más comprados
            $topProducts = DB::table('movements_details')
                ->join('movements', 'movements_details.movement_id', '=', 'movements.id')
                ->join('products', 'movements_details.product_id', '=', 'products.id')
                ->where('movements.location_origin_id', $locationId)
                ->where('movements.movement_type', 'entry')
                ->where('movements.movement_reason', 'purchase')
                ->when($startDate, function ($q) use ($startDate) {
                    return $q->where('movements.movement_date', '>=', $startDate);
                })
                ->when($endDate, function ($q) use ($endDate) {
                    return $q->where('movements.movement_date', '<=', $endDate);
                })
                ->select(
                    'products.name as product_name',
                    DB::raw('SUM(movements_details.quantity) as total_quantity'),
                    DB::raw('SUM(movements_details.total_cost) as total_amount')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_amount')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'product_name' => $item->product_name,
                        'total_quantity' => (float) $item->total_quantity,
                        'total_amount' => (float) $item->total_amount
                    ];
                });

            // Tendencia de compras (últimos 6 meses)
            $sixMonthsAgo = now()->subMonths(6)->startOfMonth();
            $purchaseTrend = (clone $query)
                ->where('movement_date', '>=', $sixMonthsAgo)
                ->select(
                    DB::raw('DATE_FORMAT(movement_date, "%Y-%m") as month'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(total_cost) as total')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(function ($item) {
                    return [
                        'month' => $item->month,
                        'count' => $item->count,
                        'total' => (float) $item->total
                    ];
                });

            // Compras recientes (recibidas)
            $receivedCount = (clone $query)
                ->where('status', 'received')
                ->count();

            $pendingCount = (clone $query)
                ->whereIn('status', ['ordered', 'in_transit'])
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_purchases' => $totalPurchases,
                    'total_amount' => round($totalAmount, 2),
                    'average_amount' => round($averageAmount, 2),
                    'received_count' => $receivedCount,
                    'pending_count' => $pendingCount,
                    'by_status' => $byStatus,
                    'top_suppliers' => $topSuppliers,
                    'top_products' => $topProducts,
                    'purchase_trend' => $purchaseTrend,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Iniciar pedido de compra (cambiar de draft a ordered)
     * POST /purchases/{id}/start-order
     */
    public function startOrder(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $purchase = Purchase::with(['details'])->findOrFail($id);

            // Validar que la compra esté en borrador
            if ($purchase->status !== 'draft' && $purchase->status !== \App\Enums\PurchaseStatus::DRAFT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden iniciar pedidos que estén en borrador'
                ], 422);
            }

            // Validar que tenga productos
            if ($purchase->details->count() === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La compra debe tener al menos un producto'
                ], 422);
            }

            // Validar que tenga proveedor
            if (!$purchase->supplier_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'La compra debe tener un proveedor asignado'
                ], 422);
            }

            // Enviar mensaje de WhatsApp al proveedor si tiene teléfono
            if ($purchase->supplier && $purchase->supplier->phone) {
                try {
                    $whatsappService = new WhatsAppService();
                    $phone = $this->formatPhoneNumber($purchase->supplier->phone);
                    $whatsappService->sendPurchaseOrder($phone, $purchase);

                    Log::info('WhatsApp sent for purchase order', [
                        'purchase_id' => $purchase->id,
                        'supplier_phone' => $phone
                    ]);
                } catch (\Exception $e) {
                    // Si falla WhatsApp, hacer rollback y no continuar
                    DB::rollBack();

                    Log::error('Failed to send WhatsApp for purchase order', [
                        'purchase_id' => $purchase->id,
                        'error' => $e->getMessage()
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al enviar mensaje de WhatsApp. Verifica que el número del proveedor esté en la lista de permitidos.',
                        'whatsapp_error' => true
                    ], 422);
                }
            }

            // Solo cambiar estado si WhatsApp se envió correctamente
            $purchase->status = 'ordered';
            $purchase->save();

            DB::commit();

            // Recargar con relaciones
            $purchase->load($this->getShowRelations());

            return response()->json([
                'success' => true,
                'message' => 'Pedido iniciado exitosamente',
                'data' => new $this->resource($purchase, ['editing' => true])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al iniciar pedido: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar el pedido: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Completar/recibir compra y afectar el stock
     * POST /purchases/{id}/receive
     */
    public function receivePurchase(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $purchase = Purchase::with(['details.product', 'details.unit'])->findOrFail($id);

            // Validar que la compra esté en un estado que permita recibirla
            if ($purchase->status === \App\Enums\PurchaseStatus::RECEIVED || $purchase->status === 'received') {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta compra ya fue recibida'
                ], 422);
            }

            if ($purchase->status === \App\Enums\PurchaseStatus::DRAFT || $purchase->status === 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede recibir una compra en borrador'
                ], 422);
            }

            $locationId = $purchase->location_origin_id;

            if (!$locationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'La compra no tiene una ubicación de destino'
                ], 422);
            }

            // Procesar cada detalle de la compra y actualizar el stock
            foreach ($purchase->details as $detail) {
                $product = $detail->product;
                $quantity = $detail->quantity;

                // Si hay unidad especificada, convertir a unidad base
                if ($detail->unit_id && $detail->unit) {
                    $factorToBase = $detail->unit->factor_to_base ?? 1;
                    $quantity = $quantity * $factorToBase;
                }

                // Buscar o crear la relación product_location
                $productLocation = DB::table('product_location')
                    ->where('product_id', $product->id)
                    ->where('location_id', $locationId)
                    ->first();

                if ($productLocation) {
                    // Actualizar stock existente
                    $newStock = $productLocation->current_stock + $quantity;

                    DB::table('product_location')
                        ->where('product_id', $product->id)
                        ->where('location_id', $locationId)
                        ->update([
                            'current_stock' => $newStock,
                            'updated_at' => now()
                        ]);
                } else {
                    // Crear nueva relación con el stock
                    DB::table('product_location')->insert([
                        'product_id' => $product->id,
                        'location_id' => $locationId,
                        'current_stock' => $quantity,
                        'minimum_stock' => 0,
                        'maximum_stock' => null,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                // Registrar en kardex si existe la tabla
                try {
                    /*  DB::table('product_kardex')->insert([
                        'product_id' => $product->id,
                        'location_id' => $locationId,
                        'movement_id' => $purchase->id,
                        'movement_type' => 'entry',
                        'movement_reason' => 'purchase',
                        'quantity' => $quantity,
                        'unit_cost' => $detail->unit_cost,
                        'previous_stock' => $productLocation->current_stock ?? 0,
                        'new_stock' => ($productLocation->current_stock ?? 0) + $quantity,
                        'movement_date' => now(),
                        'notes' => "Compra recibida: {$purchase->document_number}",
                        'created_at' => now(),
                        'updated_at' => now()
                    ]); */
                } catch (\Exception $e) {
                    // Si la tabla kardex no existe, continuar
                    Log::warning('No se pudo registrar en kardex: ' . $e->getMessage());
                }
            }

            // Cambiar estado a received
            $purchase->status = 'received';
            $purchase->save();

            // Marcar la tarea de recepción como completada
            $task = Task::where('company_id', $purchase->company_id)
                ->where('location_id', $purchase->location_origin_id)
                ->where('type', 'stock_check')
                ->where('title', 'like', "Recibir Compra #{$purchase->id}%")
                ->whereIn('status', ['pending', 'in_progress'])
                ->first();

            if ($task) {
                $task->status = 'completed';
                $task->completed_at = now();
                $task->save();

                Log::info('Task marked as completed after receiving purchase', [
                    'task_id' => $task->id,
                    'purchase_id' => $purchase->id,
                ]);
            }

            // Notificar que la compra fue recibida
            $purchase->load(['supplier', 'details.product']);
            $products = $purchase->details->map(function ($detail) {
                return [
                    'name' => $detail->product->name,
                    'quantity' => $detail->quantity,
                ];
            })->toArray();

            NotificationService::notifyPurchaseReceived(
                $purchase->company_id,
                $purchase->id,
                $purchase->supplier->name,
                $purchase->reference ?? 'N/A',
                $purchase->purchase_date ?? now()->format('Y-m-d'),
                $products
            );

            DB::commit();

            // Recargar con relaciones
            $purchase->load($this->getShowRelations());

            return response()->json([
                'success' => true,
                'message' => 'Compra recibida exitosamente. El stock ha sido actualizado.',
                'data' => new $this->resource($purchase, ['editing' => true])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al recibir compra: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al recibir la compra: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un detalle de la compra (carrito)
     * PUT /purchases/{id}/details
     */
    public function updateDetails(Request $request, $id)
    {
        try {
            $purchase = Purchase::findOrFail($id);

            // Solo permitir actualizar detalles en estado draft
            if ($purchase->status !== 'draft' && $purchase->status !== \App\Enums\PurchaseStatus::DRAFT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden actualizar detalles en compras en estado borrador'
                ], 422);
            }

            $validated = $request->validate([
                'action' => 'required|in:add,update,remove,clear,change_unit',
                'product_id' => 'required_unless:action,clear|exists:products,id',
                'quantity' => 'required_if:action,add,update|numeric|min:0.001',
                'unit_id' => 'nullable|exists:units,id',
                'unit_price' => 'required_if:action,add,update|numeric|min:0',
                'subtotal' => 'required_if:action,add,update|numeric|min:0',
            ]);

            DB::beginTransaction();

            $action = $validated['action'];
            $productId = $validated['product_id'] ?? null;

            switch ($action) {
                case 'add':
                    // Buscar si ya existe el detalle
                    $detail = $purchase->details()
                        ->where('product_id', $productId)
                        ->where('unit_id', $validated['unit_id'] ?? null)
                        ->first();

                    if ($detail) {
                        // Si existe, actualizar cantidad
                        $detail->update([
                            'quantity' => $detail->quantity + $validated['quantity'],
                            'unit_cost' => $validated['unit_price'],
                            'total_cost' => ($detail->quantity + $validated['quantity']) * $validated['unit_price'],
                        ]);
                    } else {
                        // Si no existe, crear nuevo
                        $purchase->details()->create([
                            'product_id' => $productId,
                            'quantity' => $validated['quantity'],
                            'unit_id' => $validated['unit_id'] ?? null,
                            'unit_cost' => $validated['unit_price'],
                            'total_cost' => $validated['subtotal'],
                        ]);
                    }
                    break;

                case 'update':
                    // Actualizar cantidad y precios
                    $detail = $purchase->details()
                        ->where('product_id', $productId)
                        ->where('unit_id', $validated['unit_id'] ?? null)
                        ->first();

                    if ($detail) {
                        $detail->update([
                            'quantity' => $validated['quantity'],
                            'unit_cost' => $validated['unit_price'],
                            'total_cost' => $validated['subtotal'],
                        ]);
                    }
                    break;

                case 'remove':
                    // Eliminar el detalle
                    $purchase->details()
                        ->where('product_id', $productId)
                        ->where('unit_id', $validated['unit_id'] ?? null)
                        ->delete();
                    break;

                case 'clear':
                    // Eliminar todos los detalles
                    $purchase->details()->delete();
                    break;

                case 'change_unit':
                    // Cambiar la unidad del detalle
                    $detail = $purchase->details()
                        ->where('product_id', $productId)
                        ->first();

                    if ($detail) {
                        $detail->update([
                            'unit_id' => $validated['unit_id'] ?? null,
                            'quantity' => $validated['quantity'],
                            'unit_cost' => $validated['unit_price'],
                            'total_cost' => $validated['subtotal'],
                        ]);
                    }
                    break;
            }

            // Recalcular total
            $totalAmount = $purchase->details()->sum('total_cost');
            $purchase->update(['total_cost' => $totalAmount]);

            DB::commit();

            // Recargar con relaciones
            $purchase->load($this->getShowRelations());

            return response()->json([
                'success' => true,
                'message' => 'Detalle actualizado correctamente',
                'data' => new PurchaseResource($purchase, ['editing' => true])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar detalle: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format phone number for WhatsApp (remove spaces, dashes, and +)
     */
    protected function formatPhoneNumber($phone)
    {
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Remove leading + if present
        $phone = ltrim($phone, '+');

        // Si tiene 10 dígitos (número local mexicano), agregar código de país
        if (strlen($phone) === 10) {
            $phone = '52' . $phone; // 52 (México) + 1 (celular)
        }

        return $phone;
    }
}
