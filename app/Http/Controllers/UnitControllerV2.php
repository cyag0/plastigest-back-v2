<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\UnitResource;
use App\Models\Unit;
use App\Services\StockDefinitionGuard;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
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
    protected ?string $permissionPrefix = 'units';

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
        // Sin filtro de empresa propio: applyTenantScope() ya deja las
        // unidades globales (company_id null) y las de la empresa actual.
        // Antes aquí se forzaba company_id null y una unidad creada por la
        // empresa (quien no es system_admin crea unidades de su empresa)
        // nunca aparecía en la lista ni en los selectores.

        // Tipo de unidad: quantity, mass, volume, length.
        if (!empty($params['unit_type'])) {
            $query->where('unit_type', $params['unit_type']);
        }

        // Origen: las del sistema (compartidas, sin empresa) o las que creó
        // la empresa.
        match ($params['origin'] ?? null) {
            'system' => $query->whereNull('company_id'),
            'company' => $query->whereNotNull('company_id'),
            default => null,
        };
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
            // Sin unidad de referencia hay que decir de qué tipo es (masa,
            // volumen, pieza…): la columna no admite nulos y antes respondía 500.
            'unit_type' => 'required_without:base_unit_id|nullable|string|max:50',
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

            // base_unit_id no es columna de la tabla (mandarlo respondía 500):
            // indica de qué unidad se deriva esta y el factor es respecto a ella
            // (1 caja = 12 piezas). Se traduce al tipo de la unidad de referencia
            // y a un factor respecto a la base de ese tipo.
            $baseUnitId = $data['base_unit_id'] ?? null;
            unset($data['base_unit_id']);

            if ($baseUnitId) {
                $referenceUnit = Unit::findOrFail($baseUnitId);
                $data['unit_type'] = $referenceUnit->unit_type;
                $data['factor_to_base'] = (float) ($data['factor_to_base'] ?? 1) * (float) ($referenceUnit->factor_to_base ?: 1);
                $data['is_base_unit'] = false;
            } elseif ($method === 'create') {
                // Unidad nueva sin referencia: es la base de su tipo.
                $data['factor_to_base'] = 1;
                $data['is_base_unit'] = !Unit::query()
                    ->where('unit_type', $data['unit_type'])
                    ->where('is_base_unit', true)
                    ->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $data['company_id'] ?? null))
                    ->exists();
            }
            // En una edición sin unidad de referencia el factor se queda como
            // está, salvo que se mande explícitamente. Antes se reescribía a 1:
            // cambiarle el nombre a "Gramo" lo convertía en kilogramo y cada
            // consumo de 500 g descontaba 500 kg.

            if ($method === 'update') {
                // La empresa dueña no cambia al editar: una unidad global no
                // puede volverse de una empresa (ni al revés) desde aquí.
                unset($data['company_id']);
            } elseif (!CurrentWorker::hasPermission('system_admin')) {
                // Solo un administrador del sistema crea unidades para otra empresa.
                $data['company_id'] = CurrentCompany::id() ?? ($data['company_id'] ?? null);
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
     * Edición con la unidad ya validada por denyIfNotEditable(). El factor y
     * el tipo no cambian si la unidad está en uso (ver StockDefinitionGuard):
     * antes se podía cambiar el factor de una unidad con stock y documentos
     * y cada conversión posterior salía mal. Nombre y abreviatura sí.
     */
    public function edit(array $data, int $id): Model
    {
        $unit = $this->findModel($id);
        if (!$unit) {
            abort(404, 'Recurso no encontrado');
        }

        $factorChanges = array_key_exists('factor_to_base', $data) && $data['factor_to_base'] !== null
            && round((float) $data['factor_to_base'], 6) !== round((float) $unit->factor_to_base, 6);
        $typeChanges = array_key_exists('unit_type', $data)
            && (string) $data['unit_type'] !== (string) $unit->unit_type;

        if ($factorChanges || $typeChanges) {
            app(StockDefinitionGuard::class)->assertUnitCanChange($unit);
        }

        $unit->update($data);

        return $unit;
    }

    public function update(Request $request, $id)
    {
        if (!$this->canEdit()) {
            return $this->forbiddenResponse('editar este recurso');
        }

        return $this->denyIfNotEditable($id) ?? parent::update($request, $id);
    }

    public function destroy($id)
    {
        if (!$this->canDestroy()) {
            return $this->forbiddenResponse('eliminar este recurso');
        }

        return $this->denyIfNotEditable($id) ?? parent::destroy($id);
    }

    /**
     * Las unidades globales (company_id null) las comparten todas las empresas:
     * cambiar su factor descuadra el inventario de todas. Solo las modifica un
     * administrador del sistema (system_admin). Las de una empresa, solo esa
     * empresa. Si la unidad no existe, el método padre responde 404.
     */
    private function denyIfNotEditable($id): ?JsonResponse
    {
        $unit = Unit::find($id);

        if (!$unit) {
            return null;
        }

        if ($unit->company_id === null) {
            return CurrentWorker::hasPermission('system_admin')
                ? null
                : response()->json([
                    'message' => 'Las unidades globales solo las puede modificar un administrador del sistema.',
                ], 403);
        }

        if ((int) $unit->company_id !== (int) CurrentCompany::id()) {
            return response()->json(['message' => 'Recurso no encontrado'], 404);
        }

        return null;
    }

    /**
     * Validar si se puede eliminar
     */
    protected function canDelete(Model $model): array
    {
        $products = \Illuminate\Support\Facades\DB::table('products')->where('unit_id', $model->id)->count();
        if ($products > 0) {
            return [
                'can_delete' => false,
                'message' => $products === 1
                    ? 'No se puede eliminar: 1 producto usa esta unidad.'
                    : "No se puede eliminar: {$products} productos usan esta unidad.",
            ];
        }

        return ['can_delete' => true, 'message' => ''];
    }

    /**
     * Hook ejecutado antes de eliminar
     */
    protected function beforeDestroy(Model $model): void
    {
        // Las conversiones viven en la propia unidad (unit_type + factor_to_base).
        // La tabla unit_conversions ya no existe: borrar de ella hacía fallar
        // la eliminación de cualquier unidad.
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
            $targetUnitId = (int) $request->input('unit_id', 0);

            // Unidades globales y las de la empresa actual, con el mismo
            // aislamiento que la lista. Antes un company_id en la petición
            // dejaba ver las unidades de cualquier empresa.
            $allUnits = $this->scopedQuery()->orderBy('name')->get();

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
                'message' => 'Error al obtener unidades agrupadas.', 'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    public function getGroupedByType(Request $request): JsonResponse
    {
        try {
            // Globales y de la empresa actual: antes solo las globales, y la
            // compra no ofrecía la unidad propia de un producto.
            $allUnits = $this->scopedQuery()->orderBy('name')->get();

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
                'message' => 'Error al obtener unidades agrupadas.', 'error' => $this->errorDetail($e)
            ], 500);
        }
    }
}
