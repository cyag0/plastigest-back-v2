<?php

namespace App\Http\Controllers;

use App\Models\ProductPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductPackageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = ProductPackage::with('product');

        // Filtrar por producto
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filtrar por compañía
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Solo activos
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Ordenar
        $query->orderBy('sort_order')->orderBy('package_name');

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'company_id' => 'required|exists:companies,id',
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
            // Si es el empaque por defecto, desmarcar los demás
            if ($validated['is_default'] ?? false) {
                ProductPackage::where('product_id', $validated['product_id'])
                    ->update(['is_default' => false]);
            }

            $package = ProductPackage::create($validated);

            DB::commit();
            return response()->json($package->load('product'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el empaque',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $package = ProductPackage::with('product')->findOrFail($id);
        return response()->json([
            'data' => $package
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $package = ProductPackage::findOrFail($id);

        $validated = $request->validate([
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
            return response()->json($package->load('product'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el empaque',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $package = ProductPackage::findOrFail($id);
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

        $package = ProductPackage::with('product')
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
}
