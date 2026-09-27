<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashRegisterResource;
use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de cajas de la compañía. Cada caja pertenece a una sucursal.
 */
class CashRegisterController extends CrudController
{
    protected string $resource = CashRegisterResource::class;
    protected string $model = CashRegister::class;
    protected ?string $permissionPrefix = 'cash_registers';

    /**
     * El POS necesita listar las cajas de su sucursal para abrir turno, así
     * que quien puede vender en el POS también puede listarlas.
     */
    protected function canIndex(): bool
    {
        return parent::canIndex() || CurrentWorker::hasPermission('sales_pos');
    }

    protected function indexRelations(): array
    {
        return ['location', 'openSession.user'];
    }

    protected function getShowRelations(): array
    {
        return ['location', 'openSession.user'];
    }

    protected function handleQuery($query, array $params)
    {
        // Siempre dentro de la compañía actual.
        $query->where('company_id', CurrentCompany::id());

        // Un usuario básico solo ve las cajas de su sucursal.
        if (!CurrentWorker::canAccessAllLocations()) {
            $query->where('location_id', CurrentLocation::id());
        } elseif (!empty($params['location_id'])) {
            $query->where('location_id', (int) $params['location_id']);
        }

        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        $query->orderBy('name');
    }

    protected function applyBasicFilters($query, array $params)
    {
        // La búsqueda y el alcance por compañía se resuelven en handleQuery.
    }

    /** show/update/destroy solo sobre cajas de la compañía actual. */
    protected function findModel($id): ?Model
    {
        return CashRegister::where('company_id', CurrentCompany::id())->find($id);
    }

    protected function findModelForShow($id): ?Model
    {
        return CashRegister::with($this->getShowRelations())
            ->where('company_id', CurrentCompany::id())
            ->find($id);
    }

    private function rules(?CashRegister $register = null): array
    {
        $companyId = CurrentCompany::id();

        return [
            'name' => [$register ? 'sometimes' : 'required', 'string', 'max:100'],
            'code' => [
                $register ? 'sometimes' : 'required', 'string', 'max:30',
                Rule::unique('cash_registers', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($register?->id),
            ],
            'location_id' => [
                $register ? 'sometimes' : 'required',
                Rule::exists('locations', 'id')->where('company_id', $companyId),
            ],
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function validateStoreData(Request $request): array
    {
        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
        }

        return $request->validate($this->rules());
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($request->has('is_active')) {
            $request->merge(['is_active' => $request->boolean('is_active')]);
        }

        return $request->validate($this->rules($model));
    }

    protected function processStoreData(array $validatedData, Request $request): array
    {
        $validatedData['company_id'] = CurrentCompany::id();

        return $validatedData;
    }

    protected function canDelete(Model $model): array
    {
        // Con turnos registrados se conserva para el historial; se desactiva.
        if ($model->sessions()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'La caja ya tiene turnos registrados; desactívala en lugar de eliminarla.',
            ];
        }

        return ['can_delete' => true, 'message' => ''];
    }
}
