<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use App\Enums\AdjustmentReasonCode;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\EvidencePhotoMessages;
use App\Http\Resources\InventoryAdjustmentDetailResource;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Product;
use App\Enums\StockLedgerReason;
use App\Support\StockContext;
use App\Models\Unit;
use App\Notifications\NotificationEngine;
use App\Services\MovementService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Utils\AppUploadUtil;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * reason_code, product_id, search (producto o notas) y fechas
     * (date_from/date_to o start_date/end_date).
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
            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $perPage = max(1, min((int) $request->input('per_page', 15), 100));
            $records = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

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

    public function store(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('inventory_manage')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $validated = $request->validate([
            'applied_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            // Productos de la empresa (antes bastaba con que existieran).
            'details.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', CurrentCompany::id())],
            'details.*.direction' => 'required|in:in,out',
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.reason_code' => 'required|in:loss,damage,count_diff,expiry,theft,found,other',
            'details.*.notes' => 'nullable|string',
            'details.*.evidence' => 'nullable|array',
            'details.*.evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], array_merge(
            ['details.*.product_id.exists' => 'Uno de los productos no existe en tu empresa.'],
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
            $appliedAt = isset($validated['applied_at']) ? date('Y-m-d H:i:s', strtotime((string) $validated['applied_at'])) : now();

            DB::transaction(function () use ($request, $validated, $companyId, $locationId, $createdBy, $appliedAt, &$createdIds): void {
                foreach ($validated['details'] as $index => $item) {
                    $product = Product::with('unit')->findOrFail((int) $item['product_id']);
                    $unit = Unit::findOrFail((int) $item['unit_id']);

                    $this->assertCompatibleUnit($product, $unit->id, $companyId);

                    // Todo se guarda en la unidad del producto: así lo leen
                    // update() y destroy() al revertir el ajuste.
                    $quantityInProductUnit = $this->movementService->quantityInProductUnit((int) $product->id, (int) $unit->id, (float) $item['quantity']);
                    $direction = (string) $item['direction'];
                    $previousStock = $this->getCurrentStock((int) $product->id, $locationId);

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
            // Stock insuficiente o unidad incompatible: 422 con el motivo (antes 500).
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
     * Ajuste que se puede editar o eliminar, releído con su fila bloqueada
     * (llamar dentro de la transacción que lo cambia): dos cambios
     * simultáneos se esperan y el segundo ve lo que dejó el primero.
     *
     * - De la empresa y, salvo para quien ve todas las sucursales, de la
     *   sucursal actual (404 si no): cambiarlo mueve el stock de esa sucursal
     *   y los permisos se evalúan con el rol de la sucursal actual.
     * - Solo ajustes registrados a mano (422 si no). Los de un conteo, una
     *   orden de producción o una transferencia se corrigen desde su origen:
     *   revertirlos aquí descuadraba el stock (p. ej. la merma de una orden ya
     *   cancelada, que ya se había revertido, se revertía otra vez).
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

        return $detail;
    }

    /** Por qué un ajuste generado por otro documento no se cambia aquí; null si es manual. */
    private function generatedAdjustmentMessage(InventoryAdjustmentDetail $detail): ?string
    {
        $content = $detail->content;
        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }

        // Un ajuste generado al recibir una transferencia (merma/daño) no
        // movió existencias por su cuenta: la recepción ya las manejó.
        if (!empty($content['created_from_transfer']) || ($content['source'] ?? null) === 'inventory_transfer_receive_difference') {
            return 'Este ajuste se generó al recibir una transferencia y no puede modificarse ni eliminarse aquí.';
        }

        return match ((string) $detail->reference_type) {
            '' => null,
            'inventory_count' => 'Este ajuste lo generó un conteo de inventario y no puede modificarse ni eliminarse. Si hay un error, registra un ajuste nuevo.',
            'production_order' => 'Este ajuste es una merma de una orden de producción y no puede modificarse ni eliminarse aquí. Corrígelo desde la orden.',
            default => 'Este ajuste lo generó otro documento y no puede modificarse ni eliminarse aquí.',
        };
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
