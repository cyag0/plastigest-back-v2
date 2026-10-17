<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use App\Enums\AdjustmentReasonCode;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Concerns\EvidencePhotoMessages;
use App\Http\Resources\InventoryAdjustmentDetailResource;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Product;
use App\Enums\StockLedgerReason;
use App\Support\Activity\ActivityFormatter;
use App\Support\Activity\ActivityLogger;
use App\Support\AdjustmentOrigins;
use App\Support\StockContext;
use App\Models\Unit;
use App\Notifications\NotificationEngine;
use App\Services\MovementService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Utils\AppUploadUtil;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class InventoryAdjustmentController extends Controller
{
    use EvidencePhotoMessages;

    public function __construct(private readonly MovementService $movementService) {}

    /**
     * Listado de ajustes (pantalla "Ajustes de inventario").
     *
     * Responde con la forma estándar de los listados paginados ({data, links,
     * meta}), la que lee AppList: antes la paginación venía en "pagination" y
     * solo se veían los 20 más recientes. Filtros: location_id (solo para
     * quien ve todas las sucursales), direction (in/out, el filtro "Tipo"),
     * reason_code, product_id, search (producto o notas), status (active: sin
     * deshacer; reverted: deshechos y mermas de producción revertidas), origin
     * (manual, production_order, inventory_count, transfer) y fechas
     * (date_from/date_to o start_date/end_date).
     *
     * Las fechas se comparan con la del ajuste (applied_at, o la de registro
     * si no tiene), como el reporte de mermas: una merma de ayer registrada
     * hoy sale en ayer.
     */
    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_list')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        // Un usuario básico solo ve los ajustes de su sucursal; quien ve todas,
        // la que pida o todas.
        $locationId = CurrentWorker::locationScope($request->input('location_id'));

        try {
            $query = InventoryAdjustmentDetail::with([
                'location',
                'createdByUser',
                'product.mainImage',
                'unit',
            ])
                ->where('company_id', $companyId)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId));

            if ($request->filled('reason_code')) {
                $reasons = is_array($request->reason_code)
                    ? $request->reason_code
                    : explode(',', (string) $request->reason_code);
                $query->whereIn('reason_code', array_filter(array_map('trim', $reasons)));
            }

            if (in_array($request->input('direction'), ['in', 'out'], true)) {
                $query->where('direction', $request->input('direction'));
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->input('product_id'));
            }

            if ($request->input('status') === 'active') {
                $query->reverted(false);
            } elseif ($request->input('status') === 'reverted') {
                $query->reverted();
            }

            if (in_array($request->input('origin'), InventoryAdjustmentDetail::ORIGINS, true)) {
                $query->origin($request->input('origin'));
            }

            $search = trim((string) $request->input('search', ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->whereHas('product', fn($product) => $product->where('name', 'like', $like)->orWhere('code', 'like', $like))
                        ->orWhere('notes', 'like', $like);
                });
            }

            $dateFrom = $request->input('date_from', $request->input('start_date'));
            $dateTo = $request->input('date_to', $request->input('end_date'));
            $adjustmentDate = 'COALESCE(inventory_adjustment_details.applied_at, inventory_adjustment_details.created_at)';
            if ($dateFrom) {
                $query->whereRaw("{$adjustmentDate} >= ?", [Carbon::parse($dateFrom)->startOfDay()->toDateTimeString()]);
            }
            if ($dateTo) {
                $query->whereRaw("{$adjustmentDate} <= ?", [Carbon::parse($dateTo)->endOfDay()->toDateTimeString()]);
            }

            $perPage = max(1, min((int) $request->input('per_page', 15), 100));
            $records = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

            // Folios de las órdenes y transferencias, nombres de los conteos y
            // quién deshizo cada ajuste: una consulta por tipo, no por renglón.
            AdjustmentOrigins::load($records->getCollection());

            return InventoryAdjustmentDetailResource::collection($records)->response();
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Error al listar ajustes');
        }
    }

    public function show(int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $detail = InventoryAdjustmentDetail::with([
            'location',
            'createdByUser',
            'product.mainImage',
            'unit',
        ])->where('company_id', CurrentCompany::id())->find($id);

        // De la empresa y de una sucursal a la que el usuario puede entrar.
        // Antes se abría cualquier ajuste por id, de cualquier sucursal o empresa.
        if (!$detail || !CurrentWorker::canAccessLocation((int) $detail->location_id)) {
            return response()->json(['message' => 'Ajuste no encontrado'], 404);
        }

        try {
            return response()->json([
                'message' => 'Detalle de ajuste obtenido exitosamente',
                'data' => new InventoryAdjustmentDetailResource($detail),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Error al obtener el ajuste');
        }
    }

    /**
     * Registra uno o varios ajustes (un renglón por producto) en la sucursal
     * actual y mueve su existencia.
     *
     * Cada renglón trae direction y quantity o, con el motivo «Diferencia de
     * conteo», counted_quantity: cuántos hay en realidad, en la unidad
     * elegida. Con counted_quantity la diferencia la calcula el servidor
     * contra la existencia con su fila bloqueada: la que veía la app pudo
     * cambiar con una venta mientras se contaba.
     */
    public function store(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_manage')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            // Hoy, un día pasado o mañana por el reloj del celular (ver appliedAt()).
            'applied_at' => ['bail', 'nullable', 'date', 'before:' . today()->addDays(2)->toDateString()],
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            // Productos de la empresa (antes bastaba con que existieran).
            'details.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', CurrentCompany::id())],
            'details.*.direction' => ['bail', 'required_without:details.*.counted_quantity', 'nullable', 'in:in,out', $this->reasonDirectionRule($request)],
            'details.*.quantity' => 'required_without:details.*.counted_quantity|nullable|numeric|min:0.001',
            'details.*.counted_quantity' => 'nullable|numeric|min:0|prohibited_unless:details.*.reason_code,count_diff',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.reason_code' => 'required|in:loss,damage,count_diff,expiry,theft,found,other',
            'details.*.notes' => 'nullable|string',
            'details.*.evidence' => 'nullable|array',
            'details.*.evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], array_merge(
            [
                'details.*.product_id.exists' => 'Uno de los productos no existe en tu empresa.',
                'applied_at.before' => 'La fecha del ajuste no puede ser posterior a hoy.',
                'details.*.direction.required_without' => 'Indica si el ajuste suma o resta existencia.',
                'details.*.quantity.required_without' => 'Indica la cantidad del ajuste.',
                'details.*.counted_quantity.prohibited_unless' => 'La cantidad contada solo se usa con el motivo «Diferencia de conteo».',
            ],
            $this->evidencePhotoMessages(['details.*.evidence.*'])
        ));

        try {
            $companyId = CurrentCompany::get()?->id;
            if (!$companyId) {
                return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
            }

            $locationId = CurrentLocation::id();
            if (!$locationId) {
                return response()->json(['message' => 'No se pudo obtener la ubicacion actual'], 400);
            }

            $createdBy = (int) Auth::id();
            $createdIds = [];
            $appliedAt = $this->appliedAt($validated['applied_at'] ?? null);

            DB::transaction(function () use ($request, $validated, $companyId, $locationId, $createdBy, $appliedAt, &$createdIds): void {
                foreach ($validated['details'] as $index => $item) {
                    $product = Product::with('unit')->findOrFail((int) $item['product_id']);
                    $unit = Unit::findOrFail((int) $item['unit_id']);

                    $this->assertCompatibleUnit($product, $unit->id, $companyId);

                    // Existencia de antes, con su fila bloqueada hasta el final:
                    // el conteo se compara contra ella y el renglón la guarda.
                    $previousStock = $this->movementService->lockStock($locationId, (int) $product->id);

                    // Todo se guarda en la unidad del producto: así lo leen
                    // update(), destroy() y undo() al revertir el ajuste.
                    [$direction, $quantityInProductUnit] = isset($item['counted_quantity'])
                        ? $this->countedDifference($product, (int) $unit->id, (float) $item['counted_quantity'], $previousStock)
                        : [(string) $item['direction'], $this->movementService->quantityInProductUnit((int) $product->id, (int) $unit->id, (float) $item['quantity'])];

                    $adjustmentContext = StockContext::for(
                        StockLedgerReason::ADJUSTMENT,
                        null,
                        'Ajuste: ' . (string) ($item['reason_code'] ?? 'other')
                    );

                    if ($direction === 'in') {
                        $this->movementService->increment($locationId, (int) $product->id, (int) $product->unit_id, $quantityInProductUnit, null, $adjustmentContext);
                    } else {
                        $this->movementService->decrement($locationId, (int) $product->id, (int) $product->unit_id, $quantityInProductUnit, null, $adjustmentContext);
                    }

                    $newStock = $this->getCurrentStock((int) $product->id, $locationId);

                    $detail = InventoryAdjustmentDetail::create([
                        'company_id' => $companyId,
                        'location_id' => $locationId,
                        'created_by' => $createdBy,
                        'product_id' => (int) $product->id,
                        'direction' => $direction,
                        'quantity' => $quantityInProductUnit,
                        'unit_id' => (int) $product->unit_id,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'reason_code' => AdjustmentReasonCode::from((string) $item['reason_code']),
                        'notes' => $item['notes'] ?? $validated['notes'] ?? null,
                        'applied_at' => $appliedAt,
                    ]);

                    $itemEvidenceFiles = $this->saveEvidenceFiles($request->file("details.{$index}.evidence", []), (int) $detail->id);
                    $detail->update([
                        'content' => [
                            'evidence_files' => $itemEvidenceFiles,
                            'evidence_count' => count($itemEvidenceFiles),
                            'uploaded_by' => $createdBy,
                            'uploaded_at' => now()->toISOString(),
                        ],
                    ]);

                    $createdIds[] = $detail->id;
                }
            });

            $details = InventoryAdjustmentDetail::with(['location', 'createdByUser', 'product.mainImage', 'unit'])
                ->whereIn('id', $createdIds)
                ->orderByDesc('id')
                ->get();

            // Aviso a quienes administran el inventario de la sucursal (mermas,
            // extravíos, daños…). Quien registró el ajuste no se avisa a sí mismo.
            foreach ($details as $detail) {
                NotificationEngine::dispatch('inventory_adjustment', $companyId, [
                    'adjustment'      => $detail,
                    'product'         => $detail->product,
                    'location'        => $detail->location,
                    'adjustment_qty'  => ($detail->direction === 'out' ? -1 : 1) * (float) $detail->quantity,
                    'new_stock'       => (float) $detail->new_stock,
                    'unit'            => $detail->unit?->abbreviation,
                    'reason'          => $detail->reason_code instanceof AdjustmentReasonCode
                        ? $detail->reason_code->label()
                        : 'Otro',
                    'adjusted_by'     => Auth::user()?->name ?? 'Sistema',
                    'exclude_user_id' => Auth::id(),
                ]);
            }

            return response()->json([
                'message' => 'Ajustes creados y aplicados exitosamente',
                'data' => InventoryAdjustmentDetailResource::collection($details),
            ], 201);
        } catch (Throwable $e) {
            // Stock insuficiente, unidad incompatible o un conteo sin
            // diferencia: 422 con el motivo (antes 500).
            return $this->errorResponse($e, 'Error al crear ajustes');
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_manage')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        // location_id y product_id se aceptan solo si no cambian (la app manda
        // el registro completo): un ajuste no se mueve de sucursal ni de
        // producto. Antes location_id aceptaba cualquier sucursal y el efecto
        // en el stock se aplicaba allá.
        $validated = $request->validate([
            'location_id' => 'sometimes|required|integer',
            'product_id' => 'sometimes|required|integer',
            'direction' => 'sometimes|required|in:in,out',
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'unit_id' => 'sometimes|required|exists:units,id',
            'reason_code' => 'sometimes|required|in:loss,damage,count_diff,expiry,theft,found,other',
            'notes' => 'nullable|string',
        ]);

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
        }

        try {
            $detail = DB::transaction(function () use ($id, $validated, $companyId): InventoryAdjustmentDetail {
                $detail = $this->lockAdjustmentForChange($id, $companyId);

                $movesRecord = (isset($validated['location_id']) && (int) $validated['location_id'] !== (int) $detail->location_id)
                    || (isset($validated['product_id']) && (int) $validated['product_id'] !== (int) $detail->product_id);
                if ($movesRecord) {
                    throw new BusinessRuleException(
                        'Un ajuste no puede cambiar de sucursal ni de producto. Elimínalo y regístralo de nuevo.',
                        'immutable_adjustment'
                    );
                }

                // Revertir impacto anterior
                $oldProduct = Product::findOrFail((int) $detail->product_id);
                $revertContext = StockContext::for(StockLedgerReason::ADJUSTMENT, $detail, 'Reversa del ajuste al editarlo');

                if ($detail->direction === 'in') {
                    $this->movementService->decrement((int) $detail->location_id, (int) $detail->product_id, (int) $oldProduct->unit_id, (float) $detail->quantity, null, $revertContext);
                } else {
                    $this->movementService->increment((int) $detail->location_id, (int) $detail->product_id, (int) $oldProduct->unit_id, (float) $detail->quantity, null, $revertContext);
                }

                $newLocationId = (int) $detail->location_id;
                $newProductId = (int) $detail->product_id;
                $newDirection = (string) ($validated['direction'] ?? $detail->direction);
                $newUnitId = (int) ($validated['unit_id'] ?? $detail->unit_id);

                $product = Product::with('unit')->findOrFail($newProductId);
                $unit = Unit::findOrFail($newUnitId);
                $this->assertCompatibleUnit($product, $unit->id, $companyId);

                $inputQuantity = isset($validated['quantity']) ? (float) $validated['quantity'] : (float) $detail->quantity;
                $quantityInProductUnit = $this->movementService->quantityInProductUnit($newProductId, (int) $unit->id, $inputQuantity);

                $previousStock = $this->getCurrentStock($newProductId, $newLocationId);

                $applyContext = StockContext::for(StockLedgerReason::ADJUSTMENT, $detail, 'Ajuste editado');

                if ($newDirection === 'in') {
                    $this->movementService->increment($newLocationId, $newProductId, (int) $product->unit_id, $quantityInProductUnit, null, $applyContext);
                } else {
                    $this->movementService->decrement($newLocationId, $newProductId, (int) $product->unit_id, $quantityInProductUnit, null, $applyContext);
                }

                $newStock = $this->getCurrentStock($newProductId, $newLocationId);

                $detail->update([
                    'location_id' => $newLocationId,
                    'product_id' => $newProductId,
                    'direction' => $newDirection,
                    'quantity' => $quantityInProductUnit,
                    'unit_id' => (int) $product->unit_id,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason_code' => isset($validated['reason_code'])
                        ? AdjustmentReasonCode::from((string) $validated['reason_code'])
                        : $detail->reason_code,
                    'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $detail->notes,
                    'applied_at' => now(),
                ]);

                return $detail;
            });

            $detail->load(['location', 'createdByUser', 'product.mainImage', 'unit']);

            return response()->json([
                'message' => 'Ajuste actualizado exitosamente',
                'data' => new InventoryAdjustmentDetailResource($detail),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Error al actualizar ajuste');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_manage')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
        }

        try {
            DB::transaction(function () use ($id, $companyId): void {
                $detail = $this->lockAdjustmentForChange($id, $companyId);

                $product = Product::findOrFail((int) $detail->product_id);
                $deleteContext = StockContext::for(StockLedgerReason::ADJUSTMENT, $detail, 'Ajuste eliminado');

                if ($detail->direction === 'in') {
                    $this->movementService->decrement((int) $detail->location_id, (int) $detail->product_id, (int) $product->unit_id, (float) $detail->quantity, null, $deleteContext);
                } else {
                    $this->movementService->increment((int) $detail->location_id, (int) $detail->product_id, (int) $product->unit_id, (float) $detail->quantity, null, $deleteContext);
                }

                // Con la fila bloqueada, un segundo borrado (doble toque) ya no
                // la encuentra (404) y no revierte el stock otra vez.
                if (InventoryAdjustmentDetail::whereKey($detail->id)->delete() !== 1) {
                    throw new \RuntimeException('No se pudo eliminar el ajuste');
                }
            });

            return response()->json(['message' => 'Ajuste eliminado exitosamente']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Error al eliminar ajuste');
        }
    }

    /**
     * Deshace un ajuste registrado hoy por error: regresa la existencia y lo
     * marca como deshecho (content.reverted_at y reverted_by). No se borra:
     * en el listado, el kárdex y la bitácora queda quién lo registró y quién
     * lo deshizo.
     *
     * Solo los manuales, sin deshacer y del mismo día (422 con el motivo si
     * no); los mismos criterios dan can_undo en el recurso. Con la fila
     * bloqueada, un doble toque espera al primero y recibe "ya se deshizo" en
     * vez de regresar la existencia dos veces.
     */
    public function undo(int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_manage')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
        }

        try {
            $detail = DB::transaction(function () use ($id, $companyId): InventoryAdjustmentDetail {
                $detail = $this->lockAdjustmentForChange($id, $companyId);

                // Uno de otro día ya pasó por cortes, reportes y conteos.
                if (!$detail->created_at?->isToday()) {
                    throw new BusinessRuleException(
                        'Solo se puede deshacer el mismo día. Para corregirlo, registra un ajuste nuevo.',
                        'adjustment_not_today'
                    );
                }

                $product = Product::with('unit')->findOrFail((int) $detail->product_id);
                $undoContext = StockContext::for(StockLedgerReason::ADJUSTMENT, $detail, 'Ajuste deshecho');

                if ($detail->direction === 'in') {
                    try {
                        $this->movementService->decrement((int) $detail->location_id, (int) $detail->product_id, (int) $detail->unit_id, (float) $detail->quantity, null, $undoContext);
                    } catch (InsufficientStockException $e) {
                        // Lo que entró ya se vendió o se usó.
                        throw new BusinessRuleException(sprintf(
                            'Ya no hay suficiente existencia de «%s» para deshacer esta entrada (hay %s, se necesitan %s).',
                            $product->name,
                            $this->quantityText($e->available, $product),
                            $this->quantityText($e->requested, $product),
                        ), 'insufficient_stock', $e->details(), $e);
                    }
                } else {
                    $this->movementService->increment((int) $detail->location_id, (int) $detail->product_id, (int) $detail->unit_id, (float) $detail->quantity, null, $undoContext);
                }

                $detail->update([
                    'content' => array_merge($detail->contentArray(), [
                        'reverted_at' => now()->toIso8601String(),
                        'reverted_by' => Auth::id(),
                    ]),
                ]);

                // La edición de un ajuste no va a la bitácora (ActivitySubjects):
                // deshacerlo sí, como antes eliminarlo.
                ActivityLogger::log(
                    'inventario',
                    'reverted',
                    'Deshizo el ajuste de ' . $product->name . ': ' . ($detail->direction === 'out' ? '−' : '+')
                        . $this->quantityText((float) $detail->quantity, $product)
                        . ' (' . $detail->reason_code->label() . ')',
                    $detail,
                    $product->name,
                );

                return $detail;
            });

            $detail->load(['location', 'createdByUser', 'product.mainImage', 'unit']);

            return response()->json([
                'message' => 'Ajuste deshecho',
                'data' => new InventoryAdjustmentDetailResource($detail),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Error al deshacer el ajuste');
        }
    }

    /**
     * Ajuste que se puede editar, eliminar o deshacer, releído con su fila
     * bloqueada (llamar dentro de la transacción que lo cambia): dos cambios
     * simultáneos se esperan y el segundo ve lo que dejó el primero.
     *
     * - De la empresa y, salvo para quien ve todas las sucursales, de la
     *   sucursal actual (404 si no): cambiarlo mueve el stock de esa sucursal
     *   y los permisos se evalúan con el rol de la sucursal actual.
     * - Solo ajustes registrados a mano (422 si no). Los de un conteo, una
     *   orden de producción o una transferencia se corrigen desde su origen:
     *   revertirlos aquí descuadraba el stock (p. ej. la merma de una orden ya
     *   cancelada, que ya se había revertido, se revertía otra vez).
     * - Que no se haya deshecho (422 si ya): su existencia ya regresó, y
     *   editarlo o eliminarlo la movería otra vez.
     */
    private function lockAdjustmentForChange(int $id, int $companyId): InventoryAdjustmentDetail
    {
        $detail = InventoryAdjustmentDetail::where('company_id', $companyId)->lockForUpdate()->find($id);

        $outOfBranch = $detail
            && !CurrentWorker::canAccessAllLocations()
            && (int) $detail->location_id !== (int) CurrentLocation::id();

        if (!$detail || $outOfBranch) {
            abort(404, 'Ajuste no encontrado');
        }

        if ($message = $this->generatedAdjustmentMessage($detail)) {
            throw new BusinessRuleException($message, 'generated_adjustment');
        }

        if ($detail->isReverted()) {
            throw new BusinessRuleException('Este ajuste ya se deshizo.', 'adjustment_reverted');
        }

        return $detail;
    }

    /** Por qué un ajuste generado por otro documento no se cambia aquí; null si es manual. */
    private function generatedAdjustmentMessage(InventoryAdjustmentDetail $detail): ?string
    {
        return match ($detail->originType()) {
            InventoryAdjustmentDetail::ORIGIN_MANUAL => null,
            // Un ajuste generado al recibir una transferencia (merma/daño) no
            // movió existencias por su cuenta: la recepción ya las manejó.
            InventoryAdjustmentDetail::ORIGIN_TRANSFER => 'Este ajuste se generó al recibir una transferencia y no puede modificarse ni eliminarse aquí.',
            InventoryAdjustmentDetail::ORIGIN_INVENTORY_COUNT => 'Este ajuste lo generó un conteo de inventario y no puede modificarse ni eliminarse. Si hay un error, registra un ajuste nuevo.',
            InventoryAdjustmentDetail::ORIGIN_PRODUCTION_ORDER => 'Este ajuste es una merma de una orden de producción y no puede modificarse ni eliminarse aquí. Corrígelo desde la orden.',
            default => 'Este ajuste lo generó otro documento y no puede modificarse ni eliminarse aquí.',
        };
    }

    /**
     * Regla de details.*.direction: pérdida, daño, vencimiento y robo siempre
     * restan; encontrado siempre suma (AdjustmentReasonCode::direction()).
     */
    private function reasonDirectionRule(Request $request): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request): void {
            $reason = AdjustmentReasonCode::tryFrom((string) $request->input(Str::beforeLast($attribute, '.') . '.reason_code'));
            $expected = $reason?->direction();

            if ($expected !== null && $value !== $expected) {
                $fail($expected === 'in'
                    ? "«{$reason->label()}» siempre suma existencia."
                    : "«{$reason->label()}» siempre resta existencia.");
            }
        };
    }

    /**
     * Dirección y cantidad (en la unidad del producto) de un ajuste por
     * conteo: lo contado menos la existencia, con la fila ya bloqueada. Sin
     * diferencia no hay nada que ajustar (422).
     *
     * @return array{0: string, 1: float}
     */
    private function countedDifference(Product $product, int $unitId, float $counted, float $stock): array
    {
        // A la escala de la existencia (3 decimales), como la mueve MovementService.
        $countedInProductUnit = round($this->movementService->quantityInProductUnit((int) $product->id, $unitId, $counted), 3);
        $difference = round($countedInProductUnit - $stock, 3);

        if ($difference == 0.0) {
            throw new BusinessRuleException(
                "«{$product->name}» ya tiene {$this->quantityText($stock, $product)}: no hay diferencia que ajustar.",
                'no_difference'
            );
        }

        return [$difference > 0 ? 'in' : 'out', abs($difference)];
    }

    /**
     * applied_at (cuándo pasó lo que se ajusta) según el día que eligió la
     * persona, en la hora de la app (México):
     * - sin fecha u hoy: ahora, con hora, para que quede en orden con los
     *   movimientos del día (antes quedaba a las 00:00 aunque fuera de hoy);
     * - un día pasado: ese día a las 00:00 (no se sabe a qué hora pasó);
     * - mañana: hoy. Un celular con la zona horaria vieja (aún adelanta la
     *   hora en verano) ya está en el día siguiente entre las 11 y las 12 de
     *   la noche; más adelante lo rechaza la validación.
     */
    private function appliedAt(?string $date): Carbon
    {
        if (!$date) {
            return now();
        }

        // El día tal como viene escrito, sin moverlo de zona horaria.
        $day = Carbon::parse(Carbon::parse($date)->toDateString())->startOfDay();

        return $day->lt(today()) ? $day : now();
    }

    /** "6 pz": una cantidad en la unidad del producto, para los mensajes. */
    private function quantityText(float $quantity, Product $product): string
    {
        $unit = $product->unit?->abbreviation ?: ($product->unit?->name ?? '');

        return trim(ActivityFormatter::quantity($quantity) . ' ' . $unit);
    }

    private function getCompatibleUnits(Product $product, int $companyId): Collection
    {
        $productUnit = Unit::findOrFail((int) $product->unit_id);
        $unitType = (string) $productUnit->unit_type;

        if ($unitType === '') {
            // Collection de Eloquent (el tipo de retorno): collect() daba TypeError.
            return new Collection([$productUnit]);
        }

        return Unit::query()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('unit_type', $unitType)
            ->get();
    }

    private function assertCompatibleUnit(Product $product, int $unitId, int $companyId): void
    {
        $compatible = $this->getCompatibleUnits($product, $companyId);
        $isAllowed = $compatible->contains(fn(Unit $unit) => (int) $unit->id === $unitId);

        if (!$isAllowed) {
            throw new BusinessRuleException("La unidad seleccionada no es compatible con \"{$product->name}\".", 'invalid_unit');
        }
    }

    private function getCurrentStock(int $productId, int $locationId): float
    {
        $row = DB::table('product_location')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        return (float) ($row->current_stock ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function saveEvidenceFiles(array|UploadedFile|null $files, int $detailId): array
    {
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (!is_array($files) || empty($files)) {
            return [];
        }

        $batchPath = rtrim(Files::ADJUSTMENT_EVIDENCE_PATH, '/') . '/detail_' . $detailId;
        $saved = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            $safeExtension = $extension !== '' ? $extension : 'jpg';
            // Nombre con sufijo aleatorio: las evidencias se sirven por una ruta
            // pública, y un nombre predecible (image_1.jpg) permitía adivinarlas.
            $customName = 'image_' . ($index + 1) . '_' . \Illuminate\Support\Str::random(24) . '.' . $safeExtension;
            $result = AppUploadUtil::saveFile($file, $batchPath, $customName);

            if (!$result['success']) {
                throw new \RuntimeException($result['error'] ?? 'No se pudo guardar evidencia');
            }

            $saved[] = [
                'name' => basename((string) $result['path']),
                'path' => $result['path'],
                'uri' => url('/api/auth/files/' . $result['path']),
                'url' => url('/storage/' . $result['path']),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return $saved;
    }
}
