<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UnitResource;
use App\Models\Unit;
use App\Models\UnitConversion;
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
            'company',
            'baseUnit',
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company',
            'baseUnit',
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query;

        // Filtro por compañía
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        if (isset($params["only_base"])) {
            $query->where('base_unit_id', null);
        }

        // Filtro para obtener unidad base + derivadas de un producto
        if (isset($params['product_unit_id'])) {
            $query->where(function ($q) use ($params) {
                $q->where('id', $params['product_unit_id'])
                  ->orWhere('base_unit_id', $params['product_unit_id']);
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'abbreviation' => 'required|string|max:20',
            'company_id' => 'required|exists:companies,id',
            'base_unit_id' => 'nullable|exists:units,id',
            'factor_to_base' => 'nullable|numeric|min:0.000001',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'abbreviation' => 'sometimes|required|string|max:20',
            'company_id' => 'sometimes|required|exists:companies,id',
            'base_unit_id' => 'nullable|exists:units,id',
            'factor_to_base' => 'nullable|numeric|min:0.000001',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     */
    protected function process($callback, array $data, $method): Model
    {
        try {
            DB::beginTransaction();


            // Si no viene base_unit_id, se asume unidad base -> factor 1
            if (!isset($data['base_unit_id'])) {
                $data['factor_to_base'] = 1;
            }

            /** @var Unit $model */
            $model = $callback($data);

            DB::commit();

            // Recargar relaciones
            $model->load($this->getShowRelations());

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

    /**
     * Hook ejecutado antes de eliminar
     */
    protected function beforeDestroy(Model $model): void
    {
        // Eliminar conversiones relacionadas antes de eliminar la unidad
        UnitConversion::where('from_unit_id', $model->id)
            ->orWhere('to_unit_id', $model->id)
            ->delete();
    }

    /**
     * Hook ejecutado después de eliminar (opcional)
     */
    protected function afterDestroy(Model $model): void
    {
        // Aquí puedes ejecutar lógica adicional después de eliminar
        // Por ejemplo: limpiar cache, enviar notificaciones, etc.
    }
}
