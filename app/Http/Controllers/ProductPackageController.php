<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductPackage;
use App\Services\StockDefinitionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use App\Support\BarcodeLabelPdf;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;

class ProductPackageController extends Controller
{
    private function forbidden()
    {
        return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
    }

    /**
     * Query base de empaques, aislada por la empresa del contexto. Sin esto,
     * los empaques (y sus precios) de otra empresa quedaban visibles/editables.
     */
    private function scoped()
    {
        return ProductPackage::query()->where('company_id', CurrentCompany::id());
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if (!CurrentWorker::hasPermission('products_list')) {
            return $this->forbidden();
        }

        // La cantidad del paquete va en la unidad del producto: la lista la
        // muestra con esa abreviatura (la unidad propia del paquete ya no se usa).
        $query = $this->scoped()->with(['product.unit', 'unit']);

        // Filtrar por producto. filled y no has: un product_id vacío llega
        // como null y filtraba por "product_id IS NULL" (lista vacía).
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Solo activos
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Estado (filtro de la lista): activos o inactivos.
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Categoría del producto del empaque.
        if ($request->filled('category_id')) {
            $query->whereHas('product', fn($q) => $q->where('category_id', (int) $request->input('category_id')));
        }

        // Búsqueda general: nombre del empaque, código de barras o nombre del producto
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('package_name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search) {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        // Ordenar
        $query->orderBy('sort_order')->orderBy('package_name');

        // Paginación opcional (el frontend la solicita con paginated=true).
        // Se envuelve en un JsonResource para devolver el sobre data/links/meta
        // que espera el frontend (AppList lee response.data.data y response.data.meta).
        if ($request->boolean('paginated')) {
            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            return JsonResource::collection($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (!CurrentWorker::hasPermission('products_create')) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', CurrentCompany::id())],
            'unit_id' => 'nullable|exists:units,id',
            'package_name' => 'required|string|max:100',
            'barcode' => 'required|string|max:100|unique:product_packages,barcode',
            'quantity_per_package' => 'required|numeric|min:0.01',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'content' => 'nullable|array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);

        DB::beginTransaction();
        try {
            $company = CurrentCompany::get();
            if (!$company) {
                throw new \Exception('No se ha establecido la empresa actual');
            }

            $validated['company_id'] = $company->id;

            // Si es el empaque por defecto, desmarcar los demás
            if ($validated['is_default'] ?? false) {
                ProductPackage::where('product_id', $validated['product_id'])
                    ->update(['is_default' => false]);
            }

            $package = ProductPackage::create($validated);

            DB::commit();
            return response()->json($package->load(['product.unit', 'unit']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el empaque',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        if (!CurrentWorker::hasPermission('products_read')) {
            return $this->forbidden();
        }

        $package = $this->scoped()->with(['product.unit', 'unit'])->findOrFail($id);
        return response()->json([
            'data' => $package
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        if (!CurrentWorker::hasPermission('products_update')) {
            return $this->forbidden();
        }

        $package = $this->scoped()->findOrFail($id);

        $validated = $request->validate([
            'unit_id' => 'nullable|exists:units,id',
            'package_name' => 'sometimes|string|max:100',
            'barcode' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('product_packages', 'barcode')->ignore($id)
            ],
            'quantity_per_package' => 'sometimes|numeric|min:0.01',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'content' => 'nullable|array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);

        // El contenido (cuántas unidades base trae) no cambia si el producto ya
        // tiene existencia, movimientos o documentos abiertos: las compras,
        // transferencias y ventas en esta presentación se convertirían con el
        // contenido nuevo (422 package_in_use). Nombre, precios y código sí.
        if (array_key_exists('quantity_per_package', $validated)
            && round((float) $validated['quantity_per_package'], 2) !== round((float) $package->quantity_per_package, 2)) {
            try {
                app(StockDefinitionGuard::class)->assertPackageCanChange($package);
            } catch (BusinessRuleException $e) {
                return $e->render();
            }
        }

        DB::beginTransaction();
        try {
            // Si se marca como por defecto, desmarcar los demás
            if (isset($validated['is_default']) && $validated['is_default']) {
                ProductPackage::where('product_id', $package->product_id)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $package->update($validated);

            DB::commit();
            return response()->json($package->load(['product.unit', 'unit']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el empaque',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        if (!CurrentWorker::hasPermission('products_delete')) {
            return $this->forbidden();
        }

        $package = $this->scoped()->findOrFail($id);
        $package->delete();

        return response()->json([
            'message' => 'Empaque eliminado correctamente'
        ]);
    }

    /**
     * Buscar empaque por código de barras
     */
    public function searchByBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string'
        ]);

        $package = ProductPackage::with(['product.unit', 'unit'])
            ->byBarcode($request->barcode)
            ->active()
            ->first();

        if (!$package) {
            return response()->json([
                'message' => 'Empaque no encontrado'
            ], 404);
        }

        return response()->json($package);
    }

    /**
     * Generar código de barras único
     */
    public function generateBarcode(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        do {
            // Formato: PLG-{productId}-{timestamp}-{random}
            $barcode = 'PKG-' . $request->product_id . '-' . time() . rand(100, 999);
        } while (ProductPackage::where('barcode', $barcode)->exists());

        return response()->json([
            'barcode' => $barcode
        ]);
    }

    /**
     * URL firmada (1 hora) del PDF de etiquetas del empaque. Lleva el
     * company_id dentro de la firma para validarlo al renderizar.
     */
    public function generateLabelsUrl(Request $request, string $id)
    {
        if (!CurrentWorker::hasPermission('products_list')) {
            return $this->forbidden();
        }

        $validated = validator(
            ['quantity' => $request->query('quantity', 1)],
            ['quantity' => 'required|integer|min:1|max:100']
        )->validate();

        $package = $this->scoped()->findOrFail($id);

        $signedUrl = URL::temporarySignedRoute(
            'product-packages.labels.pdf',
            now()->addHour(),
            ['package' => $package->id, 'quantity' => (int) $validated['quantity'], 'company_id' => CurrentCompany::id()]
        );

        return response()->json([
            'url' => $signedUrl,
            'expires_at' => now()->addHour()->toISOString(),
        ]);
    }

    /**
     * PDF de etiquetas del empaque para impresora térmica (ruta pública con
     * URL firmada): nombre del producto, empaque y contenido, código de
     * barras del empaque y su precio.
     */
    public function printLabels(Request $request, string $package)
    {
        $validated = validator(
            ['quantity' => $request->query('quantity')],
            ['quantity' => 'required|integer|min:1|max:100']
        )->validate();

        // Defensa en profundidad: el company_id firmado debe ser el del empaque.
        $companyId = $request->query('company_id');
        abort_if($companyId === null, 404);

        $model = ProductPackage::with('product.unit')
            ->where('company_id', $companyId)
            ->find($package);
        abort_if(!$model, 404);

        $quantityPerPackage = rtrim(rtrim(number_format((float) $model->quantity_per_package, 2, '.', ''), '0'), '.');
        $unit = $model->product?->unit?->abbreviation;

        return BarcodeLabelPdf::stream(
            title: $model->product?->name ?? $model->package_name,
            code: $model->barcode,
            quantity: (int) $validated['quantity'],
            fileName: 'etiquetas-paquete-' . $model->barcode . '.pdf',
            subtitle: trim("{$model->package_name} · {$quantityPerPackage} {$unit}"),
            price: $model->sale_price,
        );
    }
}
