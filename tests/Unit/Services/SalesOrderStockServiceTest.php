<?php

namespace Tests\Unit\Services;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOrderStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Product $product;

    private Unit $kilogram;

    private Unit $gram;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $this->company = Company::create([
            'name' => 'Empresa Demo',
            'business_name' => 'Empresa Demo SA de CV',
            'rfc' => 'DEM010101AAA',
            'address' => 'Calle 1',
            'phone' => '5550000000',
            'email' => 'empresa@example.com',
            'is_active' => true,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Sucursal Centro',
            'description' => 'Sucursal principal',
            'is_active' => true,
            'address' => 'Calle 2',
            'phone' => '5550000001',
            'email' => 'sucursal@example.com',
        ]);

        $this->kilogram = Unit::query()->where('abbreviation', 'kg')->firstOrFail();
        $this->gram = Unit::query()->where('abbreviation', 'g')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'unit_id' => $this->kilogram->id,
            'code' => 'FRIJOL-001',
            'name' => 'Frijol',
            'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
            'for_sale' => true,
        ]);

        $this->product->locations()->attach($this->location->id, [
            'current_stock' => 6,
            'reserved_stock' => 0,
            'minimum_stock' => 0,
            'maximum_stock' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rejects_mixed_quantities_when_total_requested_exceeds_available_stock(): void
    {
        $package = ProductPackage::create([
            'product_id' => $this->product->id,
            'company_id' => $this->company->id,
            'package_name' => 'Bolsa de 6 kg',
            'barcode' => 'PKG-6KG-001',
            'quantity_per_package' => 6,
            'is_active' => true,
        ]);

        $service = app()->make('App\\Services\\SalesOrderStockService');

        try {
            $service->validateDraftDetails($this->location->id, [
                [
                    'product_id' => $this->product->id,
                    'package_id' => null,
                    'unit_id' => $this->kilogram->id,
                    'requested_quantity' => 1,
                ],
                [
                    'product_id' => $this->product->id,
                    'package_id' => $package->id,
                    'unit_id' => $this->kilogram->id,
                    'requested_quantity' => 1,
                ],
            ]);

            $this->fail('Expected stock validation to reject the mixed quantity request.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertArrayHasKey('details.0.requested_quantity', $errors);
            $this->assertStringContainsString('Frijol', $errors['details.0.requested_quantity'][0]);
            $this->assertStringContainsString('Disponible: 6.000 kg', $errors['details.0.requested_quantity'][0]);
            $this->assertStringContainsString('solicitado: 7.000 kg', $errors['details.0.requested_quantity'][0]);
        }
    }

    public function test_allows_quantities_that_fit_after_unit_conversion(): void
    {
        $package = ProductPackage::create([
            'product_id' => $this->product->id,
            'company_id' => $this->company->id,
            'package_name' => 'Bolsa de 5 kg',
            'barcode' => 'PKG-5KG-001',
            'quantity_per_package' => 5,
            'is_active' => true,
        ]);

        $service = app()->make('App\\Services\\SalesOrderStockService');

        $service->validateDraftDetails($this->location->id, [
            [
                'product_id' => $this->product->id,
                'package_id' => null,
                'unit_id' => $this->gram->id,
                'requested_quantity' => 1000,
            ],
            [
                'product_id' => $this->product->id,
                'package_id' => $package->id,
                'unit_id' => $this->kilogram->id,
                'requested_quantity' => 1,
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_reserve_order_moves_requested_stock_to_reserved_stock(): void
    {
        $package = ProductPackage::create([
            'product_id' => $this->product->id,
            'company_id' => $this->company->id,
            'package_name' => 'Bolsa de 5 kg',
            'barcode' => 'PKG-5KG-002',
            'quantity_per_package' => 5,
            'is_active' => true,
        ]);

        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'order_number' => 'SOR-TEST-0001',
            'order_date' => now()->toDateString(),
            'channel' => 'admin',
            'service_mode' => 'delivery',
            'status' => 'pending',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'package_id' => null,
            'unit_id' => $this->gram->id,
            'requested_quantity' => 1000,
            'unit_price' => 10,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'package_id' => $package->id,
            'unit_id' => $this->kilogram->id,
            'requested_quantity' => 1,
            'unit_price' => 50,
        ]);

        $service = app()->make('App\\Services\\SalesOrderStockService');
        $service->reserveOrder($order->fresh('details'));

        $reservedStock = $this->product->fresh()->locations()->where('location_id', $this->location->id)->firstOrFail()->pivot->reserved_stock;

        $this->assertSame('6.000', number_format((float) $reservedStock, 3, '.', ''));
        $this->assertNotNull($order->fresh()->reserved_at);
        $this->assertSame('1.000', $order->fresh('details')->details[0]->reserved_quantity_base);
        $this->assertSame('5.000', $order->fresh('details')->details[1]->reserved_quantity_base);
    }

    public function test_release_order_returns_reserved_stock_to_availability(): void
    {
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'order_number' => 'SOR-TEST-0002',
            'order_date' => now()->toDateString(),
            'channel' => 'admin',
            'service_mode' => 'delivery',
            'status' => 'preparing',
            'reserved_at' => now(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'package_id' => null,
            'unit_id' => $this->kilogram->id,
            'requested_quantity' => 2,
            'reserved_quantity_base' => 2,
            'unit_price' => 10,
        ]);

        $this->product->locations()->updateExistingPivot($this->location->id, [
            'reserved_stock' => 2,
            'updated_at' => now(),
        ]);

        $service = app()->make('App\\Services\\SalesOrderStockService');
        $service->releaseOrder($order->fresh('details'));

        $reservedStock = $this->product->fresh()->locations()->where('location_id', $this->location->id)->firstOrFail()->pivot->reserved_stock;

        $this->assertSame('0.000', number_format((float) $reservedStock, 3, '.', ''));
        $this->assertNull($order->fresh()->reserved_at);
        $this->assertSame('0.000', $order->fresh('details')->details[0]->reserved_quantity_base);
    }

    public function test_checkout_releases_reservation_and_decrements_real_stock(): void
    {
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'order_number' => 'SOR-TEST-0003',
            'order_date' => now()->toDateString(),
            'channel' => 'admin',
            'service_mode' => 'delivery',
            'status' => 'preparing',
            'reserved_at' => now(),
            'subtotal' => 20,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 20,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'package_id' => null,
            'unit_id' => $this->kilogram->id,
            'requested_quantity' => 2,
            'reserved_quantity_base' => 2,
            'unit_price' => 10,
            'line_subtotal' => 20,
            'line_total' => 20,
        ]);

        $this->product->locations()->updateExistingPivot($this->location->id, [
            'reserved_stock' => 2,
            'updated_at' => now(),
        ]);

        $service = app()->make('App\\Services\\SalesOrderStockService');
        $sale = $service->checkoutOrder($order->fresh('details'), [
            'payment_method' => 'cash',
            'paid_amount' => 20,
        ]);

        $pivot = $this->product->fresh()
            ->locations()
            ->where('location_id', $this->location->id)
            ->firstOrFail()
            ->pivot;

        $this->assertSame('4.000', number_format((float) $pivot->current_stock, 3, '.', ''));
        $this->assertSame('0.000', number_format((float) $pivot->reserved_stock, 3, '.', ''));

        $order = $order->fresh();
        $this->assertSame('delivered', $order->status->value);
        $this->assertSame($sale->id, $order->sale_id);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('closed', $sale->status->value);
    }
}