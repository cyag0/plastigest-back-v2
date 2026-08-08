<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryTypeResource;
use App\Models\InventoryType;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de tipos de inventario (materia prima, material de empaque,
 * semiterminado, producto terminado, mercancía de reventa, insumo).
 *
 * El listado devuelve los tipos del sistema más los propios de la empresa actual.
 * Con `with_counts=1` incluye `products_count` para el menú de la app.
 */
class InventoryTypeController extends CrudController
{
    protected string $resource = InventoryTypeResource::class;
    protected string $model = InventoryType::class;
    protected ?string $permissionPrefix = 'inventory_types';

    protected function indexRelations(): array
    {
        return [];
    }

    protected function getShowRelations(): array
    {
        return [];
    }

    /**
     * Leer el catálogo es parte de ver productos: quien puede listar productos
     * puede ver los tipos, aunque su rol todavía no tenga el permiso nuevo.
     * Crear, editar y eliminar sí exigen los permisos de inventory_types.
     */
    protected function canIndex(): bool
    {
        return parent::canIndex() || CurrentWorker::hasPermission('products_list');
    }

    protected function canRead(): bool
    {
        return parent::canRead() || CurrentWorker::hasPermission('products_read');
    }

    /**
     * El filtro base de company_id excluiría los tipos del sistema (company_id null),
     * así que aquí se resuelve el alcance con el scope forCompany.
     */
    protected function applyBasicFilters($query, array $params)
    {
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (isset($params['is_active'])) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    protected function handleQuery($query, array $params)
    {
        $companyId = $params['company_id'] ?? current_company_id();

        $query->forCompany($companyId);

        // Filtrar por comportamiento: ?is_sellable=1 para el POS, ?is_consumable=1
        // para el selector de insumos de una fórmula, etc.
        foreach (InventoryType::BEHAVIOR_FLAGS as $flag) {
            if (isset($params[$flag]) && $params[$flag] !== '') {
                $query->where($flag, filter_var($params[$flag], FILTER_VALIDATE_BOOLEAN));
            }
        }

        if (!empty($params['code'])) {
            $query->whereIn('code', (array) $params['code']);
        }

        if (!empty($params['slug'])) {
            $query->whereIn('slug', (array) $params['slug']);
        }

        // Conteo de productos para el menú de tipos de inventario.
        if (!empty($params['with_counts'])) {
            $query->withCount(['products' => function ($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
            }]);
        }
    }

    /**
     * Los tipos son un catálogo corto: se ordena por sort_order salvo que se pida otra cosa.
     */
    protected function applyOrdering($query, array $params)
    {
        if (isset($params['sort_by'])) {
            return parent::applyOrdering($query, $params);
        }

        $query->orderBy('sort_order')->orderBy('name');
    }

    protected function validateStoreData(Request $request): array
    {
        $companyId = $request->input('company_id') ?? current_company_id();

        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('inventory_types', 'code')->where(
                    fn($q) => $q->where('company_id', $companyId)
                ),
            ],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:60',
            'color' => 'nullable|string|max:9',
            'is_purchasable' => 'boolean',
            'is_sellable' => 'boolean',
            'is_producible' => 'boolean',
            'is_consumable' => 'boolean',
            'is_stockable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ], [
            'code.regex' => 'El código sólo admite minúsculas, números y guiones bajos, y debe iniciar con una letra.',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        $rules = [
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:60',
            'color' => 'nullable|string|max:9',
            'is_purchasable' => 'boolean',
            'is_sellable' => 'boolean',
            'is_producible' => 'boolean',
            'is_consumable' => 'boolean',
            'is_stockable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];

        // El código de un tipo del sistema no se cambia: hay lógica que lo referencia.
        if (!$model->is_system) {
            $rules['code'] = [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('inventory_types', 'code')
                    ->where(fn($q) => $q->where('company_id', $model->company_id))
                    ->ignore($model->id),
            ];
        }

        return $request->validate($rules);
    }

    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Un tipo creado desde la app siempre pertenece a una empresa; los del
        // sistema (company_id null, is_system) sólo se crean en la migración.
        $validatedData['company_id'] = $request->input('company_id') ?? current_company_id();

        return $validatedData;
    }

    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        unset($validatedData['company_id'], $validatedData['is_system']);

        return $validatedData;
    }

    protected function canDelete(Model $model): array
    {
        if ($model->is_system) {
            return [
                'can_delete' => false,
                'message' => 'Los tipos de inventario del sistema no se pueden eliminar. Puedes desactivarlos.',
            ];
        }

        $productsCount = $model->products()->count();

        if ($productsCount > 0) {
            return [
                'can_delete' => false,
                'message' => "No se puede eliminar: hay {$productsCount} producto(s) con este tipo de inventario.",
            ];
        }

        return [
            'can_delete' => true,
            'message' => '',
        ];
    }
}
