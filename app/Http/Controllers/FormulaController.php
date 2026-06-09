<?php

namespace App\Http\Controllers;

use App\Http\Resources\FormulaResource;
use App\Models\Operations\Formula;
use App\Services\FormulaService;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FormulaController extends CrudController
{
    protected string $resource = FormulaResource::class;
    protected string $model = Formula::class;
    protected ?string $permissionPrefix = 'formulas';
    protected string $dateColumn = 'created_at';

    public function __construct(private readonly FormulaService $formulaService) {}

    protected function indexRelations(): array
    {
        return ['product.mainImage', 'items.product', 'items.unit'];
    }

    protected function getShowRelations(): array
    {
        return ['product', 'items.product', 'items.unit', 'createdBy', 'updatedBy'];
    }

    protected function handleQuery($query, array $params)
    {
        if (isset($params['product_id'])) {
            $query->where('product_id', $params['product_id']);
        }
        if (isset($params['is_active'])) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('product', fn($qq) => $qq->where('name', 'like', "%{$search}%"));
            });
        }
    }

    protected function applyBasicFilters($query, array $params)
    {
        parent::applyBasicFilters($query, $params);
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|exists:products,id',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.unit_id' => 'required_with:items|exists:units,id',
            'items.*.expected_quantity' => 'required_with:items|numeric|min:0',
            'items.*.expected_output_quantity' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.unit_id' => 'required_with:items|exists:units,id',
            'items.*.expected_quantity' => 'required_with:items|numeric|min:0',
            'items.*.expected_output_quantity' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
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
            $formula = Formula::findOrFail((int) $id);
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
        $formula = Formula::findOrFail($id);
        $newName = $request->input('name');
        $clone = $this->formulaService->clone($formula, $newName);
        return response()->json([
            'message' => 'Fórmula clonada exitosamente',
            'data' => new FormulaResource($clone->load($this->getShowRelations()), ['editing' => true]),
        ], 201);
    }
}
