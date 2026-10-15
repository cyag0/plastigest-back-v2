<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Qué se puede vender y a qué precio, igual en el punto de venta, la
 * pantalla de ventas anterior y los pedidos (kiosco).
 *
 * - Precio de catálogo: el del producto o, para un paquete, su precio de
 *   venta o, si no tiene, el unitario × su contenido.
 * - Un renglón en otra unidad del mismo tipo (gramos de un producto que se
 *   vende por kilo, docenas de uno por pieza) se guarda en la unidad del
 *   producto: la cantidad convertida (a milésimas) y el precio por unidad del
 *   producto. Así el precio se compara con el de catálogo sin redondear un
 *   precio por gramo ($25/kg no se vuelve $0.03/g) y el total, el stock y el
 *   ticket dicen lo mismo.
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
     * definitivo. Cada renglón trae product_id, la cantidad (quantity, o
     * requested_quantity en los pedidos) y, opcionales, package_id, unit_id,
     * unit_price y discount; regresa los mismos renglones con unit_price y
     * discount definitivos. Un renglón sin paquete en otra unidad sale en la
     * unidad del producto: cantidad × factor de su unidad ÷ factor de la del
     * producto (a milésimas) y precio ÷ ese mismo cociente (unit_id: el del
     * renglón si es paquete o ya es la unidad del producto; si no, la del
     * producto).
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
        /** @var Collection<int, Unit> $units */
        $units = Unit::query()
            ->whereIn('id', array_merge(
                $products->pluck('unit_id')->filter()->all(),
                array_filter(array_column($details, 'unit_id')),
            ))
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

            // Renglón en otra unidad del mismo tipo: se pasa a la unidad del
            // producto (500 g de un producto por kilo son 0.5 kg; 2 docenas de
            // uno por pieza, 24 piezas) y el precio, a precio por unidad del
            // producto. Antes se comparaba un precio por gramo redondeado al
            // centavo ($25/kg → $0.03/g) y 500 g se cobraban $15.00 en lugar
            // de $12.50. Los paquetes tienen su propio precio y contenido.
            $quantityKey = array_key_exists('quantity', $detail) ? 'quantity' : 'requested_quantity';
            $ratio = null;
            $unitId = !$package && !empty($detail['unit_id']) ? (int) $detail['unit_id'] : null;
            if ($unitId && $unitId !== (int) $product->unit_id) {
                $productUnit = $units->get((int) $product->unit_id);
                $ratio = self::unitRatio($units->get($unitId), $productUnit);
                if ($ratio === null) {
                    throw ValidationException::withMessages([
                        "details.{$i}.unit_id" => ["La unidad no corresponde a \"{$product->name}\"."],
                    ]);
                }

                $quantity = Money::roundQuantity((float) ($detail[$quantityKey] ?? 0) * $ratio);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "details.{$i}.{$quantityKey}" => ["La cantidad de \"{$product->name}\" es menor que 0.001 {$productUnit->abbreviation}, lo mínimo que se registra."],
                    ]);
                }

                $detail[$quantityKey] = $quantity;
                $detail['unit_id'] = (int) $product->unit_id;
            }

            $catalog = self::catalogPrice($product, $package);
            $sent = isset($detail['unit_price']) && $detail['unit_price'] !== '' ? $detail['unit_price'] : null;
            // Precio por unidad del producto, en centavos enteros (C2). El de
            // otra unidad se convierte antes de redondear: $0.025 el gramo son
            // $25.00 el kilo; $0.03 el gramo, $30.00.
            $price = match (true) {
                $sent === null => $catalog,
                $ratio !== null => Money::round((float) $sent / $ratio),
                default => Money::round($sent),
            };
            $discount = Money::round($detail['discount'] ?? 0);

            $isCatalogPrice = Money::toCents($price) === Money::toCents($catalog);

            if (!$canDiscount && (!$isCatalogPrice || Money::toCents($discount) > 0)) {
                throw ValidationException::withMessages([
                    "details.{$i}.unit_price" => ['No tienes permiso para cambiar el precio o aplicar descuentos.'],
                ]);
            }

            // El descuento no pasa del importe del renglón. Antes se aceptaba:
            // los pagos se comparaban contra un total y SaleService guardaba
            // otro (recorta el descuento), y quedaba un saldo pendiente que no
            // existía.
            if (Money::toCents($discount) > 0) {
                if (Money::toCents($discount) > Money::line($detail[$quantityKey] ?? 0, $price)['subtotal']) {
                    throw ValidationException::withMessages([
                        "details.{$i}.discount" => ['El descuento no puede ser mayor que el importe del renglón.'],
                    ]);
                }
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

    /**
     * Unidades del producto en una unidad del renglón, con los factores del
     * inventario (MovementService): gramo de un producto por kilo, 0.001;
     * docena de uno por pieza, 12; pieza de uno por docena, 1/12. null si la
     * unidad es de otro tipo o su factor no sirve.
     */
    private static function unitRatio(?Unit $unit, ?Unit $productUnit): ?float
    {
        if (!$unit || !$productUnit || (string) $unit->unit_type !== (string) $productUnit->unit_type) {
            return null;
        }

        $from = (float) ($unit->factor_to_base ?? 1);
        $to = (float) ($productUnit->factor_to_base ?? 1);

        return $from > 0 && $to > 0 ? $from / $to : null;
    }
}
