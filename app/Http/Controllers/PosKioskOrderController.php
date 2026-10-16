<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Services\KioskOrderService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * "Pedidos del kiosko" en el punto de venta (permiso sales_pos): los que
 * esperan pago en la sucursal y "Cancelar pedido" cuando el cliente se fue
 * sin pagar. Para cobrar uno, la app manda kiosk_order_id en pos/sales.
 */
class PosKioskOrderController extends Controller
{
    public function __construct(private readonly KioskOrderService $orders) {}

    /** GET pos/kiosk-orders — pendientes de la sucursal, del más viejo al más nuevo. */
    public function index(): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        $orders = $this->orders->pendingAt((int) CurrentCompany::id(), (int) CurrentLocation::id());

        return response()->json([
            'data' => $orders->map(fn(SalesOrder $order) => KioskOrderService::present($order))->values(),
        ]);
    }

    /**
     * POST pos/kiosk-orders/{id}/cancel — cancela un pedido pendiente de la
     * sucursal y libera lo apartado. Si ya se cobró o se canceló: 422
     * kiosk_order_unavailable.
     */
    public function cancel(int $id): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        $order = $this->orders->cancelAtPos($id, (int) CurrentCompany::id(), (int) CurrentLocation::id(), (int) Auth::id());

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    private function denied(): ?JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_pos')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        if (!CurrentCompany::id() || !CurrentLocation::id()) {
            return response()->json(['message' => 'Selecciona una sucursal para continuar.'], 403);
        }

        return null;
    }
}
