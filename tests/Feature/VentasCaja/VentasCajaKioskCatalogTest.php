<?php

namespace Tests\Feature\VentasCaja;

use App\Constants\Files;
use App\Models\Admin\Customer;
use App\Models\Category;
use App\Models\InventoryType;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Catálogo del kiosko de autoservicio (GET kiosk/catalog): lo que ve un
 * cliente. Solo lo que se puede vender en la sucursal y a más de $0, sin
 * costos, clientes ni datos internos.
 */
class VentasCajaKioskCatalogTest extends KioskTestCase
{
    public function test_it_needs_sales_kiosk(): void
    {
        $this->createProduct(['sale_price' => 30], 10);

        $this->catalog($this->cashier([], false))->assertForbidden();
        $this->catalog($this->createBranchUser($this->location, ['sales_orders_create', 'sales_orders_update', 'products_list'])->fresh())
            ->assertForbidden();

        $this->catalog($this->kioskUser())->assertOk()->assertJsonCount(1, 'data.products');
    }

    public function test_it_has_no_costs_customers_or_internal_data(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30, 'purchase_price' => 7.77], 10);
        $this->package($coco, 4, 110, 'Red 4');
        Customer::create([
            'company_id' => $this->company->id, 'name' => 'Cliente Secreto', 'rfc' => 'XAXX010101000',
            'phone' => '5512345678', 'email' => 'secreto@example.com', 'is_active' => true,
        ]);

        $response = $this->catalog($this->kioskUser())->assertOk();

        $this->assertSame(['location', 'categories', 'products'], array_keys($response->json('data')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.location')));
        $this->assertSame(
            ['id', 'name', 'category_id', 'image_url', 'unit', 'sold_loose', 'price', 'available', 'packages'],
            array_keys($response->json('data.products.0')),
        );
        $this->assertSame(['name', 'abbreviation'], array_keys($response->json('data.products.0.unit')));
        $this->assertSame(['id', 'name', 'quantity', 'price', 'available'], array_keys($response->json('data.products.0.packages.0')));

        $body = $response->getContent();
        foreach (['purchase_price', '7.77', '1.11', 'Cliente Secreto', 'XAXX010101000', '5512345678', 'secreto@example.com', 'customers', 'current_stock', 'reserved_stock', 'supplier'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_it_leaves_out_what_the_kiosk_cannot_sell(): void
    {
        $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);

        // Desactivado en esta sucursal (el punto de venta tampoco lo ofrece).
        $inactive = $this->createProduct(['name' => 'Inactivo en la sucursal', 'sale_price' => 30], 10);
        DB::table('product_location')->where('product_id', $inactive->id)->update(['active' => false]);
        // Sin fila en esta sucursal.
        $elsewhere = $this->createProduct(['name' => 'De otra sucursal', 'sale_price' => 30], 10);
        DB::table('product_location')->where('product_id', $elsewhere->id)->delete();
        // A la venta pero sin precio ni paquetes: nunca a $0.
        $this->createProduct(['name' => 'Sin precio', 'sale_price' => 0], 10);
        // Su tipo no se vende (material de empaque).
        $this->createProduct(['name' => 'Botella vacía', 'sale_price' => 5], 10, 'Pieza', InventoryType::PACKAGING_MATERIAL);
        // No se vende suelto y su único paquete no tiene precio.
        $piece = $this->createProduct(['name' => 'Pieza sin paquete con precio', 'for_sale' => false, 'sale_price' => 0], 10);
        $this->package($piece, 4, null);
        // De otra empresa, aunque esté activo en esta sucursal (dato inconsistente).
        [$otherCompany] = $this->createCompanyWithBranch();
        $this->createProduct(['name' => 'Producto ajeno', 'company_id' => $otherCompany->id, 'sale_price' => 30], 10);
        // Suelto a $0 pero con un paquete con precio: sale, solo con ese paquete.
        $cookie = $this->createProduct(['name' => 'Galleta', 'sale_price' => 0], 32);
        $priced = $this->package($cookie, 16, 80, 'PAQ 16');
        $this->package($cookie, 4, null, 'PAQ 4 sin precio');
        $this->package($cookie, 8, 50, 'PAQ 8 inactivo', false);

        $products = collect($this->catalog($this->kioskUser())->assertOk()->json('data.products'))->keyBy('name');

        $this->assertSame(['Coco entero', 'Galleta'], $products->keys()->all(), 'por nombre y solo lo vendible');
        $this->assertTrue($products['Coco entero']['sold_loose']);
        $this->assertEquals(30, $products['Coco entero']['price']);
        $this->assertSame([], $products['Coco entero']['packages']);
        $this->assertFalse($products['Galleta']['sold_loose']);
        $this->assertNull($products['Galleta']['price']);
        $this->assertSame([$priced->id], collect($products['Galleta']['packages'])->pluck('id')->all());
    }

    public function test_packages_count_full_packages_of_what_is_available(): void
    {
        $agua = $this->createProduct(['name' => 'Agua de coco 1 litro', 'sale_price' => 65], 30, 'Litro');
        $this->setReserved($agua, 5);
        $twelve = $this->package($agua, 12, 700, 'Paquete 12');
        // Sin precio propio: 6 × $65.
        $six = $this->package($agua, 6, null, 'Paquete 6');
        // Más apartado que existencia: disponible 0, no negativo, y sigue saliendo.
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 2);
        $this->setReserved($coco, 3);

        $products = collect($this->catalog($this->kioskUser())->assertOk()->json('data.products'))->keyBy('id');

        $water = $products[$agua->id];
        $this->assertSame(['name' => 'Litro', 'abbreviation' => 'L'], $water['unit']);
        $this->assertEquals(25, $water['available']);
        $packages = collect($water['packages'])->keyBy('id');
        $this->assertSame(['Paquete 12', 'Paquete 6'], $packages->pluck('name')->sort()->values()->all());
        $this->assertEquals(12, $packages[$twelve->id]['quantity']);
        $this->assertEquals(700, $packages[$twelve->id]['price']);
        $this->assertSame(2, $packages[$twelve->id]['available'], '25 litros = 2 paquetes de 12 completos');
        $this->assertEquals(390, $packages[$six->id]['price']);
        $this->assertSame(4, $packages[$six->id]['available']);

        $this->assertEquals(0, $products[$coco->id]['available']);
    }

    public function test_categories_location_and_photos(): void
    {
        Storage::fake('public');
        $drinks = Category::create(['company_id' => $this->company->id, 'name' => 'Bebidas', 'is_active' => true]);
        $snacks = Category::create(['company_id' => $this->company->id, 'name' => 'Antojos', 'is_active' => true]);
        Category::create(['company_id' => $this->company->id, 'name' => 'Sin productos', 'is_active' => true]);

        $water = $this->createProduct(['name' => 'Agua de coco', 'sale_price' => 65, 'category_id' => $drinks->id], 10);
        $this->createProduct(['name' => 'Cocada', 'sale_price' => 20, 'category_id' => $snacks->id], 10);
        $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        ProductImage::create(['product_id' => $water->id, 'image_path' => 'agua.jpg', 'image_type' => 'main']);
        Storage::disk('public')->put(Files::PRODUCT_IMAGES_PATH . '/agua.jpg', 'imagen');

        $data = $this->catalog($this->kioskUser())->assertOk()->json('data');

        $this->assertSame(['id' => $this->location->id, 'name' => $this->location->name], $data['location']);
        $this->assertSame([
            ['id' => $snacks->id, 'name' => 'Antojos'],
            ['id' => $drinks->id, 'name' => 'Bebidas'],
        ], $data['categories'], 'solo las que tienen productos, por nombre');

        $products = collect($data['products'])->keyBy('name');
        $this->assertSame($drinks->id, $products['Agua de coco']['category_id']);
        $this->assertNull($products['Coco entero']['category_id']);
        $this->assertSame(url('/api/auth/files/' . Files::PRODUCT_IMAGES_PATH . '/agua.jpg'), $products['Agua de coco']['image_url']);
        $this->assertStringStartsWith('http', $products['Agua de coco']['image_url']);
        $this->assertNull($products['Cocada']['image_url']);
    }
}
