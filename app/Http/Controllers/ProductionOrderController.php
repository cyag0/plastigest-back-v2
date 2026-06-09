<?php

namespace App\Http\Controllers;

use App\Enums\ProductionOrderStatus;
use App\Http\Resources\ProductionOrderResource;
use App\Models\Operations\ProductionOrder;
use App\Models\User;
use App\Services\ProductionOrderService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductionOrderController extends CrudController
{
    protected string $resource = ProductionOrderResource::class;
    protected string $model = ProductionOrder::class;
    protected ?string $permissionPrefix = 'production_orders';
    protected string $dateColumn = 'production_date';

    public function __construct(private readonly ProductionOrderService $service) {}

    protected function indexRelations(): array
    {
        return [
            'location',
            'responsible',
            'formula',
            'consumptions',
            'outputs',
        ];
    }

    protected function getShowRelations(): array
    {
        return [
            'location',
            'responsible',
            'formula.items.product',
            'consumptions.product',
            'consumptions.unit',
            'outputs.product',
            'outputs.unit',
            'wastes.product',
            'wastes.unit',
            'createdBy',
            'updatedBy',
        ];
    }

    protected function handleQuery($query, array $params)
    {
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['location_id'])) {
            $query->where('location_id', (int) $params['location_id']);
        }
        if (!empty($params['responsible_user_id'])) {
            $query->where('responsible_user_id', (int) $params['responsible_user_id']);
        }
        if (!empty($params['formula_id'])) {
            $query->where('formula_id', (int) $params['formula_id']);
        }
        if (!empty($params['product_id'])) {
            $productId = (int) $params['product_id'];
            $query->where(function ($q) use ($productId) {
                $q->whereHas('consumptions', fn($qq) => $qq->where('product_id', $productId))
                    ->orWhereHas('outputs', fn($qq) => $qq->where('product_id', $productId));
            });
        }
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('folio', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
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
            'location_id' => 'nullable|exists:locations,id',
            'production_date' => 'required|date',
            'responsible_user_id' => 'required|exists:users,id',
            'formula_id' => 'nullable|exists:formulas,id',
            'notes' => 'nullable|string',
            'consumptions' => 'nullable|array',
            'consumptions.*.product_id' => 'required_with:consumptions|exists:products,id',
            'consumptions.*.unit_id' => 'required_with:consumptions|exists:units,id',
            'consumptions.*.quantity' => 'required_with:consumptions|numeric|min:0.0001',
            'consumptions.*.expected_quantity' => 'nullable|numeric|min:0',
            'consumptions.*.notes' => 'nullable|string',
            'outputs' => 'nullable|array',
            'outputs.*.product_id' => 'required_with:outputs|exists:products,id',
            'outputs.*.unit_id' => 'required_with:outputs|exists:units,id',
            'outputs.*.quantity' => 'required_with:outputs|numeric|min:0.0001',
            'outputs.*.expected_quantity' => 'nullable|numeric|min:0',
            'outputs.*.notes' => 'nullable|string',
            'wastes' => 'nullable|array',
            'wastes.*.product_id' => 'required_with:wastes|exists:products,id',
            'wastes.*.unit_id' => 'required_with:wastes|exists:units,id',
            'wastes.*.quantity' => 'required_with:wastes|numeric|min:0.0001',
            'wastes.*.reason' => 'required_with:wastes|in:coconut_no_water,coconut_no_pulp,damaged_coconut,other',
            'wastes.*.notes' => 'nullable|string',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'production_date' => 'sometimes|date',
            'responsible_user_id' => 'sometimes|exists:users,id',
            'formula_id' => 'nullable|exists:formulas,id',
            'notes' => 'nullable|string',
            'consumptions' => 'nullable|array',
            'consumptions.*.product_id' => 'required_with:consumptions|exists:products,id',
            'consumptions.*.unit_id' => 'required_with:consumptions|exists:units,id',
            'consumptions.*.quantity' => 'required_with:consumptions|numeric|min:0.0001',
            'consumptions.*.expected_quantity' => 'nullable|numeric|min:0',
            'consumptions.*.notes' => 'nullable|string',
            'outputs' => 'nullable|array',
            'outputs.*.product_id' => 'required_with:outputs|exists:products,id',
            'outputs.*.unit_id' => 'required_with:outputs|exists:units,id',
            'outputs.*.quantity' => 'required_with:outputs|numeric|min:0.0001',
            'outputs.*.expected_quantity' => 'nullable|numeric|min:0',
            'outputs.*.notes' => 'nullable|string',
            'wastes' => 'nullable|array',
            'wastes.*.product_id' => 'required_with:wastes|exists:products,id',
            'wastes.*.unit_id' => 'required_with:wastes|exists:units,id',
            'wastes.*.quantity' => 'required_with:wastes|numeric|min:0.0001',
            'wastes.*.reason' => 'required_with:wastes|in:coconut_no_water,coconut_no_pulp,damaged_coconut,other',
            'wastes.*.notes' => 'nullable|string',
        ]);
    }

    protected function process($callback, array $data, $method): Model
    {
        $company = CurrentCompany::get();
        $location = CurrentLocation::get();

        $data['company_id'] = $company?->id;
        if (!$data['location_id'] ?? null) {
            $data['location_id'] = $location?->id;
        }

        if ($method === 'create') {
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();
            $order = $this->service->createDraft($data);
        } else {
            $id = request()->route('id') ?? request()->route('production_order');
            $order = ProductionOrder::findOrFail((int) $id);
            $order = $this->service->updateDraft($order, $data);
        }

        return $order->load($this->getShowRelations());
    }

    /**
     * POST /production-orders/{id}/complete
     */
    public function complete(int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('completar esta producción');
        }

        try {
            $order = ProductionOrder::with($this->getShowRelations())->findOrFail($id);
            $order = $this->service->complete($order);
            return response()->json([
                'success' => true,
                'message' => "Producción {$order->folio} completada y stock actualizado",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo completar', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error al completar producción', 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /production-orders/{id}/cancel
     */
    public function cancel(Request $request, int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('cancelar esta producción');
        }

        try {
            $order = ProductionOrder::with($this->getShowRelations())->findOrFail($id);
            $order = $this->service->cancel($order);
            return response()->json([
                'success' => true,
                'message' => "Producción {$order->folio} cancelada y stock revertido",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error al cancelar producción', 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /production-orders/today-stats
     */
    public function todayStats(Request $request)
    {
        if (!CurrentWorker::hasPermission('production_orders_list')) {
            return $this->forbiddenResponse('consultar KPIs de producción');
        }
        $companyId = CurrentCompany::get()?->id;
        $locationId = $request->input('location_id') ?? CurrentLocation::id();
        $stats = $this->service->getTodayStats($companyId, $locationId);
        return response()->json(['success' => true, 'data' => $stats]);
    }

    /**
     * GET /production-orders/initial-data
     */
    public function getInitialData(Request $request)
    {
        if (!CurrentWorker::hasPermission('production_orders_list')) {
            return $this->forbiddenResponse('consultar datos iniciales');
        }
        $locationId = $request->input('location_id') ?? CurrentLocation::id();
        $data = $this->service->getInitialData($locationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /production-orders/{id}/variance
     */
    public function variance(int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_read')) {
            return $this->forbiddenResponse('ver esta producción');
        }
        $order = ProductionOrder::with($this->getShowRelations())->findOrFail($id);
        $report = $this->service->getVarianceReport($order);
        return response()->json(['success' => true, 'data' => $report]);
    }
}
