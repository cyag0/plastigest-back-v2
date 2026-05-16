<?php

namespace App\Http\Controllers;

use App\Models\CashClosing;
use App\Http\Resources\CashClosingResource;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashClosingController extends CrudController
{
    protected string $resource = CashClosingResource::class;
    protected string $model = CashClosing::class;
    protected ?string $permissionPrefix = 'cash_movements';

    protected function indexRelations(): array
    {
        return ['location', 'user'];
    }

    protected function getShowRelations(): array
    {
        return ['location', 'user'];
    }

    protected function applyBasicFilters($query, array $params): void
    {
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('closing_date', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }
    }

    protected function handleQuery($query, array $params): void
    {
        $company = CurrentCompany::get();
        if ($company) {
            $query->where('company_id', $company->id);
        }

        if (!empty($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }

        if (!empty($params['date_from'])) {
            $query->whereDate('closing_date', '>=', $params['date_from']);
        }

        if (!empty($params['date_to'])) {
            $query->whereDate('closing_date', '<=', $params['date_to']);
        }

        $query->orderBy('closing_date', 'desc');
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'closing_date'    => 'required|date',
            'opening_balance' => 'required|numeric',
            'total_income'    => 'required|numeric',
            'total_expense'   => 'required|numeric',
            'expected_balance'=> 'required|numeric',
            'physical_count'  => 'nullable|numeric',
            'difference'      => 'nullable|numeric',
            'total_cash'      => 'required|numeric',
            'total_card'      => 'required|numeric',
            'total_transfer'  => 'required|numeric',
            'total_other'     => 'required|numeric',
            'movements_count' => 'required|integer',
            'notes'           => 'nullable|string|max:1000',
            'status'          => 'nullable|string|max:20',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'closing_date'    => 'sometimes|date',
            'opening_balance' => 'sometimes|numeric',
            'total_income'    => 'sometimes|numeric',
            'total_expense'   => 'sometimes|numeric',
            'expected_balance'=> 'sometimes|numeric',
            'physical_count'  => 'nullable|numeric',
            'difference'      => 'nullable|numeric',
            'total_cash'      => 'sometimes|numeric',
            'total_card'      => 'sometimes|numeric',
            'total_transfer'  => 'sometimes|numeric',
            'total_other'     => 'sometimes|numeric',
            'movements_count' => 'sometimes|integer',
            'notes'           => 'nullable|string|max:1000',
            'status'          => 'nullable|string|max:20',
        ]);
    }

    protected function processStoreData(array $data, Request $request): array
    {
        $company  = CurrentCompany::get();
        $location = CurrentLocation::get();

        $data['company_id']  = $company?->id;
        $data['location_id'] = $location?->id;
        $data['user_id']     = Auth::id();
        $data['status']      = $data['status'] ?? 'closed';

        return $data;
    }
}
