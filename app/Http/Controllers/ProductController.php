<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\ProductResource;
use App\Models\InventoryType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StockLedgerEntry;
use App\Enums\StockLedgerReason;
use App\Constants\Files;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    use \App\Http\Controllers\Concerns\PrunesEmptyRows;

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
            'inventoryType',
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
            'inventoryType',
            'productIngredients.ingredient',
            'packagingMaterials.material',
            'packagingMaterials.unit',
            'packagingSourceProduct',
            'packagingSourceUnit',
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


        // Filtrar por categoría (ignoramos valor vacío = "Todas")
        if (!empty($params['category_id'])) {
            $query->where('category_id', $params['category_id']);
        }

        // Filtrar por estado activo en la ubicación actual
        if ($locationId) {
            $query->whereHas('locations', function ($q) use ($params, $locationId, $isActive) {
                $q->where('location_id', $locationId)
                    ->where('product_location.active', $isActive);
            });
        }

        // Filtrar por existencia de stock en la ubicación actual.
        // "" = "Todos", "1" = con stock (>0), "0" = sin stock (<=0).
        if ($locationId && isset($params['in_stock']) && $params['in_stock'] !== '') {
            $operator = $params['in_stock'] ? '>' : '<=';
            $query->whereHas('locations', function ($q) use ($locationId, $operator) {
                $q->where('location_id', $locationId)
                    ->where('product_location.current_stock', $operator, 0);
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

        // Filtrar por disponibilidad para venta. "" = "Todos" (sin filtro),
        // "1" = sólo para venta, "0" = sólo NO para venta.
        if (isset($params['for_sale']) && $params['for_sale'] !== '') {
            $query->where('for_sale', (bool) $params['for_sale']);
        }

        // Búsqueda por nombre o código
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['search'] . '%')
                    ->orWhere('code', 'like', '%' . $params['search'] . '%')
                    ->orWhere('description', 'like', '%' . $params['search'] . '%');
            });
        }

        if (isset($params['product_type']) && is_array($params['product_type']) && count($params['product_type']) > 0) {
            $query->whereIn('product_type', $params['product_type']);
        }

        // Filtrar por tipo de inventario (uno o varios ids). Es el filtro que usa
        // el menú de tipos de inventario de la app.
        if (!empty($params['inventory_type_id'])) {
            $query->whereIn('inventory_type_id', (array) $params['inventory_type_id']);
        }

        // Filtrar por slug del tipo (materia-prima, material-de-empaque, ...).
        // Es lo que manda la app: el slug es lo que viaja en la URL.
        if (!empty($params['inventory_type_slug'])) {
            $slugs = (array) $params['inventory_type_slug'];

            $query->whereHas('inventoryType', function ($q) use ($slugs) {
                $q->whereIn('slug', $slugs);
            });
        }

        // Filtrar por código del tipo (raw_material, packaging_material, ...).
        // Lo usa el selector de material de empaque del formulario de productos.
        if (!empty($params['inventory_type_code'])) {
            $codes = (array) $params['inventory_type_code'];

            $query->whereHas('inventoryType', function ($q) use ($codes) {
                $q->whereIn('code', $codes);
            });
        }

        // Filtrar por comportamiento del tipo: ?is_consumable=1 para los
        // ingredientes de una fórmula, ?is_producible=1 para sus resultados.
        // Los selectores de la app ya mandaban estas banderas.
        foreach (InventoryType::BEHAVIOR_FLAGS as $flag) {
            if (!isset($params[$flag]) || $params[$flag] === '') {
                continue;
            }

            $value = filter_var($params[$flag], FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('inventoryType', fn($q) => $q->where($flag, $value));
        }

        // Productos que se empacan a partir de un granel dado. Es lo que
        // necesita el paso de empaque para saber qué puede salir de cada output.
        if (!empty($params['packaging_source_product_id'])) {
            $query->where('packaging_source_product_id', (int) $params['packaging_source_product_id'])
                ->where('requires_packaging', true);
        }

        if (isset($params['requires_packaging']) && $params['requires_packaging'] !== '') {
            $query->where('requires_packaging', filter_var($params['requires_packaging'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    /**
     * Crear el producto y todo lo que cuelga de él de forma atómica.
     *
     * CrudController::store crea el producto y después corre afterStore
     * (imágenes, ingredientes, material de empaque, activación en sucursales).
     * Si algo de eso fallaba, el producto ya estaba guardado pero la app
     * recibía un error; al reintentar, el código ya existía y el alta parecía
     * imposible aunque los datos estuvieran completos. store() atrapa las
     * excepciones y responde, así que se decide por el código de respuesta.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $response = parent::store($request);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $failed = $response instanceof JsonResponse && $response->getStatusCode() >= 400;
        $failed ? DB::rollBack() : DB::commit();

        return $response;
    }

    /**
     * Empresa del producto que se va a crear: la del cuerpo (company_id o
     * company_id[]) o la empresa actual. Misma regla que processStoreData.
     */
    private function storeCompanyId(Request $request): ?int
    {
        $companyId = $request->input('company_id');
        $companyId = is_array($companyId) ? reset($companyId) : $companyId;

        return $companyId ? (int) $companyId : current_company_id();
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

        if ($request->has('requires_packaging')) {
            $request->merge(['requires_packaging' => $request->boolean('requires_packaging')]);
        }

        $this->fillLegacyProductType($request);
        $this->pruneEmptyRows($request, 'ingredients', 'ingredient_id');
        $this->pruneEmptyRows($request, 'packaging_materials', 'material_product_id');

        $rules = [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            // Único dentro de la empresa, no en toda la base.
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('products', 'code')->where(
                    fn($q) => $q->where('company_id', $this->storeCompanyId($request))
                ),
            ],
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'inventory_type_id' => 'nullable|exists:inventory_types,id',
            'product_type' => 'required|in:raw_material,processed,commercial',
            'is_active' => 'boolean',
            'for_sale' => 'boolean',
            // Receta de empaque: de qué granel sale y qué material consume.
            'requires_packaging' => 'nullable|boolean',
            'packaging_source_product_id' => 'nullable|exists:products,id',
            'packaging_source_quantity' => 'nullable|numeric|min:0',
            'packaging_source_unit_id' => 'nullable|exists:units,id',
            'packaging_materials' => 'nullable|array',
            'packaging_materials.*.material_product_id' => 'required_with:packaging_materials|exists:products,id',
            'packaging_materials.*.unit_id' => 'required_with:packaging_materials|exists:units,id',
            'packaging_materials.*.quantity' => 'required_with:packaging_materials|numeric|min:0.0001',
            'packaging_materials.*.notes' => 'nullable|string|max:500',
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

        if ($request->has('requires_packaging')) {
            $request->merge(['requires_packaging' => $request->boolean('requires_packaging')]);
        }

        $this->fillLegacyProductType($request);
        $this->pruneEmptyRows($request, 'ingredients', 'ingredient_id');
        $this->pruneEmptyRows($request, 'packaging_materials', 'material_product_id');

        return $request->validate([
            'name' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('products', 'code')
                    ->where(fn($q) => $q->where('company_id', $model->company_id))
                    ->ignore($model->id),
            ],
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'company_id' => 'nullable',
            'category_id' => 'nullable',
            'unit_id' => 'nullable|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'inventory_type_id' => 'nullable|exists:inventory_types,id',
            'product_type' => 'nullable|in:raw_material,processed,commercial',
            'is_active' => 'nullable|boolean',
            'for_sale' => 'nullable|boolean',
            // Receta de empaque: ver validateStoreData.
            'requires_packaging' => 'nullable|boolean',
            'packaging_source_product_id' => 'nullable|exists:products,id',
            'packaging_source_quantity' => 'nullable|numeric|min:0',
            'packaging_source_unit_id' => 'nullable|exists:units,id',
            'packaging_materials' => 'nullable|array',
            'packaging_materials.*.material_product_id' => 'required_with:packaging_materials|exists:products,id',
            'packaging_materials.*.unit_id' => 'required_with:packaging_materials|exists:units,id',
            'packaging_materials.*.quantity' => 'required_with:packaging_materials|numeric|min:0.0001',
            'packaging_materials.*.notes' => 'nullable|string|max:500',
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
     * Rellena `product_type` a partir del tipo de inventario.
     *
     * El formulario de la app ya sólo manda `inventory_type_id`, pero
     * `products.product_type` sigue siendo obligatorio y lo leen el POS,
     * compras y los reportes. Se mantienen sincronizados hasta que el enum
     * viejo desaparezca del todo.
     */
    private function fillLegacyProductType(Request $request): void
    {
        if (!$request->filled('inventory_type_id')) {
            return;
        }

        $legacyType = InventoryType::legacyTypeForId((int) $request->input('inventory_type_id'));

        if ($legacyType) {
            $request->merge(['product_type' => $legacyType]);
        }
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

        // Se calculaba pero nunca se asignaba: sin company_id en el cuerpo el
        // INSERT fallaba, y si llegaba como arreglo (company_id[]) se intentaba
        // guardar el arreglo tal cual.
        $validatedData['company_id'] = $companyId;

        // Remover campos que no van a la base de datos (se procesan después de crear)
        unset($validatedData['activate_in_all_locations']);
        unset($validatedData['activate_in_current_location']);
        unset($validatedData['current_location_id']);
        unset($validatedData['ingredients']); // Los ingredientes se procesan por separado
        unset($validatedData['packaging_materials']); // El material de empaque también

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
        unset($validatedData['packaging_materials']); // El material de empaque también

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

        // Manejar el material de empaque que consume cada unidad
        $this->handleProductPackagingMaterials($model, $request, false);

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

        // Manejar el material de empaque que consume cada unidad
        $this->handleProductPackagingMaterials($model, $request, true);

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
                    // Un campo vacío llega como null (ConvertEmptyStringsToNull)
                    // y el default de input() solo aplica si falta la llave: por
                    // eso `?? 0`, las columnas no aceptan NULL.
                    $updateData = [];
                    $updateData['active'] = $isActive;

                    if ($request->has('minimum_stock')) {
                        $updateData['minimum_stock'] = $request->input('minimum_stock') ?? 0;
                    }
                    if ($request->has('maximum_stock')) {
                        $updateData['maximum_stock'] = $request->input('maximum_stock') ?? 0;
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
                            'minimum_stock' => $request->input('minimum_stock') ?? 0,
                            'maximum_stock' => $request->input('maximum_stock') ?? 0,
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
     * Sincroniza el material de empaque que consume una unidad del producto.
     *
     * Igual que con los ingredientes: en actualización sólo se reemplaza si el
     * campo viene en la petición, para que un guardado parcial no borre la
     * receta de empaque ya capturada.
     */
    private function handleProductPackagingMaterials(Model $product, Request $request, bool $isUpdate = false): void
    {
        // Una lista vacía no viaja en un FormData, así que no se puede
        // distinguir "borré todo el material" de "no mandé el campo" sólo por
        // `packaging_materials`. El switch `requires_packaging` sí viaja
        // siempre que el formulario trae la sección de empaque, y es lo que se
        // usa como señal: con él presente, sin filas significa lista vacía.
        $formIncludesPackaging = $request->has('packaging_materials')
            || $request->has('requires_packaging');

        if ($isUpdate && !$formIncludesPackaging) {
            return;
        }

        $materials = $request->input('packaging_materials', []);

        $product->packagingMaterials()->delete();

        foreach (array_values($materials) as $index => $material) {
            $materialId = (int) ($material['material_product_id'] ?? 0);
            $quantity = (float) ($material['quantity'] ?? 0);

            if (!$materialId || $quantity <= 0) {
                continue;
            }

            // Un producto no puede ser su propio material de empaque.
            if ($materialId === (int) $product->id) {
                continue;
            }

            $product->packagingMaterials()->create([
                'material_product_id' => $materialId,
                'unit_id' => (int) ($material['unit_id'] ?? 0)
                    ?: (int) Product::whereKey($materialId)->value('unit_id'),
                'quantity' => $quantity,
                'sort_order' => $index,
                'notes' => $material['notes'] ?? null,
            ]);
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
