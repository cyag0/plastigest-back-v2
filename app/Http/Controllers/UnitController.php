<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Http\Resources\Admin\UnitResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $request->input('company_id');
        
        $units = Unit::query()
            ->when($companyId, function($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->where('is_active', true)
            ->with('company')
            ->get();

        return UnitResource::collection($units);
    }

    public function store(Request $request): UnitResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'description' => 'nullable|string',
            'type' => 'required|in:quantity,length,weight,volume,other',
            'is_base' => 'boolean',
            'conversion_rate' => 'required|numeric|min:0.000001',
            'is_active' => 'boolean',
            'company_id' => 'required|exists:companies,id'
        ]);

        // Validar que solo haya una unidad base por tipo y compañía
        if ($validated['is_base'] ?? false) {
            $existingBase = Unit::where('company_id', $validated['company_id'])
                ->where('type', $validated['type'])
                ->where('is_base', true)
                ->exists();

            if ($existingBase) {
                return response()->json([
                    'message' => 'Ya existe una unidad base para este tipo',
                    'errors' => [
                        'is_base' => ['Ya existe una unidad base para el tipo ' . $validated['type']]
                    ]
                ], 422);
            }

            // Si es base, el conversion_rate debe ser 1
            $validated['conversion_rate'] = 1.000000;
        }

        $unit = Unit::create($validated);

        return new UnitResource($unit);
    }

    public function show(Request $request, Unit $unit): UnitResource
    {
        $companyId = $request->input('company_id');
        
        if ($companyId && $unit->company_id !== (int)$companyId) {
            abort(403, 'No autorizado');
        }

        $unit->load('company');

        return new UnitResource($unit);
    }

    public function update(Request $request, Unit $unit): UnitResource|JsonResponse
    {
        $companyId = $request->input('company_id');
        
        if ($companyId && $unit->company_id !== (int)$companyId) {
            abort(403, 'No autorizado');
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'symbol' => 'sometimes|required|string|max:10',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:quantity,length,weight,volume,other',
            'is_base' => 'boolean',
            'conversion_rate' => 'sometimes|required|numeric|min:0.000001',
            'is_active' => 'boolean',
            'company_id' => 'sometimes|required|exists:companies,id'
        ]);

        // Validar que solo haya una unidad base por tipo y compañía
        if (isset($validated['is_base']) && $validated['is_base']) {
            $existingBase = Unit::where('company_id', $unit->company_id)
                ->where('type', $validated['type'] ?? $unit->type)
                ->where('is_base', true)
                ->where('id', '!=', $unit->id)
                ->exists();

            if ($existingBase) {
                return response()->json([
                    'message' => 'Ya existe una unidad base para este tipo',
                    'errors' => [
                        'is_base' => ['Ya existe una unidad base para el tipo ' . ($validated['type'] ?? $unit->type)]
                    ]
                ], 422);
            }

            // Si es base, el conversion_rate debe ser 1
            $validated['conversion_rate'] = 1.000000;
        }

        $unit->update($validated);
        $unit->load('company');

        return new UnitResource($unit);
    }

    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        $companyId = $request->input('company_id');
        
        if ($companyId && $unit->company_id !== (int)$companyId) {
            abort(403, 'No autorizado');
        }

        $unit->delete();

        return response()->json([
            'message' => 'Unidad eliminada correctamente'
        ]);
    }
}
