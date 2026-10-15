<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Customer;
use App\Models\ProductPackage;
use App\Models\SalesOrder;

/**
 * Qué se vende y a qué precio (App\Support\SalePricing), igual en el punto de
 * venta, la pantalla de ventas anterior y los pedidos:
 *
 * - Un producto que no se vende suelto (for_sale = false) se ofrece en sus
 *   paquetes con precio ("PAQ GALLETAS 16 PZA"), nunca la pieza.
 * - Sin sales_discount el pedido se cobra al precio de catálogo (antes tomaba
 *   el precio que mandara la app).
 */
class VentasCajaCatalogPricingTest extends VentasCajaTestCase
{
    private const ORDER_TAKER = ['sales_orders_create', 'sales_orders_update', 'sales_orders_read', 'sales_orders_list', 'products_list'];

    /** Galleta que solo se vende en paquete: la pieza no tiene precio. */
    private function cookieWithPackages(float $stock = 160): array
    {
        $cookie = $this->createProduct(['name' => 'Galleta de coco 1 pza', 'for_sale' => false, 'sale_price' => 0], $stock);

        $priced = ProductPackage::create([
            'product_id' => $cookie->id, 'company_id' => $this->company->id, 'unit_id' => $cookie->unit_id,
            'package_name' => 'PAQ 16 PZA', 'barcode' => '0047', 'quantity_per_package' => 16,
            'sale_price' => 80, 'is_active' => true,
        ]);
        // Sin precio propio ni precio de la pieza: $0, no se puede vender.
        $unpriced = ProductPackage::create([
            'product_id' => $cookie->id, 'company_id' => $this->company->id, 'unit_id' => $cookie->unit_id,
            'package_name' => 'PAQ 4 PZA', 'barcode' => '0048', 'quantity_per_package' => 4,
            'sale_price' => null, 'is_active' => true,
        ]);

        return [$cookie, $priced, $unpriced];
    }

    public function test_pos_catalog_offers_the_priced_package_of_a_product_not_sold_loose(): void
    {
        [$cookie, $priced, $unpriced] = $this->cookieWithPackages();
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 5);
        $cashier = $this->cashier();

        $items = collect($this->actingInBranch($cashier)->getJson('/api/auth/admin/pos/catalog')->assertOk()->json('data'))->keyBy('key');

