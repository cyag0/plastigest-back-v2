<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Constants\Files;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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
            //'unit'
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
            'productIngredients.ingredient',
            'images' => function ($query) {
                $query->orderBy('sort_order');
            },
            //'unit'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtrar por empresa
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtrar por categoría
        if (isset($params['category_id'])) {
            $query->where('category_id', $params['category_id']);
        }

        // Filtrar por estado activo en la ubicación actual
        if (isset($params['is_active'])) {
            $locationId = $params['location_id'] ?? current_location_id();
            if ($locationId) {
                $query->whereHas('locations', function ($q) use ($params, $locationId) {
                    $q->where('location_id', $locationId)
                        ->where('product_location.active', $params['is_active']);
                });
            }
        }

        // Filtrar por disponibilidad para venta
        if (isset($params['for_sale'])) {
            $query->where('for_sale', $params['for_sale']);
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
        $this->handleProductIngredients($model, $request);

        // Manejar activación en sucursales
        $this->handleLocationActivation($model, $request);
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Procesar imágenes del producto si se enviaron
        $this->handleProductImages($model, $request, true);

        // Manejar ingredientes para productos procesados
        $this->handleProductIngredients($model, $request);

        // Manejar activación en sucursales
        $this->handleLocationActivation($model, $request);
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
            $existingImages = $product->images;
            $oldFileNames = $existingImages->pluck('image_path')->toArray(); // Solo nombres, no paths completos

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
     * Maneja la activación del producto en las sucursales según las opciones seleccionadas
     */
    private function handleLocationActivation(Model $product, Request $request): void
    {
        /** @var Product $product */
        $product = $product;
        $data = $request->all();

        $currentLocationId = $data['current_location_id'] ?? null;
        $isActive = $request->boolean('is_active');

        // Obtener datos del stock para el pivot
        $pivotData = [
            'active' => $isActive,
            'minimum_stock' => $request->input('minimum_stock', 0),
        ];

        // Si se proporciona maximum_stock, incluirlo
        if ($request->has('maximum_stock')) {
            $pivotData['maximum_stock'] = $request->input('maximum_stock', 0);
        }

        // Si no se especifica currentLocationId pero hay una ubicación actual, usarla
        if (!$currentLocationId && current_location_id()) {
            $currentLocationId = current_location_id();
        }


        if ($currentLocationId) {
            $relation = $product->locations();
            $exists = $relation->where('location_id', $currentLocationId)->exists();

            if ($exists) {
                // Actualiza pivot si ya existe la relación
                $relation->updateExistingPivot($currentLocationId, $pivotData);
            } else {
                // Crea la relación si no existe
                $relation->attach($currentLocationId, $pivotData);
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
     * Maneja la sincronización de ingredientes para productos procesados
     */
    private function handleProductIngredients(Model $product, Request $request): void
    {
        $ingredients = $request->input('ingredients', []);

        // Solo procesar ingredientes para productos procesados
        if ($product->product_type !== 'processed') {
            return;
        }

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
}
