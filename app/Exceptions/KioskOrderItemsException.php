<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Un pedido del kiosko no se registró por algunos de sus renglones: ya no
 * alcanza la mercancía (insufficient_stock) o esa opción ya no se vende
 * (product_unavailable).
 *
 * Responde 422 {message, code, items}. `items` trae cada renglón afectado
 * con lo que queda de esa opción, para que el kiosko ajuste el pedido: en la
 * unidad del producto si es suelto y en paquetes completos si es paquete (0
 * si ya no se vende).
 *
 *   {"message": "Ya no alcanza: Coco entero.", "code": "insufficient_stock",
 *    "items": [{"product_id": 10, "package_id": null, "available": 3}]}
 */
class KioskOrderItemsException extends BusinessRuleException
{
    /**
     * @param array<int, array{product_id: int, package_id: int|null, available: float|int}> $items
     */
    public function __construct(string $message, string $errorCode, private readonly array $items)
    {
        parent::__construct($message, $errorCode);
    }

    /**
     * @return array<int, array{product_id: int, package_id: int|null, available: float|int}>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function render(?Request $request = null): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode(),
            'items' => $this->items,
        ], 422);
    }
}
