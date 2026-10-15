<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PrunesEmptyRows;
use App\Http\Resources\FormulaResource;
use App\Models\Operations\Formula;
use App\Services\FormulaService;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FormulaController extends CrudController
{
    use PrunesEmptyRows;

    protected string $resource = FormulaResource::class;
    protected string $model = Formula::class;
    protected ?string $permissionPrefix = 'formulas';
    protected string $dateColumn = 'created_at';

    public function __construct(private readonly FormulaService $formulaService) {}

    protected function indexRelations(): array
    {
        return [
            'items.product',
            'items.unit',
            'outputs.product.mainImage',
            'outputs.product.unit',
            'outputs.unit',
        ];
    }

    protected function getShowRelations(): array
    {
        return [
            'items.product',
            'items.unit',
            'outputs.product.mainImage',
            'outputs.product.unit',
            'outputs.unit',
            'createdBy',
            'updatedBy',
        ];
    }

    protected function handleQuery($query, array $params)
    {
        // El producto puede participar como insumo o como resultado.
        if (isset($params['product_id'])) {
            $query->byProduct((int) $params['product_id']);
        }
        // Sólo las fórmulas que PRODUCEN el producto (para el paso de empaque).
        if (isset($params['produces_product_id'])) {
            $query->producing((int) $params['produces_product_id']);
        }
        if (isset($params['is_active'])) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('outputs.product', fn($qq) => $qq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items.product', fn($qq) => $qq->where('name', 'like', "%{$search}%"));
            });
        }
    }

    protected function applyBasicFilters($query, array $params)
    {
        // Skip generic search — handleQuery has a formula-specific search
        // that also searches by related product name.
        parent::applyBasicFilters($query, array_diff_key($params, ['search' => true]));
    }

    /**
     * Ingredientes y productos resultantes: de la empresa actual. Antes
     * bastaba con que el id existiera, y una fórmula con el producto de otra
     * empresa movía su stock al producir.
     */
    private function companyProductRule()
    {
        return Rule::exists('products', 'id')->where('company_id', CurrentCompany::id());
    }

    protected function validateStoreData(Request $request): array
    {
        $this->pruneEmptyRows($request, 'items', 'product_id');
        $this->pruneEmptyRows($request, 'outputs', 'product_id');

        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',

            // Ingredientes que consume el proceso.
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', $this->companyProductRule()],
            'items.*.unit_id' => 'required|exists:units,id',
            'items.*.expected_quantity' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',

            // Productos que entrega el proceso.
            'outputs' => 'required_without:product_id|array|min:1',
            'outputs.*.product_id' => ['required', $this->companyProductRule()],
            'outputs.*.unit_id' => 'required|exists:units,id',
            'outputs.*.expected_quantity' => 'required|numeric|min:0',
            'outputs.*.is_primary' => 'nullable|boolean',
            'outputs.*.notes' => 'nullable|string',

            // Formato viejo (un solo producto objetivo). Se acepta mientras la
            // app se actualiza: FormulaService lo guarda como salida principal.
            'product_id' => ['nullable', $this->companyProductRule()],
            'expected_output_quantity' => 'nullable|numeric|min:0',
        ], [
            'outputs.required_without' => 'Agrega al menos un producto resultante.',
            'outputs.min' => 'Agrega al menos un producto resultante.',
            'items.required' => 'Agrega al menos un ingrediente.',
            'items.min' => 'Agrega al menos un ingrediente.',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        $this->pruneEmptyRows($request, 'items', 'product_id');
        $this->pruneEmptyRows($request, 'outputs', 'product_id');

        return $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',

            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => ['required', $this->companyProductRule()],
            'items.*.unit_id' => 'required|exists:units,id',
            'items.*.expected_quantity' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',

            'outputs' => 'sometimes|array|min:1',
            'outputs.*.product_id' => ['required', $this->companyProductRule()],
            'outputs.*.unit_id' => 'required|exists:units,id',
            'outputs.*.expected_quantity' => 'required|numeric|min:0',
            'outputs.*.is_primary' => 'nullable|boolean',
            'outputs.*.notes' => 'nullable|string',

            // Formato viejo, ver validateStoreData.
            'product_id' => ['nullable', $this->companyProductRule()],
            'expected_output_quantity' => 'nullable|numeric|min:0',
        ], [
            'outputs.min' => 'Agrega al menos un producto resultante.',
            'items.min' => 'Agrega al menos un ingrediente.',
        ]);
    }

    protected function process($callback, array $data, $method): Model
    {
        $company = CurrentCompany::get();

        if ($method === 'create') {
            $data['company_id'] = $company?->id;
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();
            $formula = $this->formulaService->create($data);
        } else {
            $id = request()->route('id') ?? request()->route('formula');
            $formula = $this->scopedQuery()->findOrFail((int) $id);
            $formula = $this->formulaService->update($formula, $data);
        }

        return $formula->load($this->getShowRelations());
    }

    protected function canDelete(Model $model): array
    {
        return $this->formulaService->canDelete($model);
    }

    public function clone(Request $request, int $id)
    {
        if (!CurrentWorker::hasPermission('formulas_create')) {
            return $this->forbiddenResponse('clonar fórmulas');
        }
        // La fórmula de origen tiene que ser de la empresa (antes se clonaba
        // la de cualquier empresa por id y la copia quedaba en esa empresa).
        $formula = $this->scopedQuery()->with(['items', 'outputs'])->find($id);
        if (!$formula) {
            return response()->json(['message' => 'Recurso no encontrado'], 404);
        }
        $newName = $request->input('name');
        $clone = $this->formulaService->clone($formula, $newName);
        return response()->json([
            'message' => 'Fórmula clonada exitosamente',
            'data' => new FormulaResource($clone->load($this->getShowRelations()), ['editing' => true]),
        ], 201);
    }
}
