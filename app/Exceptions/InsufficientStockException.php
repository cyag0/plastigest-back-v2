<?php

namespace App\Exceptions;

use App\Models\Product;

/**
 * No alcanza la existencia de un producto en una sucursal para sacar la
 * cantidad pedida.
 *
 * La lanza MovementService::decrement(), el paso obligado de toda salida de
 * stock (punto de venta, ventas, pedidos, producción, empaque,
 * transferencias, ajustes y conteos). Responde 422:
 *
 *   {
 *     "message": "Stock insuficiente para \"Coco\". Disponible: 5 Pieza, solicitado: 6 Pieza.",
 *     "code": "insufficient_stock",
 *     "details": {product_id, product_name, location_id, available, requested, unit}
 *   }
 *
 * Las cantidades van en la unidad base del producto (un paquete ya viene
 * multiplicado por su contenido). El mensaje es el mismo que MovementService
 * daba antes como Exception genérica: quien atrapa Exception/Throwable y
 * reenvía getMessage() sigue funcionando igual.
 */
class InsufficientStockException extends BusinessRuleException
{
    public function __construct(
        public readonly int $productId,
        public readonly float $available,
        public readonly float $requested,
        public readonly ?int $locationId = null,
        public readonly ?string $productName = null,
        public readonly ?string $unitName = null,
    ) {
        $name = $productName ?? "producto ID {$productId}";
        $suffix = ($unitName ?? '') !== '' ? " {$unitName}" : '';
        $availableRounded = round($available, 3);
        $requestedRounded = round($requested, 3);

        parent::__construct(
            "Stock insuficiente para \"{$name}\". " .
            "Disponible: {$availableRounded}{$suffix}, solicitado: {$requestedRounded}{$suffix}.",
            'insufficient_stock',
            [
                'product_id' => $productId,
                'product_name' => $productName,
                'location_id' => $locationId,
                'available' => $availableRounded,
                'requested' => $requestedRounded,
                'unit' => $unitName,
            ],
        );
    }

    /**
     * Arma la excepción con el nombre y la unidad base del producto.
     */
    public static function forProduct(int $productId, float $available, float $requested, ?int $locationId = null): self
    {
        $product = Product::with('unit')->find($productId);

        return new self($productId, $available, $requested, $locationId, $product?->name, $product?->unit?->name);
    }
}
