<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Qué se puede vender y a qué precio, igual en el punto de venta, la
 * pantalla de ventas anterior y los pedidos (kiosco).
 *
 * - Precio de catálogo: el del producto o, para un paquete, su precio de
 *   venta o, si no tiene, el unitario × su contenido.
 * - Sin el permiso sales_discount se cobra el precio de catálogo y no hay
 *   descuentos: el precio nunca se toma de la app para quien no puede
 *   cambiarlo. Antes los pedidos aceptaban cualquier precio.
 * - Un producto que no está a la venta (for_sale = false) solo se vende en
 *   sus paquetes con precio: hay piezas base que existen para anclar su
 *   paquete ("PAQ GALLETAS 16 PZA") y no se venden sueltas.
 */
final class SalePricing
{
    public static function catalogPrice(Product $product, ?ProductPackage $package = null): float
    {
        if (!$package) {
            return Money::round($product->sale_price ?? 0);
        }

        if ($package->sale_price !== null && Money::toCents($package->sale_price) > 0) {
            return Money::round($package->sale_price);
        }

        return Money::fromCents(Money::lineCents(
            Money::toMilli($package->quantity_per_package),
            Money::toCents($product->sale_price ?? 0),
        ));
    }

    /**
     * ¿Se vende este renglón? Un producto a la venta (suelto o en paquete), o
     * un paquete con precio de un producto que no se vende suelto.
     */
    public static function isSellable(Product $product, ?ProductPackage $package = null): bool
    {
        if ($product->for_sale) {
            return true;
        }

        return $package !== null && Money::toCents(self::catalogPrice($product, $package)) > 0;
    }

    /**
     * Deja en el producto solo los paquetes que se pueden vender (con
     * activePackages ya cargado) y dice si al producto le queda algo vendible:
     * la pieza (for_sale) o al menos un paquete. Para los catálogos de venta.
     */
    public static function onlySellablePackages(Product $product): bool
    {
        if (!$product->for_sale) {
            $product->setRelation('activePackages', $product->activePackages
                ->filter(fn(ProductPackage $package) => self::isSellable($product, $package))
                ->values());
        }

        return $product->for_sale || $product->activePackages->isNotEmpty();
    }

    /**
     * Agrega `price` (precio de catálogo) a los paquetes ya serializados de un
     * producto, en el mismo orden que su relación activePackages.
     *
     * @param array<int, array<string, mixed>>|iterable $serialized
     * @return array<int, array<string, mixed>>
     */
    public static function packagesWithPrice(Product $product, iterable $serialized): array
    {
        $packages = $product->activePackages->keyBy('id');

        return collect($serialized)->map(function ($row) use ($product, $packages) {
            $row = (array) $row;
            $package = $packages->get($row['id'] ?? null);

            return $package ? $row + ['price' => self::catalogPrice($product, $package)] : $row;
        })->values()->all();
    }

    /**
     * Valida los renglones de una venta o pedido y les pone su precio
     * definitivo. Cada renglón trae product_id y, opcionales, package_id,
     * unit_id, unit_price y discount; regresa los mismos renglones con
     * unit_price y discount definitivos (unit_id: el del renglón o la unidad
     * base del producto).
     *
     * @param array<int, array<string, mixed>> $details
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function priceLines(array $details, bool $canDiscount, ?int $companyId = null): array
    {
        $companyId ??= CurrentCompany::id();

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', array_column($details, 'product_id'))
            ->get()
            ->keyBy('id');
        /** @var Collection<int, ProductPackage> $packages */
        $packages = ProductPackage::query()
            ->whereIn('id', array_filter(array_column($details, 'package_id')))
            ->get()
            ->keyBy('id');

        foreach ($details as $i => $detail) {
            $product = $products->get($detail['product_id']);
            if (!$product || ($companyId && (int) $product->company_id !== (int) $companyId)) {
                throw ValidationException::withMessages(["details.{$i}.product_id" => ['Producto inválido.']]);
            }

            $package = null;
            if (!empty($detail['package_id'])) {
                $package = $packages->get($detail['package_id']);
                if (!$package || (int) $package->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages(["details.{$i}.package_id" => ['El paquete no corresponde al producto.']]);
                }
            }

            if (!self::isSellable($product, $package)) {
                throw ValidationException::withMessages([
                    "details.{$i}.product_id" => [$package
                        ? "\"{$package->package_name}\" no tiene precio de venta."
                        : "\"{$product->name}\" no se vende suelto: véndelo en uno de sus paquetes."],
                ]);
            }

            $catalog = self::catalogPrice($product, $package);
            $price = isset($detail['unit_price']) && $detail['unit_price'] !== '' ? Money::round($detail['unit_price']) : $catalog;
            $discount = Money::round($detail['discount'] ?? 0);

            if (!$canDiscount && (Money::toCents($price) !== Money::toCents($catalog) || Money::toCents($discount) > 0)) {
                throw ValidationException::withMessages([
                    "details.{$i}.unit_price" => ['No tienes permiso para cambiar el precio o aplicar descuentos.'],
                ]);
            }

            $details[$i] = array_merge($detail, [
                'product_id' => $product->id,
                'package_id' => $package?->id,
                'unit_id' => $detail['unit_id'] ?? $product->unit_id,
                'unit_price' => $price,
                'discount' => $discount,
            ]);
        }

        return $details;
    }
}
