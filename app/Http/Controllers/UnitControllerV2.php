<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UnitResource;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class UnitControllerV2 extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = UnitResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Unit::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtro por compañía
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por estado activo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Filtro por tipo
        if (isset($params['type'])) {
            $query->where('type', $params['type']);
        }

        // Solo unidades base
        if (isset($params['is_base'])) {
            $query->where('is_base', $params['is_base']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        // Normalizar is_active
        if ($request->has('is_active')) {
            $val = $request->input('is_active');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_active' => (bool)$val]);
        }

        // Normalizar is_base
        if ($request->has('is_base')) {
            $val = $request->input('is_base');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_base' => (bool)$val]);
        }

        return $request->validate([
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'description' => 'nullable|string',
            'type' => 'required|in:quantity,length,weight,volume,other',
            'is_base' => 'boolean',
            'conversion_rate' => 'required|numeric|min:0.000001',
            'is_active' => 'boolean',
            'company_id' => 'required|exists:companies,id'
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        // Normalizar is_active
        if ($request->has('is_active')) {
            $val = $request->input('is_active');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_active' => (bool)$val]);
        }

        // Normalizar is_base
        if ($request->has('is_base')) {
            $val = $request->input('is_base');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_base' => (bool)$val]);
        }

        return $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'symbol' => 'sometimes|required|string|max:10',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:quantity,length,weight,volume,other',
            'is_base' => 'boolean',
            'conversion_rate' => 'sometimes|required|numeric|min:0.000001',
            'is_active' => 'boolean',
            'company_id' => 'sometimes|required|exists:companies,id'
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method): Model
    {
        try {
            DB::beginTransaction();

            // Normalizar company_id si viene como array
            $data["company_id"] = isset($data["company_id"][0]) ? $data["company_id"][0] : $data["company_id"];

            // Validar unidad base única por tipo y compañía
            if (($data['is_base'] ?? false) && $method === 'create') {
                $existingBase = Unit::where('company_id', $data['company_id'])
                    ->where('type', $data['type'])
                    ->where('is_base', true)
                    ->exists();

                if ($existingBase) {
                    throw new \Exception('Ya existe una unidad base para el tipo ' . $data['type']);
                }

                // Si es base, el conversion_rate debe ser 1
                $data['conversion_rate'] = 1.000000;
            }

            // Para update, verificar si está cambiando a unidad base
            if (($data['is_base'] ?? false) && $method === 'update') {
                $model = Unit::find($data['id'] ?? null);

                if ($model) {
                    $existingBase = Unit::where('company_id', $model->company_id)
                        ->where('type', $data['type'] ?? $model->type)
                        ->where('is_base', true)
                        ->where('id', '!=', $model->id)
                        ->exists();

                    if ($existingBase) {
                        throw new \Exception('Ya existe una unidad base para el tipo ' . ($data['type'] ?? $model->type));
                    }

                    // Si es base, el conversion_rate debe ser 1
                    $data['conversion_rate'] = 1.000000;
                }
            }

            // El callback ejecuta el store() o update() del modelo
            $model = $callback($data);

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        // Aquí puedes agregar validaciones si hay productos usando esta unidad
        // Por ejemplo:
        // if ($model->products()->exists()) {
        //     return [
        //         'can_delete' => false,
        //         'message' => 'No se puede eliminar porque hay productos usando esta unidad'
        //     ];
        // }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
