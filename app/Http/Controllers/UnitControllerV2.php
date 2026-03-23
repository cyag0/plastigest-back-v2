<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UnitResource;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Support\CurrentCompany;
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
        return [];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query;

        // Filtro por compañía
        /*  if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        } */
        $query->where('company_id', null);
        /* if (isset($params["only_base"])) {
            $query->where('base_unit_id', null);
        }
 */
        // Filtro para obtener unidad base + derivadas de un producto
        /*  if (isset($params['product_unit_id'])) {
            $query->where(function ($q) use ($params) {
                $q->where('id', $params['product_unit_id'])
                    ->orWhere('base_unit_id', $params['product_unit_id']);
            });
        } */
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

    /**
     * Obtener todas las unidades agrupadas por unidad base
     * Retorna un objeto donde cada clave es el ID de la unidad base
     * y el valor es un array con la unidad base y sus unidades derivadas
     */
    public function getGroupedByBase(Request $request): JsonResponse
    {
        try {
            $currentCompany = CurrentCompany::get();
            $companyId = $request->input('company_id') ?? ($currentCompany?->id);
            $targetUnitId = (int) $request->input('unit_id', 0);

            // Incluir unidades globales (company_id null) y, si existe, unidades de la compañía actual
            $allUnits = Unit::query()
                ->where(function ($query) use ($companyId) {
                    $query->whereNull('company_id');

                    if ($companyId) {
                        $query->orWhere('company_id', $companyId);
                    }
                })
                ->orderBy('name')
                ->get();

            // Agrupar por tipo de unidad y elegir una base por tipo.
            $grouped = [];
            $groupsByType = $allUnits->groupBy('unit_type');

            foreach ($groupsByType as $unitType => $unitsInType) {
                $baseUnit = $unitsInType->firstWhere('is_base_unit', true)
                    ?? $unitsInType->first(function ($unit) {
                        return (float) ($unit->factor_to_base ?? 0) === 1.0;
                    })
                    ?? $unitsInType->first();

                if (!$baseUnit) {
                    continue;
                }

                $grouped[(string) $baseUnit->id] = $unitsInType
                    ->sortBy(function ($unit) use ($baseUnit) {
                        return (int) ($unit->id !== $baseUnit->id);
                    })
                    ->map(function ($unit) use ($baseUnit, $targetUnitId, $unitType) {
                        $isBase = (int) $unit->id === (int) $baseUnit->id;

                        return [
                            'id' => $unit->id,
                            'name' => $unit->name,
                            'abbreviation' => $unit->abbreviation,
                            'unit_type' => $unitType,
                            'base_unit_id' => $isBase ? null : $baseUnit->id,
                            'factor_to_base' => (float) ($unit->factor_to_base ?? 1),
                            'is_base' => $isBase,
                            'is_target' => (int) $unit->id === $targetUnitId,
                        ];
                    })
                    ->values()
                    ->toArray();
            }

            // Si se solicita una unidad específica, devolver solo su grupo compatible.
            if ($targetUnitId > 0) {
                foreach ($grouped as $baseId => $units) {
                    $exists = collect($units)->contains(fn($item) => (int) ($item['id'] ?? 0) === $targetUnitId);
                    if ($exists) {
                        return response()->json([
                            'success' => true,
                            'data' => [
                                (string) $baseId => $units,
                            ],
                        ]);
                    }
                }

                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $grouped
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades agrupadas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getGroupedByType(Request $request): JsonResponse
    {
        try {

            // Obtener todas las unidades de la compañía
            $allUnits = Unit::where('company_id', null)
                ->orderBy('name')
                ->get();


            $units = [];

            foreach ($allUnits as $unit) {
                $units[$unit->unit_type][] = $unit;
            }

            return response()->json([
                'success' => true,
                'data' => $units
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades agrupadas: ' . $e->getMessage()
            ], 500);
        }
    }
}
