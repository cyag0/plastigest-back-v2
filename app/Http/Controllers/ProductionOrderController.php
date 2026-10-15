<?php

namespace App\Http\Controllers;

use App\Enums\ProductionOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\ProductionOrderResource;
use App\Models\Operations\ProductionOrder;
use App\Services\ProductionOrderService;
use App\Services\ProductionPackagingService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProductionOrderController extends CrudController
{
    protected string $resource = ProductionOrderResource::class;
    protected string $model = ProductionOrder::class;
    protected ?string $permissionPrefix = 'production_orders';
    protected ?string $locationColumn = 'location_id';
    protected string $dateColumn = 'production_date';

    public function __construct(
        private readonly ProductionOrderService $service,
        private readonly ProductionPackagingService $packagingService,
    ) {}

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
            'packagings.product',
            'packagings.unit',
            'packagings.sourceProduct',
            'packagings.sourceUnit',
            'packagings.materials.product',
            'packagings.materials.unit',
            'createdBy',
            'updatedBy',
        ];
    }

    /**
     * Solo se pueden eliminar órdenes en borrador o canceladas. Una orden
     * completada/empacada ya movió existencias; borrarla dejaría el stock
     * descuadrado (CrudController::destroy no traía este resguardo).
     */
    protected function canDelete(\Illuminate\Database\Eloquent\Model $model): array
    {
        $status = $model->status instanceof ProductionOrderStatus
            ? $model->status
            : ProductionOrderStatus::tryFrom((string) $model->status);

        if (!in_array($status, [ProductionOrderStatus::DRAFT, ProductionOrderStatus::CANCELLED], true)) {
            return [
                'can_delete' => false,
                'message' => 'Solo se pueden eliminar órdenes en borrador o canceladas.',
            ];
        }

        return ['can_delete' => true, 'message' => ''];
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
        return $request->validate($this->rules([
            //'location_id' => 'nullable|exists:locations,id',
            'production_date' => 'required|date',
            'responsible_user_id' => 'required|exists:users,id',
        ]));
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate($this->rules([
            //'location_id' => 'sometimes|exists:locations,id',
            'production_date' => 'sometimes|date',
            'responsible_user_id' => 'sometimes|exists:users,id',
        ]));
    }

    /**
     * Reglas comunes de alta y edición.
     *
     * Productos y fórmula tienen que ser de la empresa: una orden con un
     * producto ajeno movía el stock (y el kárdex) de otra empresa desde esta
     * sucursal.
     */
    private function rules(array $rules): array
    {
        $companyId = CurrentCompany::id();
        $product = [Rule::exists('products', 'id')->where('company_id', $companyId)];

        return $rules + [
            'formula_id' => [
                'nullable',
                Rule::exists('formulas', 'id')->where(fn($q) => $q->where('company_id', $companyId)->orWhereNull('company_id')),
            ],
            'notes' => 'nullable|string',
            'consumptions' => 'nullable|array',
            'consumptions.*.product_id' => ['required_with:consumptions', ...$product],
            'consumptions.*.unit_id' => 'required_with:consumptions|exists:units,id',
            'consumptions.*.quantity' => 'required_with:consumptions|numeric|min:0.0001',
            'consumptions.*.expected_quantity' => 'nullable|numeric|min:0',
            'consumptions.*.notes' => 'nullable|string',
            'outputs' => 'nullable|array',
            'outputs.*.product_id' => ['required_with:outputs', ...$product],
            'outputs.*.unit_id' => 'required_with:outputs|exists:units,id',
            'outputs.*.quantity' => 'required_with:outputs|numeric|min:0.0001',
            'outputs.*.expected_quantity' => 'nullable|numeric|min:0',
            'outputs.*.notes' => 'nullable|string',
            'wastes' => 'nullable|array',
            'wastes.*.product_id' => ['required_with:wastes', ...$product],
            'wastes.*.unit_id' => 'required_with:wastes|exists:units,id',
            'wastes.*.quantity' => 'required_with:wastes|numeric|min:0.0001',
            'wastes.*.reason' => 'required_with:wastes|in:damage,quality,expired,process_loss,spill,other',
            'wastes.*.notes' => 'nullable|string',
        ];
    }

    protected function process($callback, array $data, $method): Model
    {
        $company = CurrentCompany::get();
        $location = CurrentLocation::get();

        $data['company_id'] = $company?->id;
        if (!isset($data['location_id']) || $data['location_id'] === null) {
            $data['location_id'] = $location?->id;
        }

        if ($method === 'create') {
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();

            $save = fn() => $this->service->createDraft($data);
        } else {
            $id = request()->route('id') ?? request()->route('production_order');
            $order = $this->scopedQuery()->findOrFail((int) $id);
            $save = fn() => $this->service->updateDraft($order, $data);
        }

        // Si se pide afectar stock, guardamos y completamos en una sola
        // transacción: si el stock es insuficiente, se revierte TODO y no
        // queda una orden en borrador huérfana. Aplica también al editar un
        // borrador; antes la edición ignoraba affect_stock y la orden se
        // quedaba en borrador aunque el botón dijera "Registrar Producción".
        try {
            if (!request()->boolean('affect_stock')) {
                return $save()->load($this->getShowRelations());
            }

            $order = DB::transaction(fn() => $this->service->complete($save()));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // El motivo (p. ej. "Stock insuficiente para …") sale como 422 en
            // message, y también en errors.stock, donde lo leía la app; un
            // error interno ya no llega con su texto (SQL) al usuario.
            $response = $this->failure($e, 'No se pudo registrar la producción.');
            if ($response->getStatusCode() === 422) {
                $payload = $response->getData(true);
                $payload['errors'] = ['stock' => [$payload['message']]];
                $response->setData($payload);
            }

            throw new HttpResponseException($response);
        }

        return $order->load($this->getShowRelations());
    }

    // ─── Acciones sobre una orden ────────────────────────────────────────────

    /**
     * Orden de la empresa actual y, salvo para quien ve todas las sucursales,
     * de la sucursal actual (scopedQuery). Las acciones de abajo la buscaban
     * solo por id: desde una sucursal se podía completar, cancelar o empacar
     * la producción de otra y mover su stock.
     */
    private function findOrder(int $id, array $with = []): ?ProductionOrder
    {
        return $this->scopedQuery()->with($with)->find($id);
    }

    private function orderNotFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Producción no encontrada'], 404);
    }

    /**
     * Respuesta de una acción de producción que no se pudo hacer.
     *
     * El motivo (stock insuficiente, una orden que ya no está en borrador,
     * una receta de empaque incompleta...) va en `message`, que es lo que
     * muestra la app, con un `code` estable; también en `error`, donde lo
     * buscan las pantallas de empaque. Un error interno (SQL, PHP) no sale:
     * se registra y se responde el mensaje genérico, con 500.
     */
    private function failure(Throwable $e, string $fallback): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json(['success' => false, 'message' => $fallback, 'errors' => $e->errors()], 422);
        }
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }
        if ($e instanceof HttpExceptionInterface) {
            return response()->json(['success' => false, 'message' => $e->getMessage() ?: $fallback], $e->getStatusCode());
        }

        // Un "no encontrado" dentro del servicio es un dato que ya no existe,
        // no un motivo para el usuario: su texto nombra la clase del modelo.
        $reason = $e instanceof ModelNotFoundException ? null : $this->errorDetail($e);
        if ($reason === null) {
            if ($e instanceof ModelNotFoundException) {
                Log::error($fallback, ['exception' => get_class($e), 'message' => $e->getMessage()]);
            }

            return response()->json(['success' => false, 'message' => $fallback], 500);
        }

        $payload = [
            'success' => false,
            'message' => $reason,
            'code' => $e instanceof BusinessRuleException ? $e->errorCode() : 'production_error',
            'error' => $reason,
        ];
        if ($e instanceof BusinessRuleException && $e->details() !== []) {
            $payload['details'] = $e->details();
        }

        return response()->json($payload, 422);
    }

    /**
     * POST /production-orders/{id}/complete
     */
    public function complete(int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('completar esta producción');
        }

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $order = $this->service->complete($order);
            return response()->json([
                'success' => true,
                'message' => "Producción {$order->folio} completada y stock actualizado",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'No se pudo completar la producción.');
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

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $order = $this->service->cancel($order);
            return response()->json([
                'success' => true,
                'message' => "Producción {$order->folio} cancelada y stock revertido",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'No se pudo cancelar la producción.');
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

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        $report = $this->service->getVarianceReport($order);
        return response()->json(['success' => true, 'data' => $report]);
    }

    /**
     * GET /production-orders/{id}/packaging
     *
     * Plan de empaque: qué se puede empacar de cada salida de la orden, cuánto
     * granel queda y qué material haría falta.
     */
    public function packagingPlan(int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_read')) {
            return $this->forbiddenResponse('ver el empaque de esta producción');
        }

        $order = $this->findOrder($id, ['outputs.product', 'outputs.unit']);
        if (!$order) {
            return $this->orderNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->packagingService->getPlan($order),
        ]);
    }

    /**
     * POST /production-orders/{id}/packaging
     *
     * Registra empaques. Sólo se permite cuando la producción ya se completó:
     * antes de eso el granel no existe en inventario.
     */
    public function pack(Request $request, int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('empacar esta producción');
        }

        // Terminado, granel y material tienen que ser productos de la empresa.
        $product = Rule::exists('products', 'id')->where('company_id', CurrentCompany::id());

        $data = $request->validate([
            'packagings' => 'required|array|min:1',
            'packagings.*.product_id' => ['required', $product],
            'packagings.*.quantity' => 'required|numeric|min:0.0001',
            'packagings.*.unit_id' => 'nullable|exists:units,id',
            // Permiten sobrescribir la receta del producto en un empaque puntual.
            'packagings.*.source_product_id' => ['nullable', $product],
            'packagings.*.source_unit_id' => 'nullable|exists:units,id',
            'packagings.*.source_quantity_per_unit' => 'nullable|numeric|min:0',
            'packagings.*.materials' => 'nullable|array',
            'packagings.*.materials.*.product_id' => ['required_with:packagings.*.materials', $product],
            'packagings.*.materials.*.unit_id' => 'required_with:packagings.*.materials|exists:units,id',
            'packagings.*.materials.*.quantity' => 'required_with:packagings.*.materials|numeric|min:0',
            'packagings.*.notes' => 'nullable|string',
        ], [
            'packagings.required' => 'Indica al menos un producto a empacar.',
        ]);

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $order = $this->packagingService->pack($order, $data['packagings']);

            return response()->json([
                'success' => true,
                'message' => "Empaque registrado en la producción {$order->folio}",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'No se pudo registrar el empaque.');
        }
    }

    /**
     * DELETE /production-orders/{id}/packaging/{packagingId}
     */
    public function revertPackaging(int $id, int $packagingId)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('revertir el empaque de esta producción');
        }

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        // El empaque se busca dentro de la orden: no se revierte el de otra.
        $packaging = $order->packagings()->with('materials')->find($packagingId);
        if (!$packaging) {
            return response()->json(['success' => false, 'message' => 'Empaque no encontrado'], 404);
        }

        try {
            $order = $this->packagingService->revert($order, $packaging);

            return response()->json([
                'success' => true,
                'message' => 'Empaque revertido y stock devuelto',
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'No se pudo revertir el empaque.');
        }
    }

    /**
     * POST /production-orders/{id}/packaging/finish
     *
     * Cierra el empaque: la orden queda como empacada y ya no admite más.
     */
    public function finishPackaging(int $id)
    {
        if (!CurrentWorker::hasPermission('production_orders_update')) {
            return $this->forbiddenResponse('cerrar el empaque de esta producción');
        }

        $order = $this->findOrder($id);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $order = $this->packagingService->finish($order);

            return response()->json([
                'success' => true,
                'message' => "Empaque de la producción {$order->folio} cerrado",
                'data' => new ProductionOrderResource($order->load($this->getShowRelations()), ['editing' => true]),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'No se pudo cerrar el empaque.');
        }
    }
}
