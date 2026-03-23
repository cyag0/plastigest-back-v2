<?php

namespace App\Http\Controllers;

use App\Constants\Files;
use App\Enums\AdjustmentReasonCode;
use App\Http\Resources\InventoryAdjustmentDetailResource;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Product;
use App\Models\Unit;
use App\Services\MovementService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Utils\AppUploadUtil;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryAdjustmentController extends Controller
{
    public function __construct(private readonly MovementService $movementService) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $companyId = CurrentCompany::get()?->id;
            if (!$companyId) {
                return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
            }

            $query = InventoryAdjustmentDetail::with([
                'location',
                'createdByUser',
                'product.mainImage',
                'unit',
            ])->where('company_id', $companyId);

            if ($request->filled('location_id')) {
                $query->where('location_id', (int) $request->input('location_id'));
            }

            if ($request->filled('reason_code')) {
                $reasons = is_array($request->reason_code)
                    ? $request->reason_code
                    : explode(',', (string) $request->reason_code);
                $query->whereIn('reason_code', array_filter(array_map('trim', $reasons)));
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->input('product_id'));
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [
                    $request->start_date . ' 00:00:00',
                    $request->end_date . ' 23:59:59',
                ]);
            }

            $perPage = (int) $request->input('per_page', 15);
            $records = $query->orderByDesc('created_at')->paginate($perPage);

            return response()->json([
                'message' => 'Ajustes obtenidos exitosamente',
                'data' => InventoryAdjustmentDetailResource::collection($records),
                'pagination' => [
                    'total' => $records->total(),
                    'per_page' => $records->perPage(),
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Error listing inventory adjustments', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al listar ajustes', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $detail = InventoryAdjustmentDetail::with([
                'location',
                'createdByUser',
                'product.mainImage',
                'unit',
            ])->findOrFail($id);

            return response()->json([
                'message' => 'Detalle de ajuste obtenido exitosamente',
                'data' => new InventoryAdjustmentDetailResource($detail),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Ajuste no encontrado', 'error' => $e->getMessage()], 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'applied_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.direction' => 'required|in:in,out',
            'details.*.quantity' => 'required|numeric|min:0.001',
            'details.*.unit_id' => 'required|exists:units,id',
            'details.*.reason_code' => 'required|in:loss,damage,count_diff,expiry,theft,found,other',
            'details.*.notes' => 'nullable|string',
            'details.*.evidence' => 'nullable|array',
            'details.*.evidence.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

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

                    $quantityInBase = $this->toBaseQuantity((float) $item['quantity'], $unit);
                    $direction = (string) $item['direction'];
                    $previousStock = $this->getCurrentStock((int) $product->id, $locationId);

                    if ($direction === 'in') {
                        $this->movementService->increment($locationId, (int) $product->id, (int) $product->unit_id, $quantityInBase);
                    } else {
                        $this->movementService->decrement($locationId, (int) $product->id, (int) $product->unit_id, $quantityInBase);
                    }

                    $newStock = $this->getCurrentStock((int) $product->id, $locationId);

                    $detail = InventoryAdjustmentDetail::create([
                        'company_id' => $companyId,
                        'location_id' => $locationId,
                        'created_by' => $createdBy,
                        'product_id' => (int) $product->id,
                        'direction' => $direction,
                        'quantity' => $quantityInBase,
                        'unit_id' => (int) $unit->id,
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

            return response()->json([
                'message' => 'Ajustes creados y aplicados exitosamente',
                'data' => InventoryAdjustmentDetailResource::collection($details),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Error creating inventory adjustment details', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al crear ajustes', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'sometimes|required|exists:locations,id',
            'product_id' => 'sometimes|required|exists:products,id',
            'direction' => 'sometimes|required|in:in,out',
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'unit_id' => 'sometimes|required|exists:units,id',
            'reason_code' => 'sometimes|required|in:loss,damage,count_diff,expiry,theft,found,other',
            'notes' => 'nullable|string',
        ]);

        try {
            $companyId = CurrentCompany::get()?->id;
            if (!$companyId) {
                return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
            }

            $detail = InventoryAdjustmentDetail::where('company_id', $companyId)->findOrFail($id);

            DB::transaction(function () use ($detail, $validated, $companyId): void {
                // Revertir impacto anterior
                $oldProduct = Product::findOrFail((int) $detail->product_id);
                if ($detail->direction === 'in') {
                    $this->movementService->decrement((int) $detail->location_id, (int) $detail->product_id, (int) $oldProduct->unit_id, (float) $detail->quantity);
                } else {
                    $this->movementService->increment((int) $detail->location_id, (int) $detail->product_id, (int) $oldProduct->unit_id, (float) $detail->quantity);
                }

                $newLocationId = (int) ($validated['location_id'] ?? $detail->location_id);
                $newProductId = (int) ($validated['product_id'] ?? $detail->product_id);
                $newDirection = (string) ($validated['direction'] ?? $detail->direction);
                $newUnitId = (int) ($validated['unit_id'] ?? $detail->unit_id);

                $product = Product::with('unit')->findOrFail($newProductId);
                $unit = Unit::findOrFail($newUnitId);
                $this->assertCompatibleUnit($product, $unit->id, $companyId);

                $inputQuantity = isset($validated['quantity']) ? (float) $validated['quantity'] : (float) $detail->quantity;
                $quantityInBase = $this->toBaseQuantity($inputQuantity, $unit);

                $previousStock = $this->getCurrentStock($newProductId, $newLocationId);

                if ($newDirection === 'in') {
                    $this->movementService->increment($newLocationId, $newProductId, (int) $product->unit_id, $quantityInBase);
                } else {
                    $this->movementService->decrement($newLocationId, $newProductId, (int) $product->unit_id, $quantityInBase);
                }

                $newStock = $this->getCurrentStock($newProductId, $newLocationId);

                $detail->update([
                    'location_id' => $newLocationId,
                    'product_id' => $newProductId,
                    'direction' => $newDirection,
                    'quantity' => $quantityInBase,
                    'unit_id' => $newUnitId,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason_code' => isset($validated['reason_code'])
                        ? AdjustmentReasonCode::from((string) $validated['reason_code'])
                        : $detail->reason_code,
                    'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $detail->notes,
                    'applied_at' => now(),
                ]);
            });

            $detail->load(['location', 'createdByUser', 'product.mainImage', 'unit']);

            return response()->json([
                'message' => 'Ajuste actualizado exitosamente',
                'data' => new InventoryAdjustmentDetailResource($detail),
            ]);
        } catch (Throwable $e) {
            Log::error('Error updating inventory adjustment detail', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al actualizar ajuste', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $companyId = CurrentCompany::get()?->id;
            if (!$companyId) {
                return response()->json(['message' => 'No se pudo obtener la compania actual'], 400);
            }

            $detail = InventoryAdjustmentDetail::where('company_id', $companyId)->findOrFail($id);

            DB::transaction(function () use ($detail): void {
                $product = Product::findOrFail((int) $detail->product_id);
                if ($detail->direction === 'in') {
                    $this->movementService->decrement((int) $detail->location_id, (int) $detail->product_id, (int) $product->unit_id, (float) $detail->quantity);
                } else {
                    $this->movementService->increment((int) $detail->location_id, (int) $detail->product_id, (int) $product->unit_id, (float) $detail->quantity);
                }
                $detail->delete();
            });

            return response()->json(['message' => 'Ajuste eliminado exitosamente']);
        } catch (Throwable $e) {
            Log::error('Error deleting inventory adjustment detail', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al eliminar ajuste', 'error' => $e->getMessage()], 500);
        }
    }

    private function getCompatibleUnits(Product $product, int $companyId): Collection
    {
        $productUnit = Unit::findOrFail((int) $product->unit_id);
        $unitType = (string) $productUnit->unit_type;

        if ($unitType === '') {
            return collect([$productUnit]);
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
            throw new \RuntimeException('La unidad seleccionada no es compatible con el producto.');
        }
    }

    private function toBaseQuantity(float $inputQuantity, Unit $unit): float
    {
        $factor = (float) ($unit->factor_to_base ?? 1);
        return $inputQuantity * $factor;
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
            $customName = 'image_' . ($index + 1) . '.' . $safeExtension;
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
