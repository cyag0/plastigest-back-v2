<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Movement;
use App\Models\MovementDetail;
use App\Models\ProductKardex;
use App\Constants\Files;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;
use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

class ProductController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = ProductResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Product::class;
    protected ?string $permissionPrefix = 'products';

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company',
            'category',
            'supplier',
            'mainImage',
            'locations',
            'unit',
            'activePackages'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company',
            'category',
            'supplier',
            'locations',
            'unit',
            'productIngredients.ingredient',
            'mainImage',
            'images' => function ($query) {
                $query->orderBy('sort_order');
            },
            //'unit'
        ];
    }

    /**
     * Override del método show para incluir unidades disponibles
     */
    public function show($id)
    {
        $product = Product::with($this->getShowRelations())->findOrFail($id);

        return new ProductResource($product);
    }

    /**
     * Sobrescribir filtros básicos para excluir is_active de la tabla products
     * ya que ahora se maneja en el pivot product_location
     */
    protected function applyBasicFilters($query, array $params)
    {
        // Filtro por búsqueda general
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // NO aplicar filtro is_active aquí, se maneja en handleQuery con el pivot

        // Filtro por company_id si existe
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por fecha de creación
        if (isset($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
        }
    }

    /**
     * Manejo de filtros personalizados
     */                                                                                                                                                                                                                                 
    protected function handleQuery($query, array $params)
    {
        $location = CurrentLocation::get();
        $locationId = $location ? $location->id : (isset($params['location_id']) ? $params['location_id'] : null);
        $isActive = isset($params['is_active']) ? (bool)$params['is_active'] : true;
                                    
        // Filtrar por empresa
        if (isset($params['company_id'])) {
            $company = CurrentCompany::get();
            $companyId = $company ? $company->id : (isset($params['company_id']) ? $params['company_id'] : null);

            $query->where('company_id', $companyId);
        }

        if (isset($params['supplier_id'])) {
            $query->where('supplier_id', $params['supplier_id']);
        }


        // Filtrar por categoría
        if (isset($params['category_id'])) {
            $query->where('category_id', $params['category_id']);
        }

        // Filtrar por estado activo en la ubicación actual
        if ($locationId) {
            $query->whereHas('locations', function ($q) use ($params, $locationId, $isActive) {
                $q->where('location_id', $locationId)
                    ->where('product_location.active', $isActive);
            });
        }

        // Filtrar por stock bajo
        if (isset($params['low_stock']) && $params['low_stock']) {
            if ($locationId) {
                $query->whereHas('locations', function ($q) use ($locationId) {
                    $q->where('location_id', $locationId)
                        ->whereRaw('product_location.current_stock < product_location.minimum_stock')
                        ->where('product_location.minimum_stock', '>', 0);
                });
            }
        }

        // Filtrar por disponibilidad para venta
        if (isset($params['for_sale'])) {
            $query->where('for_sale', true);
        }

        // Búsqueda por nombre o código
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['search'] . '%')
                    ->orWhere('code', 'like', '%' . $params['search'] . '%')
                    ->orWhere('description', 'like', '%' . $params['search'] . '%');
            });
        }

        if (isset($params['product_type']) && is_array($params['product_type'])) {
            $query->whereIn('product_type', $params['product_type']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
        }

        if ($request->has('for_sale')) {
            $request->merge(['for_sale' => $request->boolean('for_sale')]);
        }

        $rules = [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'code' => 'required|string|max:50|unique:products,code',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'product_type' => 'required|in:raw_material,processed,commercial',
            'is_active' => 'boolean',
            'for_sale' => 'boolean',
            'product_images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
            // Campos para manejo de activación en sucursales
            'activate_in_all_locations' => 'nullable|boolean',
            'activate_in_current_location' => 'nullable|boolean',
            'current_location_id' => 'nullable|exists:locations,id',
            // Campos del pivot product_location
            'minimum_stock' => 'nullable|numeric|min:0',
            'maximum_stock' => 'nullable|numeric|min:0',
            // Campos para ingredientes (productos procesados)
            'ingredients' => 'nullable|array',
            'ingredients.*.ingredient_id' => 'required_with:ingredients|exists:products,id',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',
            'ingredients.*.notes' => 'nullable|string|max:500',
        ];

        // Si hay una empresa actual establecida, no requerir company_id en el request
        if (!current_company_id()) {
            $rules['company_id'] = 'required|exists:companies,id';
        } else {
            $rules['company_id'] = 'nullable|exists:companies,id';
        }

        return $request->validate($rules);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
        }

        if ($request->has('for_sale')) {
            $request->merge(['for_sale' => $request->boolean('for_sale')]);
        }

        return $request->validate([
            'name' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'code' => 'nullable',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'company_id' => 'nullable',
            'category_id' => 'nullable',
            'unit_id' => 'nullable|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'product_type' => 'nullable|in:raw_material,processed,commercial',
            'is_active' => 'nullable|boolean',
            'for_sale' => 'nullable|boolean',
            'product_images.*' => 'nullable', // 5MB max
            // Campos para manejo de activación en sucursales
            'activate_in_all_locations' => 'nullable|boolean',
            'activate_in_current_location' => 'nullable|boolean',
            'current_location_id' => 'nullable|exists:locations,id',
            // Campos del pivot product_location
            'minimum_stock' => 'nullable|numeric|min:0',
            'maximum_stock' => 'nullable|numeric|min:0',
            // Campos para ingredientes (productos procesados)
            'ingredients' => 'nullable|array',
            'ingredients.*.ingredient_id' => 'required_with:ingredients|exists:products,id',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',
            'ingredients.*.notes' => 'nullable|string|max:500',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Usar la empresa actual si no se especifica una
        $companyIdArray = $request->input('company_id');
        $companyId = is_array($companyIdArray) ? reset($companyIdArray) : $companyIdArray;

        // Si no hay company_id en el request, usar la empresa actual
        if (!$companyId && current_company_id()) {
            $companyId = current_company_id();
        }

        // Remover campos que no van a la base de datos (se procesan después de crear)
        unset($validatedData['activate_in_all_locations']);
        unset($validatedData['activate_in_current_location']);
        unset($validatedData['current_location_id']);
        unset($validatedData['ingredients']); // Los ingredientes se procesan por separado

        // Remover campos del pivot table (se procesan después)
        unset($validatedData['minimum_stock']);
        unset($validatedData['maximum_stock']);

        // Remover is_active ya que ahora se maneja en el pivot table
        unset($validatedData['is_active']);

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Remover campos que no van a la base de datos (se procesan después de actualizar)
        unset($validatedData['activate_in_all_locations']);
        unset($validatedData['activate_in_current_location']);
        unset($validatedData['current_location_id']);
        unset($validatedData['ingredients']); // Los ingredientes se procesan por separado

        // Remover campos del pivot table (se procesan después)
        unset($validatedData['current_stock']);
        unset($validatedData['minimum_stock']);
        unset($validatedData['maximum_stock']);

        // Remover is_active ya que ahora se maneja en el pivot table
        unset($validatedData['is_active']);

        Log::info('Mensaje informativo', ['data' => $request->all()]);

        return $validatedData;
    }

    /**
     * Acciones después de crear (opcional)
     */
    protected function afterStore(Model $model, Request $request): void
    {

        // Procesar imágenes del producto si se enviaron
        $this->handleProductImages($model, $request);

        // Manejar ingredientes para productos procesados
        $this->handleProductIngredients($model, $request, false);

        // Manejar activación en sucursales (en creación, current_stock parte en 0)
        $this->handleLocationActivation($model, $request, false);
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {

        // Procesar imágenes del producto si se enviaron
        $this->handleProductImages($model, $request, true);

        // Manejar ingredientes para productos procesados
        $this->handleProductIngredients($model, $request, true);

        // Manejar activación en sucursales (en actualización NUNCA se toca current_stock)
        $this->handleLocationActivation($model, $request, true);
    }

    /**
     * Obtener materias primas disponibles para ingredientes
     */
    public function getRawMaterials(Request $request)
    {
        $query = Product::where('product_type', 'raw_material')
            ->where('is_active', true);

        // Filtrar por empresa actual o especificada
        $companyId = $request->input('company_id') ?? current_company_id();
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        // Búsqueda por nombre o código
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('code', 'like', '%' . $search . '%');
            });
        }

        $rawMaterials = $query->select('id', 'name', 'code', 'description')
            ->orderBy('name')
            ->get();

        return response()->json($rawMaterials);
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // TODO: Agregar validaciones cuando se implementen otras tablas
        // Ejemplo: inventarios, órdenes, etc.

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Maneja la subida y procesamiento de imágenes del producto
     */
    private function handleProductImages(Model $product, Request $request, bool $isUpdate = false): void
    {
        $data = $request->all();
        $newImages = $data['product_images'] ?? [];
        // Verificar si se enviaron imágenes
        if (empty($newImages)) {
            return;
        }

        if ($isUpdate) {
            $existingImages = $product->images ?? null;
            $oldFileNames = $existingImages ? $existingImages->pluck('image_path')->toArray() : []; // Solo nombres, no paths completos

            Log::info('Manejo de imágenes en actualización', [
                'oldFileNames' => $oldFileNames,
                'newImagesCount' => count($newImages)
            ]);

            // Reemplazar archivos usando la utilidad (por nombres)
            $result = AppUploadUtil::syncFilesByNames(
                Files::PRODUCT_IMAGES_PATH,
                $newImages,
                $oldFileNames,
                "product_{$product->id}"
            );

            $deleted = $result['deleted'] ?? [];

            // Eliminar registros antiguos de la base de datos
            $existingImages->each(function ($image) use ($deleted) {
                if (in_array($image->image_path, $deleted)) {
                    $image->delete();
                }
            });
        } else {
            // Al crear: simplemente guardar las nuevas imágenes
            $result = AppUploadUtil::syncFilesByNames(
                Files::PRODUCT_IMAGES_PATH,
                $newImages,
                [],
                "product_{$product->id}"
            );
        }

        // Verificar si hubo errores
        if (!empty($result['errors'])) {
            Log::error('Error uploading product images:', $result['errors']);
            return;
        }

        // Crear registros en la base de datos para las nuevas imágenes
        foreach ($result['saved'] as $index => $savedFile) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $savedFile['name'], // Solo el nombre del archivo
                'original_name' => $savedFile['metadata']['original_name'],
                'mime_type' => $savedFile['metadata']['mime_type'],
                'file_size' => $savedFile['metadata']['size'],
                'image_type' => $index === 0 ? 'main' : 'gallery', // Primera imagen como principal
                'is_public' => true,
                'show_in_catalog' => true,
                'sort_order' => $index
            ]);
        }
    }

    /**
     * Eliminar una imagen específica del producto
     */
    public function deleteProductImage(Request $request, $productId, $imageId)
    {
        try {
            $product = Product::findOrFail($productId);
            $image = ProductImage::where('product_id', $productId)
                ->where('id', $imageId)
                ->firstOrFail();

            // Eliminar archivo físico (usando solo el nombre del archivo)
            $deleted = AppUploadUtil::deleteFileByName(Files::PRODUCT_IMAGES_PATH, $image->image_path);

            if ($deleted) {
                // Eliminar registro de base de datos
                $image->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'Imagen eliminada correctamente'
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Error al eliminar el archivo físico'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Imagen no encontrada'
            ], 404);
        }
    }

    /**
     * Obtener todas las imágenes de un producto
     */
    public function getProductImages($productId)
    {
        try {
            $product = Product::with(['images' => function ($query) {
                $query->orderBy('sort_order');
            }])->findOrFail($productId);

            return response()->json([
                'success' => true,
                'data' => $product->images
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Producto no encontrado'
            ], 404);
        }
    }

    /**
     * Actualizar el orden de las imágenes
     */
    public function updateImageOrder(Request $request, $productId)
    {
        try {
            $request->validate([
                'images' => 'required|array',
                'images.*.id' => 'required|exists:product_images,id',
                'images.*.sort_order' => 'required|integer|min:0'
            ]);

            foreach ($request->input('images') as $imageData) {
                ProductImage::where('id', $imageData['id'])
                    ->where('product_id', $productId)
                    ->update(['sort_order' => $imageData['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden de imágenes actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al actualizar el orden'
            ], 500);
        }
    }

    /**
     * Maneja la activación del producto en las sucursales según las opciones seleccionadas.
     *
     * IMPORTANTE: en actualización ($isUpdate = true) NUNCA se sobrescribe current_stock
     * ni campos económicos del pivot, ya que esto destruiría el stock real del producto
     * y registros de kardex al editarlo. Solo se actualizan: active, minimum_stock y
     * maximum_stock (y solo si vienen en el request).
     */
    private function handleLocationActivation(Model $product, Request $request, bool $isUpdate = false): void
    {
        /** @var Product $product */
        $product = $product;
        $data = $request->all();

        $currentLocationId = $data['current_location_id'] ?? null;
        $isActive = $request->boolean('is_active');

        // Si no se especifica currentLocationId pero hay una ubicación actual, usarla
        if (!$currentLocationId && current_location_id()) {
            $currentLocationId = current_location_id();
        }

        // Obtener la compañía del producto
        $companyId = $product->company_id ?? current_company_id();

        if (!$companyId) {
            return;
        }

        // Obtener todas las locaciones de la compañía
        $allLocations = \App\Models\Admin\Location::where('company_id', $companyId)->get();

        foreach ($allLocations as $location) {
            $pivotExists = $product->locations()->where('location_id', $location->id)->exists();

            if ($location->id == $currentLocationId) {
                if ($isUpdate && $pivotExists) {
                    // En actualización, el stock es intocable: solo refrescar
                    // active/minimum_stock/maximum_stock si fueron enviados.
                    $updateData = [];
                    $updateData['active'] = $isActive;

                    if ($request->has('minimum_stock')) {
                        $updateData['minimum_stock'] = $request->input('minimum_stock', 0);
                    }
                    if ($request->has('maximum_stock')) {
                        $updateData['maximum_stock'] = $request->input('maximum_stock', 0);
                    }
                    $updateData['updated_at'] = now();

                    DB::table('product_location')
                        ->where('product_id', $product->id)
                        ->where('location_id', $location->id)
                        ->update($updateData);
                } else {
                    // Creación o registro nuevo: insertar/actualizar con current_stock = 0
                    $product->locations()->syncWithoutDetaching([
                        $location->id => [
                            'active' => $isActive,
                            'minimum_stock' => $request->input('minimum_stock', 0),
                            'maximum_stock' => $request->input('maximum_stock', 0),
                            'current_stock' => 0,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    ]);
                }
            } else {
                // Para las demás ubicaciones, solo crear la entrada inactiva si no existe.
                // Nunca modificar registros existentes para preservar su estado real.
                if (!$pivotExists) {
                    $product->locations()->syncWithoutDetaching([
                        $location->id => [
                            'active' => false,
                            'minimum_stock' => 0,
                            'maximum_stock' => 0,
                            'current_stock' => 0,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    ]);
                }
            }
        }
    }

    /**
     * Activa producto en todas las ubicaciones con datos de stock
     */
    private function activateProductInAllLocationsWithStock(Model $product, $companyId, array $pivotData): void
    {
        $locations = \App\Models\Admin\Location::where('company_id', $companyId)->get();

        foreach ($locations as $location) {
            $product->locations()->syncWithoutDetaching([
                $location->id => $pivotData
            ]);
        }
    }

    /**
     * Activa producto en una ubicación específica con datos de stock
     */
    private function activateProductInLocationWithStock(Model $product, $locationId, array $pivotData): void
    {
        $product->locations()->syncWithoutDetaching([
            $locationId => $pivotData
        ]);
    }

    /**
     * Agregar stock a un producto existente
     */
    public function addStock(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'location_id' => 'required|exists:locations,id',
                'quantity' => 'required|numeric|min:0.001',
                'unit_cost' => 'required|numeric|min:0',
                'reason' => 'required|string|max:255',
                'notes' => 'nullable|string|max:500',
                'batch_number' => 'nullable|string|max:100',
                'expiry_date' => 'nullable|date|after_or_equal:today',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            $product = Product::findOrFail($id);
            $locationId = $request->location_id;
            $quantity = $request->quantity;
            $unitCost = $request->unit_cost;

            DB::beginTransaction();

            // Verificar/crear relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $id)
                ->where('location_id', $locationId)
                ->first();

            $previousStock = $productLocation ? $productLocation->current_stock : 0;

            if ($productLocation) {
                // Incrementar stock existente
                DB::table('product_location')
                    ->where('product_id', $id)
                    ->where('location_id', $locationId)
                    ->increment('current_stock', $quantity);
            } else {
                // Crear nueva relación con stock
                DB::table('product_location')->insert([
                    'product_id' => $id,
                    'location_id' => $locationId,
                    'current_stock' => $quantity,
                    'minimum_stock' => 0,
                    'maximum_stock' => 0,
                    'average_cost' => $unitCost,
                    'last_movement_at' => now(),
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Crear movimiento de entrada para trazabilidad
            $movement = Movement::create([
                'company_id' => $product->company_id,
                'location_destination_id' => $locationId,
                'movement_type' => 'entry',
                'movement_reason' => 'stock_adjustment',
                'document_number' => 'ADJ-' . now()->format('YmdHis'),
                'user_id' => Auth::id(),
                'movement_date' => now(),
                'status' => 'closed',
                'notes' => $request->reason
            ]);

            // Crear detalle del movimiento
            $movementDetail = MovementDetail::create([
                'movement_id' => $movement->id,
                'product_id' => $id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $quantity * $unitCost,
                'batch_number' => $request->batch_number,
                'notes' => $request->notes,
            ]);

            // Registrar en kardex
            ProductKardex::create([
                'company_id' => $product->company_id,
                'location_id' => $locationId,
                'product_id' => $id,
                'movement_id' => $movement->id,
                'movement_detail_id' => $movementDetail->id,
                'operation_type' => 'entry',
                'operation_reason' => 'stock_adjustment',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $quantity * $unitCost,
                'previous_stock' => $previousStock,
                'new_stock' => $previousStock + $quantity,
                'running_average_cost' => $unitCost,
                'document_number' => $movement->document_number,
                'batch_number' => $request->batch_number,
                'expiry_date' => $request->expiry_date,
                'user_id' => Auth::id(),
                'operation_date' => now()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Stock agregado exitosamente',
                'data' => [
                    'product_id' => $id,
                    'location_id' => $locationId,
                    'quantity_added' => $quantity,
                    'new_stock' => $previousStock + $quantity,
                    'movement_id' => $movement->id
                ]
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error agregando stock: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error agregando stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Maneja la sincronización de ingredientes para productos procesados.
     *
     * En actualización ($isUpdate = true), solo reemplaza los ingredientes si el campo
     * 'ingredients' viene explícitamente en el request. Si no viene, conserva los
     * ingredientes existentes para evitar pérdidas de datos al editar el producto.
     */
    private function handleProductIngredients(Model $product, Request $request, bool $isUpdate = false): void
    {
        // Solo procesar ingredientes para productos procesados
        if ($product->product_type !== 'processed') {
            return;
        }

        // En actualización, si el campo no se envió, no tocar la relación para no
        // perder ingredientes existentes.
        if ($isUpdate && !$request->has('ingredients')) {
            return;
        }

        $ingredients = $request->input('ingredients', []);

        // Eliminar ingredientes existentes
        $product->productIngredients()->delete();

        // Agregar nuevos ingredientes
        foreach ($ingredients as $ingredientData) {
            if (!empty($ingredientData['ingredient_id']) && !empty($ingredientData['quantity'])) {
                // Validar que el ingrediente sea de tipo raw_material
                $ingredient = Product::find($ingredientData['ingredient_id']);
                if ($ingredient && $ingredient->product_type === 'raw_material') {
                    $product->productIngredients()->create([
                        'ingredient_id' => $ingredientData['ingredient_id'],
                        'quantity' => $ingredientData['quantity'],
                        'notes' => $ingredientData['notes'] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Generar URL firmada para el PDF de etiquetas
     */
    public function generatePdfUrl(Request $request, $productId)
    {
        try {
            // Validar quantity
            $quantity = $request->input('quantity') ?? $request->query('quantity', 1);

            validator(['quantity' => $quantity], [
                'quantity' => 'required|integer|min:1|max:100'
            ])->validate();

            // Verificar que el producto existe y pertenece a la compañía actual (evita IDOR cross-tenant)
            $product = Product::where('company_id', CurrentCompany::id())
                ->findOrFail($productId);

            // Generar URL firmada que expira en 1 hora, codificando el company_id para validación en el render
            $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'products.labels.pdf',
                now()->addHour(),
                ['product' => $productId, 'quantity' => $quantity, 'company_id' => CurrentCompany::id()]
            );

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ]);
        } catch (\Exception $e) {
            // Re-emitir excepciones HTTP y ModelNotFound para que Laravel las maneje con su status code original
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            return response()->json([
                'message' => 'Error al generar URL del PDF'
            ], 500);
        }
    }

    /**
     * Genera etiquetas de código de barras para impresión térmica
     */
    public function printLabels(Request $request, $productId)
    {
        try {
            // Aceptar quantity desde query params o body
            $quantity = $request->input('quantity') ?? $request->query('quantity');

            $validated = validator(['quantity' => $quantity], [
                'quantity' => 'required|integer|min:1|max:100'
            ])->validate();

            // Defensa en profundidad: validar que el company_id de la URL firmada coincide con el del producto
            $companyId = $request->query('company_id');
            abort_if(
                $companyId === null || !Product::where('id', $productId)->where('company_id', $companyId)->exists(),
                404
            );

            $product = Product::findOrFail($productId);
            $quantity = $validated['quantity'];

            // Generar código de barras como imagen base64
            $generator = new BarcodeGeneratorPNG();
            $barcodeData = $generator->getBarcode($product->code, $generator::TYPE_CODE_128);
            $barcodeBase64 = base64_encode($barcodeData);

            // HTML optimizado para impresora térmica (58mm típicamente)
            $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Etiquetas - ' . htmlspecialchars($product->name) . '</title>
    <style>
        @page {
            size: 58mm auto;
            margin: 2mm;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            width: 58mm;
            font-size: 10pt;
        }
        .label {
            page-break-after: always;
            padding: 3mm;
            text-align: center;
            border: 1px dashed #ccc;
            margin-bottom: 2mm;
        }
        .label:last-child {
            page-break-after: auto;
            margin-bottom: 0;
        }
        .product-name {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 2mm;
            word-wrap: break-word;
        }
        .product-code {
            font-size: 9pt;
            margin-bottom: 2mm;
            color: #333;
        }
        .barcode-container {
            margin: 2mm 0;
        }
        .barcode-image {
            width: 100%;
            max-width: 50mm;
            height: auto;
        }
        .price {
            font-size: 12pt;
            font-weight: bold;
            margin-top: 2mm;
        }
    </style>
</head>
<body>';

            // Generar etiquetas según la cantidad solicitada
            for ($i = 0; $i < $quantity; $i++) {
                $html .= '
    <div class="label">
        <div class="product-name">' . htmlspecialchars($product->name) . '</div>
        <div class="product-code">Código: ' . htmlspecialchars($product->code) . '</div>
        <div class="barcode-container">
            <img src="data:image/png;base64,' . $barcodeBase64 . '" alt="Barcode" class="barcode-image">
        </div>';

                if ($product->sale_price) {
                    $html .= '
        <div class="price">$' . number_format($product->sale_price, 2) . '</div>';
                }

                $html .= '
    </div>';
            }

            $html .= '
</body>
</html>';

            // Generar PDF
            $pdf = Pdf::loadHTML($html);

            // Configurar el tamaño de página para impresora térmica de 58mm
            $pdf->setPaper([0, 0, 165, 500], 'portrait'); // 58mm = 165 puntos aproximadamente

            return $pdf->stream('etiquetas-' . $product->code . '.pdf');
        } catch (\Exception $e) {
            // Re-emitir excepciones HTTP y ModelNotFound para que Laravel las maneje con su status code original
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }

            return response()->json([
                'message' => 'Error al generar el PDF'
            ], 500);
        }
    }
}