        $this->assertArrayHasKey("k{$priced->id}", $items->all(), 'el paquete con precio se vende');
        $this->assertEquals(80, $items["k{$priced->id}"]['price']);
        $this->assertEquals(10, $items["k{$priced->id}"]['stock'], '160 piezas = 10 paquetes');
        $this->assertArrayNotHasKey("p{$cookie->id}", $items->all(), 'la pieza suelta no se ofrece');
        $this->assertArrayNotHasKey("k{$unpriced->id}", $items->all(), 'un paquete en $0 no se ofrece');
        $this->assertArrayHasKey("p{$coco->id}", $items->all());
    }

    public function test_pos_sells_the_package_but_not_the_loose_piece(): void
    {
        [$cookie, $priced, $unpriced] = $this->cookieWithPackages();
        $cashier = $this->cashier();

        $this->posSale($cashier, [['product_id' => $cookie->id, 'package_id' => $priced->id, 'quantity' => 2]], [['method' => 'card', 'amount' => 160]])
            ->assertCreated()
            ->assertJsonPath('data.total', 160);
        $this->assertEquals(128, $this->stockOf($cookie));

        $this->posSale($cashier, [['product_id' => $cookie->id, 'quantity' => 1, 'unit_price' => 0]], [['method' => 'card', 'amount' => 0.01]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.product_id');

        $this->posSale($cashier, [['product_id' => $cookie->id, 'package_id' => $unpriced->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 0.01]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.product_id');

        $this->assertEquals(128, $this->stockOf($cookie), 'los rechazos no mueven stock');
    }

    public function test_a_package_of_another_product_is_rejected(): void
    {
        [$cookie, $priced] = $this->cookieWithPackages();
        $coco = $this->createProduct(['sale_price' => 30], 50);
        $cashier = $this->cashier();

        $this->posSale($cashier, [['product_id' => $coco->id, 'package_id' => $priced->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 80]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.package_id');
    }

    public function test_sale_screens_list_the_package_with_its_catalog_price(): void
    {
        [$cookie, $priced, $unpriced] = $this->cookieWithPackages();
        // Producto sin nada vendible: ni pieza ni paquete con precio.
        $this->createProduct(['name' => 'Insumo', 'for_sale' => false], 10);

        $products = collect($this->actingInBranch($this->user)->getJson('/api/auth/admin/sales/initial-data')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertTrue($products->has($cookie->id));
        $this->assertFalse((bool) $products[$cookie->id]['for_sale'], 'la app sabe que la pieza no se vende');
        $packages = collect($products[$cookie->id]['active_packages']);
        $this->assertSame([$priced->id], $packages->pluck('id')->all(), 'solo el paquete con precio');
        $this->assertEquals(80, $packages->first()['price']);
        $this->assertCount(1, $products, 'el insumo no aparece');

        $orderProducts = collect($this->actingInBranch($this->user)->getJson('/api/auth/admin/sales-orders/initial-data')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertTrue($orderProducts->has($cookie->id));
        $this->assertSame([$priced->id], collect($orderProducts[$cookie->id]['active_packages'])->pluck('id')->all());
        $this->assertCount(1, $orderProducts);
    }

    public function test_order_catalog_only_lists_the_companys_products_and_customers(): void
    {
        $this->createProduct(['name' => 'Coco entero'], 5);
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente propio', 'is_active' => true]);

        [$otherCompany, $otherBranch] = $this->createCompanyWithBranch();
        Customer::create(['company_id' => $otherCompany->id, 'name' => 'Cliente ajeno', 'is_active' => true]);
        // Producto de otra empresa activo en la sucursal del contexto (dato
        // inconsistente): aun así no sale.
        $this->createProduct(['name' => 'Producto ajeno', 'company_id' => $otherCompany->id], 5);

        $data = $this->actingInBranch($this->user)->getJson('/api/auth/admin/sales-orders/initial-data')->assertOk()->json('data');

        $this->assertSame(['Coco entero'], collect($data['products'])->pluck('name')->all());
        $this->assertSame(['Cliente propio'], collect($data['customers'])->pluck('name')->all());
    }

    public function test_an_order_is_charged_at_catalog_price_without_sales_discount(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 50);
        $taker = $this->createBranchUser($this->location, self::ORDER_TAKER)->fresh();

        $payload = fn($price) => [
            'channel' => 'kiosk', 'service_mode' => 'counter',
            'details' => [$this->orderLine($coco, 2, $price)],
        ];

        $this->actingInBranch($taker)->postJson('/api/auth/admin/sales-orders', $payload(1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.unit_price');
        $this->assertSame(0, SalesOrder::count());

        $this->actingInBranch($taker)->postJson('/api/auth/admin/sales-orders', $payload(30))
            ->assertOk()
            ->assertJsonPath('data.total_amount', '60.00');
    }

    public function test_sales_discount_may_set_an_order_price(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 50);
        $manager = $this->createBranchUser($this->location, array_merge(self::ORDER_TAKER, ['sales_discount']))->fresh();

        $this->actingInBranch($manager)->postJson('/api/auth/admin/sales-orders', [
            'channel' => 'phone', 'service_mode' => 'counter',
            'details' => [$this->orderLine($coco, 2, 25)],
        ])->assertOk();

        $this->assertEquals(50, SalesOrder::sole()->total_amount);
    }

    public function test_editing_an_order_without_lines_keeps_its_lines_branch_and_creator(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 50);
        $id = $this->actingInBranch($this->user)->postJson('/api/auth/admin/sales-orders', [
            'channel' => 'phone', 'service_mode' => 'counter',
            'details' => [$this->orderLine($coco, 2)],
        ])->assertOk()->json('data.id');

        $editor = $this->createBranchUser($this->location, self::ORDER_TAKER)->fresh();
        $this->actingInBranch($editor)->putJson("/api/auth/admin/sales-orders/{$id}", ['notes' => 'Pasa a las 5'])
            ->assertOk();

        $order = SalesOrder::with('details')->findOrFail($id);
        $this->assertSame('Pasa a las 5', $order->notes);
        $this->assertCount(1, $order->details, 'antes se borraban los renglones');
        $this->assertEquals(60, $order->total_amount);
        $this->assertSame($this->user->id, $order->created_by, 'antes se borraba quién lo creó');
        $this->assertSame($editor->id, $order->updated_by);
        $this->assertSame($this->location->id, $order->location_id);
    }
}
