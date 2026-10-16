<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Base de las pruebas del kiosko de autoservicio: la cuenta del kiosko (solo
 * sales_kiosk), el catálogo, el alta de pedidos por la API y paquetes.
 */
abstract class KioskTestCase extends VentasCajaTestCase
{
    /** La cuenta del dispositivo: solo usa el kiosko. */
    protected const KIOSK = ['sales_kiosk'];

    protected function kioskUser(?Location $location = null): User
    {
        return $this->createBranchUser($location ?? $this->location, self::KIOSK)->fresh();
    }

    protected function catalog(User $user, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user, $location)->getJson('/api/auth/admin/kiosk/catalog');
    }

    /** POST kiosk/orders con una llave nueva (salvo que $extra traiga otra). */
    protected function kioskOrder(User $kiosk, array $items, array $extra = [], ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($kiosk, $location)->postJson('/api/auth/admin/kiosk/orders', array_merge([
            'client_request_id' => 'k-' . Str::uuid(),
            'items' => $items,
        ], $extra));
    }

    /** Renglón del kiosko: suelto o en paquete. */
    protected function item(Product $product, int $quantity, ?ProductPackage $package = null): array
    {
        return ['product_id' => $product->id, 'package_id' => $package?->id, 'quantity' => $quantity];
    }

    protected function package(Product $product, float $perPackage, ?float $price, ?string $name = null, bool $active = true): ProductPackage
    {
        return ProductPackage::create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'unit_id' => $product->unit_id,
            'package_name' => $name ?? "Paquete {$perPackage}",
            'barcode' => 'PK-' . uniqid(),
            'quantity_per_package' => $perPackage,
            'purchase_price' => 1.11,
            'sale_price' => $price,
            'is_active' => $active,
        ]);
    }

    /** Pedido del kiosko creado por la API; regresa su id. */
    protected function placeKioskOrder(array $items, ?Location $location = null, ?string $customerName = null): int
    {
        $kiosk = $this->kioskUser($location);

        return $this->kioskOrder($kiosk, $items, array_filter(['customer_name' => $customerName]), $location)
            ->assertCreated()
            ->json('data.id');
    }
}
