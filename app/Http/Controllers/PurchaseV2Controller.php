<?php

namespace App\Http\Controllers;

use App\Models\PurchaseV2;
use App\Models\PurchaseDetailV2;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Utils\AppUploadUtil;
use App\Constants\Files;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use Illuminate\Support\Facades\Log;

class PurchaseV2Controller extends Controller
{
    /**
     * Crear o actualizar una compra en estado draft
     * Se llama cada vez que se agrega/modifica/elimina un producto
     */
    public function upsertDraft(Request $request)
    {
        try {
            $validated = $request->validate([
                'purchase_id' => 'nullable|exists:purchases,id',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'notes' => 'nullable|string',
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
                        'details_count' => $purchase->details->count(),
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
        try {
            $validated = $request->validate([
                'expected_delivery_date' => 'nullable|date|after_or_equal:today',
                'document_number' => 'nullable|string|max:255',
            ]);

            $purchase = PurchaseV2::draft()->findOrFail($id);

            if ($purchase->details()->count() === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede confirmar una compra sin productos'
                ], 422);
            }

            $purchase->update([
                'status' => PurchaseV2::STATUS_ORDERED,
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'document_number' => $validated['document_number'] ?? null,
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
        try {
            $purchase = PurchaseV2::ordered()->findOrFail($id);

            $purchase->update([
                'status' => PurchaseV2::STATUS_IN_TRANSIT,
            ]);

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
        try {
            $validated = $request->validate([
                'details' => 'required|array',
                'details.*.id' => 'required|exists:purchase_details,id',
                'details.*.quantity_received' => 'required|numeric|min:0',
            ]);

            DB::beginTransaction();

            $purchase = PurchaseV2::inTransit()->findOrFail($id);

            // Actualizar cantidades recibidas
            foreach ($validated['details'] as $detailData) {
                $detail = $purchase->details()->findOrFail($detailData['id']);
                $detail->update([
                    'quantity_received' => $detailData['quantity_received'],
                    'received_at' => now(),
                ]);

                // Actualizar stock (convertir paquetes a unidad base)
                $this->updateStock($detail);
            }

            // Crear movimiento de entrada con detalles
            $this->createPurchaseMovement($purchase);

            $purchase->update([
                'status' => PurchaseV2::STATUS_RECEIVED,
                'delivery_date' => now(),
                'received_by' => Auth::id(),
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
     * Actualizar stock del producto al recibir
     */
    private function updateStock(PurchaseDetailV2 $detail)
    {
        $purchase = $detail->purchase;
        $quantityToAdd = $detail->getQuantityInBaseUnit();

        // Actualizar product_location
        DB::table('product_location')
            ->where('product_id', $detail->product_id)
            ->where('location_id', $purchase->location_id)
            ->increment('current_stock', $quantityToAdd);
    }

    /**
     * Crear movimiento de entrada por recepción de compra
     */
    private function createPurchaseMovement(PurchaseV2 $purchase)
    {
        // Crear el movimiento principal
        $movementId = DB::table('movements')->insertGetId([
            'company_id' => $purchase->company_id,
            'movement_type' => 'in',
            'warehouse_destination_id' => $purchase->location_id,
            'supplier_id' => $purchase->supplier_id,
            'user_id' => Auth::id(),
            'date' => now()->toDateString(),
            'total_cost' => $purchase->total,
            'status' => 'closed',
            'comments' => 'Recepción de compra #' . $purchase->purchase_number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Crear detalles del movimiento (productos ya convertidos a unidad base)
        foreach ($purchase->details as $detail) {
            $quantityInBaseUnit = $detail->getQuantityInBaseUnit();

            DB::table('movements_details')->insert([
                'movement_id' => $movementId,
                'product_id' => $detail->product_id,
                'quantity' => $quantityInBaseUnit,
                'unit_cost' => $detail->unit_price,
                'total_cost' => $quantityInBaseUnit * $detail->unit_price,
                'comments' => $detail->package_id
                    ? "Paquete: {$detail->package->package_name} (Original: {$detail->quantity} paquetes, Convertido: {$quantityInBaseUnit} unidades base)"
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $movementId;
    }

    /**
     * Agregar un detalle individual a la compra draft
     */
    public function addDetail(Request $request)
    {
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
        try {
            $purchase = PurchaseV2::whereIn('status', [
                PurchaseV2::STATUS_DRAFT,
                PurchaseV2::STATUS_ORDERED,
                PurchaseV2::STATUS_IN_TRANSIT
            ])->findOrFail($id);

            $purchase->update([
                'status' => PurchaseV2::STATUS_CANCELLED,
            ]);

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
     * Listar compras
     */
    public function index(Request $request)
    {
        $query = PurchaseV2::with(['supplier', 'user', 'details'])
            ->where('company_id', CurrentCompany::get()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
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
}
