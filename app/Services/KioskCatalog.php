<?php

namespace App\Services;

use App\Constants\Files;
use App\Models\Admin\Location;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Support\Money;
use App\Support\SalePricing;
use App\Utils\AppUploadUtil;
use Illuminate\Support\Collection;

/**
 * Qué ofrece el kiosko de autoservicio en una sucursal y cuánto alcanza.
 *
 * Las mismas reglas arman el catálogo (GET kiosk/catalog) y validan cada
 * renglón de un pedido (POST kiosk/orders): el kiosko nunca acepta algo que
 * su catálogo no muestra.
 *
 * - Producto de la empresa, activo en la sucursal (product_location.active,
 *   como el punto de venta), con unidad y de un tipo de inventario que se
 *   vende (inventory_types.is_sellable: empaque, granel e insumos no).
 * - Suelto, solo si está a la venta y su precio de catálogo es mayor a $0.
 * - Paquetes activos que se venden (SalePricing::isSellable), con precio
 *   mayor a $0 y contenido mayor a 0.
 * - Sin nada de eso el producto no sale: nunca se vende nada a $0.
 * - Disponible = existencia − apartado (no menos de 0), en la unidad del
 *   producto; de un paquete, los paquetes completos que alcanzan.
 *
 * El catálogo no lleva costos, clientes ni datos internos de la sucursal.
 */
class KioskCatalog
{
    /**
     * Productos que el kiosko ofrece en la sucursal, por nombre, con
     * current_stock y reserved_stock de esa sucursal y su unidad y paquetes
     * activos cargados.
     *
     * @param int[]|null $productIds Solo estos (para validar un pedido).
     * @param array<int|string, mixed> $with Relaciones extra (p. ej. mainImage).
     * @return Collection<int, Product>
     */
    public function products(int $companyId, int $locationId, ?array $productIds = null, array $with = []): Collection
    {
        return Product::query()
            ->where('products.company_id', $companyId)
            ->whereNotNull('products.unit_id')
            ->join('product_location as pl', fn($join) => $join->on('pl.product_id', '=', 'products.id')
                ->where('pl.location_id', $locationId)
                ->where('pl.active', true))
            // Sin tipo (o con un tipo que ya no existe) se vende, como antes de los tipos.
            ->leftJoin('inventory_types as it', 'it.id', '=', 'products.inventory_type_id')
            ->where(fn($query) => $query->whereNull('it.id')->orWhere('it.is_sellable', true))
            ->when($productIds !== null, fn($query) => $query->whereIn('products.id', $productIds))
            ->with(array_merge(['unit:id,name,abbreviation', 'activePackages'], $with))
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get([
                'products.id', 'products.name', 'products.category_id', 'products.unit_id',
                'products.sale_price', 'products.for_sale', 'pl.current_stock', 'pl.reserved_stock',
            ])
            ->filter(fn(Product $product) => self::soldLoose($product) || self::packages($product)->isNotEmpty())
            ->values();
    }

    /** ¿Se vende suelto en el kiosko? A la venta y con precio de catálogo mayor a $0. */
    public static function soldLoose(Product $product): bool
    {
        return (bool) $product->for_sale && Money::toCents(SalePricing::catalogPrice($product)) > 0;
    }

    /**
     * Paquetes del producto que vende el kiosko (de activePackages, en su orden).
     *
     * @return Collection<int, ProductPackage>
     */
    public static function packages(Product $product): Collection
    {
        return $product->activePackages
            ->filter(fn(ProductPackage $package) => Money::toMilli($package->quantity_per_package) > 0
                && SalePricing::isSellable($product, $package)
                && Money::toCents(SalePricing::catalogPrice($product, $package)) > 0)
            ->values();
    }

    /** Existencia − apartado en la sucursal (no menos de 0), en la unidad del producto. */
    public static function available(Product $product): float
    {
        return round(max(0.0, (float) $product->current_stock - (float) $product->reserved_stock), 3);
    }

    /** Paquetes completos que alcanzan con lo disponible (en milésimas enteras: 0.3 / 0.1 son 3). */
    public static function fullPackages(float $available, mixed $perPackage): int
    {
        $perPackageMilli = Money::toMilli($perPackage);

        return $perPackageMilli > 0 ? intdiv(Money::toMilli($available), $perPackageMilli) : 0;
    }

    /**
     * Respuesta de GET kiosk/catalog: la sucursal, las categorías que tienen
     * productos (por nombre) y los productos (por nombre).
     *
     * @return array{location: array, categories: array, products: array}
     */
    public function forLocation(int $companyId, Location $location): array
    {
        $products = $this->products($companyId, $location->id, null, ['mainImage']);

        $categories = Category::query()
            ->whereIn('id', $products->pluck('category_id')->filter()->unique()->values())
            ->where(fn($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
        $categoryIds = $categories->pluck('id')->flip();

        return [
            'location' => ['id' => $location->id, 'name' => $location->name],
            'categories' => $categories->map(fn(Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()->all(),
            'products' => $products->map(fn(Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                // Toda categoría que se nombra viene en `categories`.
                'category_id' => $product->category_id && $categoryIds->has($product->category_id) ? $product->category_id : null,
                'image_url' => self::imageUrl($product),
                'unit' => $product->unit
                    ? ['name' => $product->unit->name, 'abbreviation' => $product->unit->abbreviation]
                    : null,
                'sold_loose' => self::soldLoose($product),
                'price' => self::soldLoose($product) ? SalePricing::catalogPrice($product) : null,
                'available' => self::available($product),
                'packages' => self::packages($product)->map(fn(ProductPackage $package) => [
                    'id' => $package->id,
                    'name' => $package->package_name,
                    'quantity' => (float) $package->quantity_per_package,
                    'price' => SalePricing::catalogPrice($product, $package),
                    'available' => self::fullPackages(self::available($product), $package->quantity_per_package),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** URL absoluta de la foto principal (la misma que main_image.uri de ProductResource) o null. */
    private static function imageUrl(Product $product): ?string
    {
        $path = $product->mainImage?->image_path;

        return $path ? (AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $path)['uri'] ?? null) : null;
    }
}
