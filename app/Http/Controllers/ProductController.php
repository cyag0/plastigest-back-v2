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
            'mainImage',
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

        // Filtrar por estado activo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Búsqueda por nombre o código
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['search'] . '%')
                    ->orWhere('code', 'like', '%' . $params['search'] . '%')
                    ->orWhere('description', 'like', '%' . $params['search'] . '%');
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'code' => 'required|string|max:50|unique:products,code',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'company_id' => 'required|exists:companies,id',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'is_active' => 'boolean',
            'product_images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
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
            'is_active' => 'nullable|boolean',
            'product_images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Procesar datos antes de crear si es necesario
        // Ejemplo: agregar company_id del usuario autenticado
        // $validatedData['company_id'] = auth()->user()->company_id;
        $companyIdArray = $request->input('company_id');
        $companyId = is_array($companyIdArray) ? reset($companyIdArray) : $companyIdArray;

        $categoryIdArray = $request->input('category_id');
        $categoryId = is_array($categoryIdArray) ? reset($categoryIdArray) : $categoryIdArray;

        $validatedData['company_id'] = $companyId;
        $validatedData['category_id'] = $categoryId;

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Procesar datos antes de actualizar si es necesario
        // Ejemplo: no permitir cambiar company_id
        // unset($validatedData['company_id']);

        $companyIdArray = $request->input('company_id');
        $companyId = is_array($companyIdArray) ? reset($companyIdArray) : $companyIdArray;

        $categoryIdArray = $request->input('category_id');
        $categoryId = is_array($categoryIdArray) ? reset($categoryIdArray) : $categoryIdArray;

        $validatedData['company_id'] = $companyId;
        $validatedData['category_id'] = $categoryId;

        return $validatedData;
    }

    /**
     * Acciones después de crear (opcional)
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Procesar imágenes del producto si se enviaron
        $this->handleProductImages($model, $request);
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Procesar imágenes del producto si se enviaron
        $this->handleProductImages($model, $request, true);
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
        // Verificar si se enviaron imágenes
        if (!$request->hasFile('product_images')) {
            return;
        }

        $newImages = $request->file('product_images');

        if ($isUpdate) {
            // Al actualizar: reemplazar todas las imágenes existentes
            $existingImages = $product->images;
            $oldFileNames = $existingImages->pluck('image_path')->toArray(); // Solo nombres, no paths completos

            // Reemplazar archivos usando la utilidad (por nombres)
            $result = AppUploadUtil::syncFilesByNames(
                Files::PRODUCT_IMAGES_PATH,
                $newImages,
                $oldFileNames,
                "product_{$product->id}"
            );

            // Eliminar registros antiguos de la base de datos
            $existingImages->each(function ($image) {
                $image->delete();
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
}
