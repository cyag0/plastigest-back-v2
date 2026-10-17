<?php

namespace App\Http\Controllers;

use App\Models\CashMovement;
use App\Models\PurchaseV2;
use App\Models\PurchaseDetailV2;
use App\Services\CashMovementService;
use App\Services\MovementService;
use App\Enums\StockLedgerReason;
use App\Support\StockContext;
use App\Services\TaskService;
use App\Notifications\NotificationEngine;
use App\Notifications\Templates\PurchaseUpdateTemplate;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class PurchaseV2Controller extends Controller
{
    public function __construct(
        private TaskService $taskService
    ) {}

    /**
     * Compras que el usuario puede tocar: las de la empresa actual y de su
     * sucursal actual; quien ve todas las sucursales, las de cualquier
     * sucursal de la empresa. Toda acción sobre una compra por id pasa por
     * aquí: confirmar, marcar en tránsito, cancelar y editar renglones la
     * buscaban solo por id y se podía actuar sobre la compra de otra sucursal
     * (o de otra empresa).
     */
    private function purchases(): Builder
    {
        return PurchaseV2::query()
            ->where('company_id', CurrentCompany::id())
            ->when(
                !CurrentWorker::canAccessAllLocations(),
                fn($q) => $q->where('location_id', CurrentLocation::id())
            );
    }

    /**
     * Renglón de una compra que el usuario puede tocar (ver purchases()).
     */
    private function findDetail($detailId): ?PurchaseDetailV2
    {
        return PurchaseDetailV2::query()
            ->whereIn('purchase_id', $this->purchases()->select('id'))
            ->find($detailId);
    }

    /**
     * Regla "existe en la tabla y es de la empresa actual" (productos,
     * empaques, proveedores).
     */
    private function companyRule(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('company_id', CurrentCompany::id());
    }

    /**
     * 404 de una compra (o renglón) que no existe o que el usuario no puede
     * ver: no se confirma que exista en otra sucursal.
     */
    private function purchaseNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Compra no encontrada',
        ], 404);
    }

    /**
     * El borrador de purchase_id con su fila bloqueada y el estado releído,
     * como al editar o quitar un renglón: si otro dispositivo lo está
     * confirmando (o registrando recibido), se espera y ya no entra nada.
     * Antes se leía sin bloqueo y un producto agregado en ese momento caía en
     * la compra ya recibida, sin stock ni kárdex, y le cambiaba el total.
     *
     * Una compra que ya no es borrador se rechaza (422): antes se creaba otro
     * borrador en silencio y el producto quedaba en una compra que la app no
     * tenía abierta. null si no viene o no es de la sucursal (se crea uno).
     */
    private function lockedDraft($purchaseId, int $companyId, int $locationId): PurchaseV2|JsonResponse|null
    {
        if (empty($purchaseId)) {
            return null;
        }

        $purchase = PurchaseV2::query()
            ->where('id', $purchaseId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($purchase && $purchase->status !== PurchaseV2::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Esta compra ya no está en borrador: sus productos ya no se pueden cambiar.',
            ], 422);
        }

        return $purchase;
    }

    /**
     * Crear o actualizar una compra en estado draft
     * Se llama cada vez que se agrega/modifica/elimina un producto
     */
    public function upsertDraft(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Llega como "true"/"false" (FormData) o 1/0; boolean() acepta todos.
        if ($request->filled('paid_from_cash')) {
            $request->merge(['paid_from_cash' => $request->boolean('paid_from_cash')]);
        }

        // Fuera del try: un dato inválido es un 422 con el detalle por campo.
        // Productos, empaques y proveedores tienen que ser de la empresa: una
        // compra de un producto ajeno sumaba stock (y kárdex) a otra empresa.
        $validated = $request->validate([
            'purchase_id' => 'nullable|exists:purchases,id',
            'supplier_id' => ['nullable', $this->companyRule('suppliers')],
            'notes' => 'nullable|string',
            'payment_method' => 'nullable|in:cash,card,transfer,other',
            'paid_from_cash' => 'nullable|boolean',
            'items' => 'nullable|array',
            'items.*.id' => 'required_with:items|string',
            'items.*.product_id' => ['required_with:items', 'integer', $this->companyRule('products')],
            'items.*.package_id' => ['nullable', 'integer', $this->companyRule('product_packages')],
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit_id' => 'required_with:items|integer|exists:units,id',
            'items.*.price' => 'required_with:items|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;
            $userId = Auth::id();

            // Buscar (bloqueado) o crear compra en draft
            $purchase = $this->lockedDraft($validated['purchase_id'] ?? null, $companyId, $locationId);
            if ($purchase instanceof JsonResponse) {
                DB::rollBack();
                return $purchase;
            }

            if (!$purchase) {
                $attributes = [
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'purchase_number' => PurchaseV2::generatePurchaseNumber(),
                    'purchase_date' => now(),
                    'status' => PurchaseV2::STATUS_DRAFT,
                    'notes' => $validated['notes'] ?? null,
                    'paid_from_cash' => $validated['paid_from_cash'] ?? true,
                    'user_id' => $userId,
                ];
                // Sin forma de pago se deja la de la columna: un null explícito
                // tronaba el alta del borrador ("payment_method cannot be null").
                if (!empty($validated['payment_method'])) {
                    $attributes['payment_method'] = $validated['payment_method'];
                }
                $purchase = PurchaseV2::create($attributes);
            } else {
                // Actualizar notas y supplier si cambiaron
                $purchase->update([
                    'supplier_id' => $validated['supplier_id'] ?? $purchase->supplier_id,
                    'notes' => $validated['notes'] ?? $purchase->notes,
                    'payment_method' => $validated['payment_method'] ?? $purchase->payment_method,
                    'paid_from_cash' => $validated['paid_from_cash'] ?? $purchase->paid_from_cash,
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
                'error' => $this->errorDetail($e)
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
     * Último precio pagado de cada producto y empaque: el del renglón más
     * reciente que de verdad llegó (cantidad recibida y precio > 0) en una
     * compra recibida de la empresa; con supplier_id, solo de ese proveedor.
     * La app lo propone al armar la compra: antes cada renglón tomaba el
     * precio de compra del catálogo (en $0 si nadie lo capturó) y los reportes
     * valúan el inventario con el de la última compra (ProductCosts), así que
     * un producto comprado "a $0" valía $0. Misma fecha de recepción y mismo
     * desempate que ProductCosts::loadLastPurchases().
     */
    public function lastPrices(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', $this->companyRule('suppliers')],
        ]);

        $receivedAt = 'COALESCE(purchase_details.received_at, purchases.delivery_date, purchases.updated_at)';

        // Una sola consulta: cada renglón numerado dentro de su producto y
        // empaque, del más reciente al más viejo, y se queda el primero.
        $ranked = DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->where('purchases.company_id', CurrentCompany::id())
            ->where('purchases.status', PurchaseV2::STATUS_RECEIVED)
            ->where('purchase_details.quantity_received', '>', 0)
            ->where('purchase_details.unit_price', '>', 0)
            ->when(
                $validated['supplier_id'] ?? null,
                fn($q, $supplierId) => $q->where('purchases.supplier_id', $supplierId)
            )
            ->select([
                'purchase_details.product_id',
                'purchase_details.package_id',
                'purchase_details.unit_id',
                'purchase_details.unit_price',
                'purchases.id as purchase_id',
                'purchases.purchase_number',
            ])
            ->selectRaw("DATE({$receivedAt}) as received_at")
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY purchase_details.product_id, purchase_details.package_id'
                . " ORDER BY {$receivedAt} DESC, purchase_details.id DESC) as position"
            );

        $prices = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('position', 1)
            ->orderBy('product_id')
            ->orderBy('package_id')
            ->get()
            ->map(fn($row) => [
                'product_id' => (int) $row->product_id,
                'package_id' => $row->package_id !== null ? (int) $row->package_id : null,
                'unit_id' => (int) $row->unit_id,
                'unit_price' => (float) $row->unit_price,
                'purchase_id' => (int) $row->purchase_id,
                'purchase_number' => $row->purchase_number,
                'received_at' => $row->received_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => $prices,
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
            $purchase = $this->purchases()
                ->where('id', $id)
                ->with([
                    'details.product.mainImage', 'details.package', 'details.unit', 'supplier',
                    'user:id,name', 'receivedBy:id,name',
                ])
                ->firstOrFail();

            // delivery_date guarda solo la fecha: la hora de la recepción sale
            // de los renglones, que se marcan recibidos en esa misma recepción.
            $receivedAt = $purchase->details->pluck('received_at')->filter()->max();

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => [
                        'id' => $purchase->id,
                        'supplier_id' => $purchase->supplier_id,
                        'supplier_name' => $purchase->supplier?->name,
                        'purchase_date' => $purchase->purchase_date?->format('Y-m-d'),
                        // Cuándo llegó (o se espera) y quién la hizo y recibió,
                        // para el detalle.
                        'expected_delivery_date' => $purchase->expected_delivery_date?->format('Y-m-d'),
                        'delivery_date' => $purchase->delivery_date
                            ? ($receivedAt ?? $purchase->delivery_date)->format('Y-m-d H:i')
                            : null,
                        'created_by_name' => $purchase->user?->name,
                        'received_by_name' => $purchase->receivedBy?->name,
                        'purchase_number' => $purchase->purchase_number,
                        'notes' => $purchase->notes,
                        'document_number' => $purchase->document_number,
                        'status' => $purchase->status,
                        'total' => $purchase->total,
                        'payment_method' => $purchase->payment_method,
                        'paid_from_cash' => (bool) ($purchase->paid_from_cash ?? true),
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
                                'received_at' => $detail->received_at?->format('Y-m-d H:i'),
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
                'error' => $this->errorDetail($e)
            ], 404);
        }
    }

    /**
     * Confirmar compra: de borrador a pedida (ordered) o, con receive_now, a
     * recibida en el mismo paso.
     */
    public function confirm(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Llegan como "true"/"false" (FormData) o 1/0; boolean() acepta todos
        // ("false" es false).
        foreach (['paid_from_cash', 'receive_now'] as $flag) {
            if ($request->filled($flag)) {
                $request->merge([$flag => $request->boolean($flag)]);
            }
        }
        $receiveNow = $request->boolean('receive_now');

        // Fuera del try: un dato inválido es un 422 con el detalle por campo,
        // no un "Error al confirmar la compra" 500.
        $validated = $request->validate([
            'expected_delivery_date' => 'nullable|date|after_or_equal:today',
            'document_number' => 'nullable|string|max:255',
            'payment_method' => 'nullable|in:cash,card,transfer,other',
            // ¿El dinero salió de la caja? Si no, al recibir no se registra
            // egreso en el corte.
            'paid_from_cash' => 'nullable|boolean',
            // El encabezado se puede editar sobre un borrador ya creado.
            'supplier_id' => ['nullable', $this->companyRule('suppliers')],
            // Lo que ya llegó no puede tener fecha posterior a hoy: el stock
            // entraba hoy y el egreso de caja quedaba fechado en el futuro,
            // fuera de los totales del día en que salió el dinero. Mañana sí
            // pasa (abajo se toma como hoy): un celular con la zona horaria
            // vieja, que aún adelanta la hora en verano, ya está en el día
            // siguiente entre las 11 y las 12 de la noche y no podía registrar.
            'purchase_date' => [
                'nullable', 'date',
                ...($receiveNow ? ['before:' . today()->addDays(2)->toDateString()] : []),
            ],
            // El formulario de la app manda las notas al confirmar (el
            // borrador nace con el primer producto, sin encabezado).
            'notes' => 'nullable|string|max:2000',
            // «Registrar compra recibida»: la mercancía ya está aquí (se
            // compró en la tienda y se trajo), así que entra al inventario al
            // confirmar. Antes toda compra pasaba por pedida, en tránsito y
            // recibida: tres confirmaciones más después de armarla.
            'receive_now' => 'nullable|boolean',
        ], [
            'purchase_date.before' => 'Una compra que ya llegó no puede tener fecha posterior a hoy.',
        ]);

        // El día siguiente por el reloj del celular es hoy.
        if ($receiveNow && !empty($validated['purchase_date'])
            && \Illuminate\Support\Carbon::parse($validated['purchase_date'])->isAfter(today())) {
            $validated['purchase_date'] = today()->toDateString();
        }

        try {
            $result = DB::transaction(function () use ($id, $validated, $receiveNow) {
                // Con la fila bloqueada: un doble envío confirmaba dos veces y
                // le mandaba el pedido por WhatsApp dos veces al proveedor.
                $purchase = $this->purchases()->lockForUpdate()->find($id);

                if (!$purchase) {
                    return $this->purchaseNotFound();
                }

                if ($purchase->status !== PurchaseV2::STATUS_DRAFT) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Solo se puede confirmar una compra en borrador.',
                    ], 422);
                }

                if ($purchase->details()->count() === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se puede confirmar una compra sin productos'
                    ], 422);
                }

                $header = [
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'document_number' => $validated['document_number'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? $purchase->payment_method ?? 'other',
                    'paid_from_cash' => $validated['paid_from_cash'] ?? $purchase->paid_from_cash,
                    'supplier_id' => $validated['supplier_id'] ?? $purchase->supplier_id,
                    'purchase_date' => $validated['purchase_date'] ?? $purchase->purchase_date,
                    // Vacías se borran; si no vienen (versiones viejas de la
                    // app) se quedan las del borrador.
                    'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $purchase->notes,
                ];

                // Registrada recibida, nunca fue un pedido: se guarda el
                // encabezado sin pasar por «pedida» y la recepción de abajo
                // deja un solo paso, de borrador a recibida (y en la bitácora
                // solo «Recibió»). Antes quedaban «pedida» y «recibida»
                // seguidas, como si se le hubiera pedido al proveedor.
                if (!$receiveNow) {
                    $purchase->appendStatusLog(PurchaseV2::STATUS_ORDERED, Auth::id());
                    $header['status'] = PurchaseV2::STATUS_ORDERED;
                }

                $purchase->update($header);

                // Llegó todo: se recibe con la misma recepción que receive()
                // (kárdex, egreso de caja, tareas y aviso), con la compra ya
                // bloqueada.
                if ($receiveNow) {
                    $lines = $purchase->details()->get(['id', 'quantity'])
                        ->map(fn(PurchaseDetailV2 $detail) => [
                            'id' => $detail->id,
                            'quantity_received' => $detail->quantity,
                        ])
                        ->all();

                    $this->receiveLockedPurchase($purchase, $lines);
                }

                return $purchase;
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

            $purchase = $result;

            // Ya está aquí: no hay pedido que mandarle al proveedor.
            if ($receiveNow) {
                return response()->json([
                    'success' => true,
                    'message' => 'Compra registrada como recibida',
                    'received' => true,
                    'whatsapp_sent' => false,
                    'cash_out_of_shift' => $this->cashOutOfShift($purchase),
                    'data' => $purchase->fresh(['details']),
                ]);
            }

            $whatsappSent = $this->sendOrderToSupplier($purchase);

            return response()->json([
                'success' => true,
                'received' => false,
                'whatsapp_sent' => $whatsappSent,
                'message' => 'Compra confirmada exitosamente',
                'data' => $purchase,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar la compra',
                'error' => $this->errorDetail($e)
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
            $result = DB::transaction(function () use ($id) {
                $purchase = $this->purchases()->lockForUpdate()->find($id);

                if (!$purchase) {
                    return $this->purchaseNotFound();
                }

                // Puede que el proveedor ya haya contestado por WhatsApp y el
                // webhook la haya pasado a tránsito.
                if ($purchase->status !== PurchaseV2::STATUS_ORDERED) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Solo se puede marcar en tránsito una compra pedida.',
                    ], 422);
                }

                $purchase->appendStatusLog(PurchaseV2::STATUS_IN_TRANSIT, Auth::id());

                $purchase->update([
                    'status' => PurchaseV2::STATUS_IN_TRANSIT,
                ]);

                return $purchase;
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

            $purchase = $result;

            try {
                $this->taskService->createFromPurchaseV2($purchase);
            } catch (Exception $taskException) {
                Log::warning('Could not create receive_purchase task for PurchaseV2 in transit', [
                    'purchase_id' => $purchase->id,
                    'error' => $taskException->getMessage(),
                ]);
            }

            // La plantilla tenía el caso "en camino" pero nunca se avisaba.
            PurchaseUpdateTemplate::dispatchFor($purchase, 'in_transit', Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Compra marcada como en tránsito',
                'data' => $purchase,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Recibir compra (pedida o en tránsito) y actualizar inventario
     */
    public function receive(Request $request, $id)
    {
        if (!CurrentWorker::hasPermission('purchases_update')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            'details' => 'required|array',
            'details.*.id' => 'required|distinct|exists:purchase_details,id',
            'details.*.quantity_received' => 'required|numeric|min:0',
            // Lo que de verdad se pagó, si al llegar no es el precio del
            // pedido (cambió, o iba en $0 porque el proveedor lo da al
            // entregar). Sin él se queda el del pedido. Antes el precio quedaba
            // fijo al confirmar: el egreso de caja y el costo de los reportes
            // (último precio de compra) salían con el precio del pedido.
            'details.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $purchase = $this->purchases()->with('details.product')->find($id);
        if (!$purchase) {
            return $this->purchaseNotFound();
        }

        // Se recibe lo que llegó, de 0 a lo pedido, en la misma unidad (o
        // empaque) del renglón. Lo pedido ya no cambia después del borrador,
        // así que se puede revisar antes de bloquear la compra.
        $errors = [];
        $details = $purchase->details->keyBy('id');
        foreach ($validated['details'] as $index => $line) {
            $detail = $details->get((int) $line['id']);
            if (!$detail) {
                $errors["details.{$index}.id"] = ['Ese renglón no es de esta compra.'];
                continue;
            }

            if ((float) $line['quantity_received'] > (float) $detail->quantity + 0.0001) {
                $errors["details.{$index}.quantity_received"] = [sprintf(
                    'De %s se pidieron %s: no se puede recibir más de lo pedido.',
                    $detail->product?->name ?? 'este producto',
                    rtrim(rtrim(number_format((float) $detail->quantity, 4, '.', ''), '0'), '.')
                )];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $result = DB::transaction(fn() => $this->receivePurchase($id, $validated['details']));

            if ($result instanceof JsonResponse) {
                return $result;
            }

            return response()->json([
                'success' => true,
                'message' => 'Compra recibida exitosamente',
                'cash_out_of_shift' => $this->cashOutOfShift($result),
                'data' => $result->fresh(['details']),
            ]);
        } catch (Exception $e) {
            Log::error('Error al recibir la compra', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al recibir la compra',
            ], 500);
        }
    }

    /**
     * Recepción dentro de la transacción de receive(). Devuelve la compra
     * recibida o la respuesta de error (404 / 422).
     */
    private function receivePurchase($id, array $lines): PurchaseV2|JsonResponse
    {
        // Bloqueo de fila + relectura del estado: sin esto, un doble envío
        // (doble clic / dos dispositivos) recibía la compra dos veces y
        // sumaba stock y caja por duplicado. Aislado por empresa/sucursal.
        $purchase = $this->purchases()->lockForUpdate()->find($id);

        if (!$purchase) {
            return $this->purchaseNotFound();
        }

        // Una compra pedida también se recibe: «en tránsito» es opcional (lo
        // marca el proveedor por WhatsApp o quien quiere avisar que ya viene).
        // Antes solo se recibía en tránsito y había que marcarla primero.
        if (!in_array($purchase->status, [PurchaseV2::STATUS_ORDERED, PurchaseV2::STATUS_IN_TRANSIT], true)) {
            return response()->json([
                'success' => false,
                'message' => 'La compra ya fue recibida, o no está pedida ni en tránsito.',
            ], 422);
        }

        return $this->receiveLockedPurchase($purchase, $lines);
    }

    /**
     * Recibe una compra ya bloqueada (lockForUpdate) y con el estado ya
     * revisado, dentro de la transacción de quien llama: receive() y
     * confirm() con receive_now. Así las dos registran lo mismo: kárdex,
     * egreso de caja por lo recibido, discrepancias, tareas y aviso.
     *
     * @param array<int, array{id: int, quantity_received: float|string, unit_price?: float|string|null}> $lines
     */
    private function receiveLockedPurchase(PurchaseV2 $purchase, array $lines): PurchaseV2
    {
        // El turno de quien recibe (de donde sale el pago) se bloquea antes que
        // las existencias: mismo orden que una venta (turno → stock), para que
        // una venta simultánea en ese turno no se cruce con esta recepción.
        // fromPurchaseV2 vuelve a leerlo, ya bloqueado.
        if ($purchase->paid_from_cash !== false) {
            CashMovementService::lockedOpenSessionIdFor(Auth::id(), $purchase->location_id);
        }

        $movementService = new MovementService();

        // Las existencias se bloquean en orden de producto, como en una venta
        // (Sale::assertStockNotReserved) y en las transferencias. Antes se
        // recorrían en el orden de los renglones (o de la petición) y una
        // venta simultánea de esos productos, que los toma de menor a mayor,
        // se trababa con la recepción (deadlock) y perdía la venta o la compra.
        $details = $purchase->details()->findOrFail(array_column($lines, 'id'))->keyBy('id');
        usort($lines, fn(array $a, array $b) => [(int) $details[(int) $a['id']]->product_id, (int) $a['id']]
            <=> [(int) $details[(int) $b['id']]->product_id, (int) $b['id']]);

        // Actualizar cantidades recibidas e incrementar stock
        $repriced = false;
        foreach ($lines as $detailData) {
            $detail = $details[(int) $detailData['id']];
            $changes = [
                'quantity_received' => $detailData['quantity_received'],
                'received_at' => now(),
            ];
            // El precio pagado reemplaza al del pedido antes del egreso, y el
            // renglón y la compra recalculan su total al guardarse.
            if (isset($detailData['unit_price'])) {
                $changes['unit_price'] = $detailData['unit_price'];
                $repriced = true;
            }
            $detail->update($changes);

            // Lo que no llegó no mueve stock (ni deja un renglón en cero en
            // el kárdex).
            if ((float) $detail->quantity_received <= 0) {
                continue;
            }

            // Incrementar stock usando MovementService
            $movementService->increment(
                $purchase->location_id,
                $detail->product_id,
                $detail->unit_id,
                $detail->quantity_received,
                $detail->package_id,
                StockContext::for(StockLedgerReason::PURCHASE, $purchase)
            );
        }

        // El total nuevo ya se guardó con cada renglón; se relee para el aviso.
        if ($repriced) {
            $purchase->refresh();
        }

        $purchase->appendStatusLog(PurchaseV2::STATUS_RECEIVED, Auth::id());

        $purchase->update([
            'status' => PurchaseV2::STATUS_RECEIVED,
            'delivery_date' => now(),
            'received_by' => Auth::id(),
        ]);

        // Registrar egreso en caja por lo que de verdad llegó (y se pagó):
        // recibido × precio de cada renglón, no el total pedido. Si de 100
        // cocos llegan 90, al proveedor se le pagan 90 y el corte de caja
        // tiene que cuadrar con eso. El total de la compra se queda como lo
        // pedido; la diferencia es la discrepancia de abajo.
        $receivedTotal = round((float) $purchase->details()->get()->sum(
            fn(PurchaseDetailV2 $detail) => round((float) ($detail->quantity_received ?? 0) * (float) $detail->unit_price, 2)
        ), 2);
        // Fechado el día de la compra, pero nunca después del día en que se
        // recibe: un pedido con fecha adelantada que llegaba antes dejaba su
        // egreso en el futuro, fuera de los totales de caja del día.
        $cashDate = $purchase->purchase_date?->isAfter(today()) ? today() : $purchase->purchase_date;
        CashMovementService::fromPurchaseV2((clone $purchase)->forceFill([
            'total' => $receivedTotal,
            'purchase_date' => $cashDate,
        ]));

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

        // Sale al confirmar la transacción (NotificationEngine::afterCommit).
        PurchaseUpdateTemplate::dispatchFor($purchase, 'received', Auth::id());

        return $purchase;
    }

    /**
     * ¿El pago salió de la caja fuera de turno? Pasa cuando quien la recibe
     * (o la registra recibida) no tiene turno abierto en la sucursal: ningún
     * corte lo descuenta y el cajero de quien tomó el efectivo sale con
     * faltante. La respuesta lo dice para que la app avise, en lugar de
     * prometer que se descuenta en el corte.
     */
    private function cashOutOfShift(PurchaseV2 $purchase): bool
    {
        return CashMovement::query()
            ->where('source_type', 'purchase_v2')
            ->where('source_id', $purchase->id)
            ->whereNull('cash_register_session_id')
            ->exists();
    }

    /**
     * Agregar un detalle individual a la compra draft
     */
    public function addDetail(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_create')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // Fuera del try y de la empresa, como en upsertDraft().
        $validated = $request->validate([
            'purchase_id' => 'nullable|exists:purchases,id',
            'supplier_id' => ['nullable', $this->companyRule('suppliers')],
            'product_id' => ['required', 'integer', $this->companyRule('products')],
            'package_id' => ['nullable', 'integer', $this->companyRule('product_packages')],
            'quantity' => 'required|numeric|min:0.0001',
            'unit_id' => 'required|integer|exists:units,id',
            'price' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $companyId = CurrentCompany::get()->id;
            $locationId = CurrentLocation::get()->id;
            $userId = Auth::id();

            // Buscar (bloqueado) o crear compra en draft
            $purchase = $this->lockedDraft($validated['purchase_id'] ?? null, $companyId, $locationId);
            if ($purchase instanceof JsonResponse) {
                DB::rollBack();
                return $purchase;
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
                'error' => $this->errorDetail($e)
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

        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.0001',
            'unit_id' => 'nullable|integer|exists:units,id',
            'price' => 'nullable|numeric|min:0',
        ]);

        $detail = $this->findDetail($detailId);
        if (!$detail) {
            return $this->purchaseNotFound();
        }

        try {
            $purchase = DB::transaction(function () use ($detail, $validated) {
                // Bloqueada como al confirmar: el cambio entra antes de la
                // confirmación o se rechaza, no a medias.
                $purchase = PurchaseV2::query()->lockForUpdate()->find($detail->purchase_id);

                // Verificar que la compra esté en draft
                if ($purchase->status !== PurchaseV2::STATUS_DRAFT) {
                    return null;
                }

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

                return $purchase;
            });

            if (!$purchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar compras en borrador',
                ], 400);
            }

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
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar producto',
                'error' => $this->errorDetail($e)
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

        $detail = $this->findDetail($detailId);
        if (!$detail) {
            return $this->purchaseNotFound();
        }

        try {
            $purchase = DB::transaction(function () use ($detail) {
                $purchase = PurchaseV2::query()->lockForUpdate()->find($detail->purchase_id);

                // Verificar que la compra esté en draft
                if ($purchase->status !== PurchaseV2::STATUS_DRAFT) {
                    return null;
                }

                $detail->delete();
                $purchase->updateTotal();

                return $purchase;
            });

            if (!$purchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar compras en borrador',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado',
                'data' => [
                    'total' => $purchase->total,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Eliminar compra (solo draft o canceladas)
     */
    public function destroy($id)
    {
        if (!CurrentWorker::hasPermission('purchases_delete')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $purchase = $this->purchases()->find($id);
        if (!$purchase) {
            return $this->purchaseNotFound();
        }

        if (!in_array($purchase->status, [PurchaseV2::STATUS_DRAFT, PurchaseV2::STATUS_CANCELLED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar compras en borrador o canceladas.',
            ], 422);
        }

        try {
            $purchase->details()->delete();
            $purchase->delete();

            return response()->json([
                'success' => true,
                'message' => 'Compra eliminada correctamente',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la compra',
                'error' => $this->errorDetail($e)
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

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = DB::transaction(function () use ($id, $validated) {
                // Bloqueada: una compra que se está recibiendo no se cancela a
                // la mitad (ni al revés).
                $purchase = $this->purchases()->lockForUpdate()->find($id);

                if (!$purchase) {
                    return $this->purchaseNotFound();
                }

                if (!in_array($purchase->status, [
                    PurchaseV2::STATUS_DRAFT,
                    PurchaseV2::STATUS_ORDERED,
                    PurchaseV2::STATUS_IN_TRANSIT,
                ], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Esta compra ya no se puede cancelar: está recibida o cancelada.',
                    ], 422);
                }

                $purchase->appendStatusLog(
                    PurchaseV2::STATUS_CANCELLED,
                    Auth::id(),
                    $validated['reason'] ?? null
                );

                $purchase->update([
                    'status' => PurchaseV2::STATUS_CANCELLED,
                ]);

                return $purchase;
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

            $purchase = $result;

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
                'error' => $this->errorDetail($e)
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

        $validated = $request->validate([
            'resolution' => 'required|in:credit_note,adjustment,no_action,other',
            'notes' => 'nullable|string|max:1000',
            'adjustment_id' => 'nullable|integer|exists:movements,id',
        ]);

        $purchase = $this->purchases()->find($id);
        if (!$purchase) {
            return $this->purchaseNotFound();
        }

        try {
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
                        'error' => $this->errorDetail($e),
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
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Estadísticas de compras v2
     */
    public function stats(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::get()->id;
        $locationId = $this->requestedLocation($request);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = PurchaseV2::where('company_id', $companyId)
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->whereDate('purchase_date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('purchase_date', '<=', $endDate));

        $totalPurchases = (clone $query)->count();
        $totalAmount = (clone $query)->sum('total');
        $averageAmount = $totalPurchases > 0 ? $totalAmount / $totalPurchases : 0;
        $receivedCount = (clone $query)->where('status', PurchaseV2::STATUS_RECEIVED)->count();
        $pendingCount = (clone $query)->whereIn('status', [PurchaseV2::STATUS_ORDERED, PurchaseV2::STATUS_IN_TRANSIT])->count();

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status => ['count' => $item->count, 'total' => (float) $item->total]]);

        $topSuppliers = (clone $query)
            ->select('supplier_id', DB::raw('COUNT(*) as purchase_count'), DB::raw('SUM(total) as total_amount'))
            ->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->with('supplier:id,name')
            ->get()
            ->map(fn($item) => [
                'supplier_name' => $item->supplier?->name ?? 'Sin proveedor',
                'purchase_count' => $item->purchase_count,
                'total_amount' => (float) $item->total_amount,
            ]);

        $purchaseIds = (clone $query)->pluck('id');

        $topProducts = PurchaseDetailV2::whereIn('purchase_id', $purchaseIds)
            ->with('product:id,name')
            ->get()
            ->groupBy('product_id')
            ->map(fn($rows) => [
                'product_name' => $rows->first()?->product?->name ?? 'Sin nombre',
                'total_quantity' => (float) $rows->sum('quantity'),
                'total_amount' => (float) $rows->sum('total'),
            ])
            ->sortByDesc('total_amount')
            ->take(10)
            ->values();

        $sixMonthsAgo = now()->subMonths(6)->startOfMonth();
        $purchaseTrend = (clone $query)
            ->where('purchase_date', '>=', $sixMonthsAgo)
            ->select(
                DB::raw('DATE_FORMAT(purchase_date, "%Y-%m") as month'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as total')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($item) => [
                'month' => $item->month,
                'count' => $item->count,
                'total' => (float) $item->total,
            ]);

        return response()->json([
            'data' => [
                'total_purchases' => $totalPurchases,
                'total_amount' => (float) $totalAmount,
                'average_amount' => (float) $averageAmount,
                'received_count' => $receivedCount,
                'pending_count' => $pendingCount,
                'by_status' => $byStatus,
                'top_suppliers' => $topSuppliers,
                'top_products' => $topProducts,
                'purchase_trend' => $purchaseTrend,
            ],
        ]);
    }

    /**
     * Listar compras
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('purchases_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $locationId = $this->requestedLocation($request);

        // Solo lo que la lista muestra: proveedor, quién la hizo (sin su
        // correo) y cuántos productos lleva, no todos los renglones.
        $query = PurchaseV2::with(['supplier:id,name', 'user:id,name'])
            ->withCount(['details as products_count'])
            ->where('company_id', CurrentCompany::id())
            ->when($locationId, fn($q) => $q->where('location_id', $locationId));

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->input('supplier_id'));
        }

        // Mismos filtros de fecha que el resto de las listas (CrudController).
        $dateFrom = $request->input('date_from') ?? $request->input('start_date');
        $dateTo = $request->input('date_to') ?? $request->input('end_date');
        $query->when($dateFrom, fn($q) => $q->whereDate('purchase_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('purchase_date', '<=', $dateTo));

        // El buscador de la lista: folio, número de documento del proveedor,
        // nombre del proveedor o notas.
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('purchase_number', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        // Siempre paginada, con el sobre data/links/meta de las demás listas
        // (CrudController): la app la muestra con AppList y su paginador.
        // Antes devolvía el paginador crudo de 20 sin `meta` y la app no
        // podía pasar de la primera página.
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $purchases = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return JsonResource::collection($purchases);
    }

    /**
     * Sucursal de una lista o estadística de compras: la del filtro
     * location_id para quien ve todas las sucursales ('all' = todas) y, si no
     * lo manda, la actual. Un usuario de una sola sucursal siempre ve la suya,
     * diga lo que diga el filtro.
     */
    private function requestedLocation(Request $request): ?int
    {
        return CurrentWorker::locationScope(
            $request->filled('location_id') ? $request->input('location_id') : CurrentLocation::id()
        );
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

        // Validar que la compra pertenece a la compañía actual (anti-IDOR cross-tenant).
        $purchase = $this->purchases()->find($id);
        if (!$purchase) {
            return $this->purchaseNotFound();
        }

        try {
            $companyId = CurrentCompany::id();

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

    /**
     * Envía el pedido al proveedor por WhatsApp. Es la primera mitad del
     * flujo: el proveedor responde "ya lo envié" y el webhook pasa la compra a
     * tránsito. Las compras v1 lo hacían; al pasar a v2 se había perdido.
     *
     * Si WhatsApp no está configurado o falla, la compra queda confirmada de
     * todos modos (en v1 un fallo de WhatsApp impedía confirmarla).
     */
    private function sendOrderToSupplier(PurchaseV2 $purchase): bool
    {
        $purchase->loadMissing(['supplier', 'location', 'details.product', 'details.unit']);
        $phone = $purchase->supplier?->phone;

        if (!$phone || !\App\Services\WhatsAppService::isConfigured()) {
            return false;
        }

        try {
            return (bool) app(\App\Services\WhatsAppService::class)->sendPurchaseOrder(
                \App\Services\WhatsAppService::formatPhoneNumber($phone),
                $purchase
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el pedido por WhatsApp', [
                'purchase_id' => $purchase->id,
                'error' => $this->errorDetail($e),
            ]);
            return false;
        }
    }
}
