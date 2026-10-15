<?php

namespace App\Support;

/**
 * Dinero en centavos enteros para las ventas (punto de venta, pantalla de
 * ventas anterior y pedidos), con el mismo algoritmo que la app
 * (utils/money.ts):
 *
 *   qty_milli      = round(cantidad × 1000)
 *   price_cents    = round(precio × 100)
 *   line_cents     = round_half_up(qty_milli × price_cents / 1000)   (enteros)
 *   discount_cents = round(descuento × 100)
 *   renglón        = line_cents − discount_cents
 *   total          = suma de los renglones
 *
 * Antes el servidor redondeaba cada renglón con round() sobre flotantes y la
 * app sumaba sin redondear: cobros de producto pesado (2.489 kg × $25 = 62.23)
 * se rechazaban por un centavo, y el resultado cambiaba entre PHP 8.2 y 8.4
 * (8.4 quitó el pre-redondeo de round()). Con enteros da lo mismo en cualquier
 * versión.
 */
final class Money
{
    /** Monto → centavos enteros ($62.23 → 6223). */
    public static function toCents(mixed $amount): int
    {
        return self::scaled($amount, 100);
    }

    /** Cantidad → milésimas enteras (2.489 kg → 2489). */
    public static function toMilli(mixed $quantity): int
    {
        return self::scaled($quantity, 1000);
    }

    /** Centavos → monto (6223 → 62.23). */
    public static function fromCents(int $cents): float
    {
        return $cents / 100;
    }

    /** Monto redondeado a centavos con la misma regla (19.999 → 20.0). */
    public static function round(mixed $amount): float
    {
        return self::fromCents(self::toCents($amount));
    }

    /** Cantidad redondeada a milésimas con la misma regla. */
    public static function roundQuantity(mixed $quantity): float
    {
        return self::toMilli($quantity) / 1000;
    }

    /**
     * round_half_up(qtyMilli × priceCents / 1000) solo con enteros: mitades
     * lejos de cero, como la app. 2489 milésimas × 2500 centavos = 6223.
     */
    public static function lineCents(int $qtyMilli, int $priceCents): int
    {
        $product = abs($qtyMilli) * abs($priceCents);
        $cents = intdiv($product + 500, 1000);

        return ($qtyMilli < 0) !== ($priceCents < 0) ? -$cents : $cents;
    }

    /**
     * Importes de un renglón en centavos: subtotal (cantidad × precio), el
     * descuento y el total (subtotal − descuento).
     *
     * @return array{subtotal: int, discount: int, total: int}
     */
    public static function line(mixed $quantity, mixed $unitPrice, mixed $discount = 0): array
    {
        $subtotal = self::lineCents(self::toMilli($quantity), self::toCents($unitPrice));
        $discountCents = self::toCents($discount ?? 0);

        return [
            'subtotal' => $subtotal,
            'discount' => $discountCents,
            'total' => $subtotal - $discountCents,
        ];
    }

    /**
     * value × scale al entero más cercano. El producto puede quedar a un pelo
     * del entero (2.489 × 1000 = 2488.9999999999995, 1.005 × 100 =
     * 100.49999999999999): se toma con 15 cifras significativas, como la app
     * (toPrecision(15)), para redondear el decimal que se escribió y no su
     * representación binaria.
     */
    private static function scaled(mixed $value, int $scale): int
    {
        $number = (float) ($value ?? 0);

        return (int) \round((float) sprintf('%.15g', $number * $scale));
    }
}
