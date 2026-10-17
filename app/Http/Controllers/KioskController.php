<?php

namespace App\Http\Controllers;

use App\Services\KioskCatalog;
use App\Services\KioskOrderService;
use App\Support\ClientRequest;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Kiosko de autoservicio (permiso sales_kiosk): el cliente arma su pedido en
 * el kiosko y paga en caja.
 *
 * El kiosko solo ve el catálogo de la sucursal (sin costos, clientes ni
 * datos internos) y solo puede mandar pedidos. Los cobra o los cancela el
 * cajero en el punto de venta (PosKioskOrderController y pos/sales con
 * kiosk_order_id); si nadie los cobra, vencen solos (kiosk:expire-orders).
 */
class KioskController extends Controller
{
    public function __construct(
        private readonly KioskCatalog $catalog,
        private readonly KioskOrderService $orders,
    ) {}

    /**
     * GET kiosk/catalog — lo que el kiosko ofrece en la sucursal actual
     * (reglas en KioskCatalog).
     */
    public function catalog(): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        return response()->json([
            'data' => $this->catalog->forLocation((int) CurrentCompany::id(), CurrentLocation::get()),
        ]);
    }

    /**
     * POST kiosk/orders — pedido para pagar en caja, con su mercancía apartada
     * y su número corto. Los precios son los del catálogo (los que mande la
     * app no cuentan). La misma llave (client_request_id) responde 200 con el
     * mismo pedido (o 422 kiosk_order_cancelled si ya se canceló o venció);
     * el alta, 201.
     */
    public function storeOrder(Request $request): JsonResponse
    {
        if ($denied = $this->denied()) {
            return $denied;
        }

        // Tope por renglón (kiosk.max_quantity_per_item): lo que aparta un
        // solo pedido. Más que eso se pide en caja.
        $maxQuantity = KioskOrderService::maxQuantityPerItem();

        $validated = $request->validate([
            'client_request_id' => ClientRequest::rules(true),
            'customer_name' => 'nullable|string|max:60',
            'items' => 'required|array|min:1|max:40',
            'items.*.product_id' => 'required|integer',
            'items.*.package_id' => 'nullable|integer',
            'items.*.quantity' => "required|integer|min:1|max:{$maxQuantity}",
        ], [
            'items.required' => 'Agrega al menos un producto a tu pedido.',
            'items.min' => 'Agrega al menos un producto a tu pedido.',
            'items.max' => 'Tu pedido puede llevar hasta 40 productos distintos.',
            'items.*.quantity.min' => 'La cantidad mínima es 1.',
            'items.*.quantity.max' => "Puedes pedir hasta {$maxQuantity} de cada producto; si necesitas más, pide en caja.",
            'customer_name.max' => 'El nombre puede tener hasta 60 letras.',
        ]);

        $name = preg_replace('/\s+/u', ' ', trim((string) ($validated['customer_name'] ?? '')));

        [$order, $created] = $this->orders->create(
            (int) CurrentCompany::id(),
            (int) CurrentLocation::id(),
            (int) Auth::id(),
            $validated['client_request_id'],
            $name !== null && $name !== '' ? $name : null,
            $validated['items'],
        );

        return response()->json(['data' => KioskOrderService::present($order)], $created ? 201 : 200);
    }

    private function denied(): ?JsonResponse
    {
        if (!CurrentWorker::hasPermission('sales_kiosk')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        if (!CurrentCompany::id() || !CurrentLocation::id()) {
            return response()->json(['message' => 'Selecciona una sucursal para continuar.'], 403);
        }

        return null;
    }
}
