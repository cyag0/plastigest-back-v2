<?php

namespace Tests\Feature\Flows;

use App\Models\InventoryType;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Alta de productos como la manda la app (multipart, campos vacíos).
 * Punto 14 del checklist.
 */
class ProductCreationTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Botella 1 Lt',
            'code' => 'BOT-1L',
            'inventory_type_id' => InventoryType::idForCode(InventoryType::PACKAGING_MATERIAL),
            'unit_id' => $this->unitId('Pieza'),
            'current_location_id' => $this->location->id,
            'is_active' => '1',
            'for_sale' => '1',
            // El formulario manda estos campos vacíos por defecto.
            'minimum_stock' => '',
            'maximum_stock' => '',
        ], $overrides);
    }

    public function test_creates_product_with_company_from_header_and_empty_stock_levels(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload());

        $response->assertCreated();

        $product = Product::where('code', 'BOT-1L')->firstOrFail();
        $this->assertSame($this->company->id, (int) $product->company_id);
        // product_type ya no es columna: se calcula del tipo para versiones viejas de la app.
        $this->assertSame('raw_material', $product->product_type);
        $this->assertDatabaseHas('product_location', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'active' => 1,
            'maximum_stock' => 0,
        ]);
    }

    public function test_accepts_company_id_sent_as_array(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload(['company_id' => [$this->company->id]]))
            ->assertCreated();
    }

    public function test_duplicate_code_in_same_company_is_a_validation_error(): void
    {
        $this->createProduct(['code' => 'BOT-1L']);

        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_same_code_is_allowed_in_another_company(): void
    {
        $other = \App\Models\Admin\Company::factory()->create();
        Product::create([
            'name' => 'Otra empresa',
            'code' => 'BOT-1L',
            'company_id' => $other->id,
            'inventory_type_id' => InventoryType::idForCode(InventoryType::MERCHANDISE),
        ]);

        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload())
            ->assertCreated();
    }

    public function test_failed_creation_leaves_no_half_created_product(): void
    {
        // Un ingrediente inexistente no pasa validación: no debe quedar nada.
        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload([
                'ingredients' => [['ingredient_id' => 999999, 'quantity' => 1]],
            ]))
            ->assertStatus(422);

        $this->assertDatabaseMissing('products', ['code' => 'BOT-1L']);
    }

    public function test_old_app_versions_can_still_send_product_type(): void
    {
        // Una app instalada de antes manda product_type y no inventory_type_id.
        $payload = $this->payload(['product_type' => 'processed']);
        unset($payload['inventory_type_id']);

        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.product_type', 'processed');

        $product = Product::where('code', 'BOT-1L')->firstOrFail();
        $this->assertSame(InventoryType::idForCode(InventoryType::FINISHED_PRODUCT), (int) $product->inventory_type_id);

        // Y el filtro viejo por product_type sigue funcionando.
        $codes = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/products?product_type[]=processed&per_page=50')
            ->assertOk()->json('data'))->pluck('code');
        $this->assertContains('BOT-1L', $codes->all());
    }

    public function test_the_detail_names_the_supplier_and_the_bulk_unit_for_the_form(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Plásticos del Norte']);
        $bulk = $this->createProduct(['name' => 'Cloro a granel'], 0, 'Litro');
        $product = $this->createProduct([
            'supplier_id' => $supplier->id,
            'requires_packaging' => true,
            'packaging_source_product_id' => $bulk->id,
            'packaging_source_quantity' => 1,
            'packaging_source_unit_id' => $this->unitId('Litro'),
        ]);

        $this->actingInBranch($this->user)->getJson("/api/auth/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.supplier_name', 'Plásticos del Norte')
            ->assertJsonPath('data.packaging_source_unit', ['id' => $this->unitId('Litro'), 'name' => 'Litro']);

        // Sin proveedor ni granel vienen en null, no faltan ni truenan.
        $plain = $this->createProduct();
        $data = $this->actingInBranch($this->user)->getJson("/api/auth/admin/products/{$plain->id}")
            ->assertOk()->json('data');
        $this->assertArrayHasKey('supplier_name', $data);
        $this->assertNull($data['supplier_name']);
        $this->assertArrayHasKey('packaging_source_unit', $data);
        $this->assertNull($data['packaging_source_unit']);
    }

    public function test_stock_levels_keep_their_decimals(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload(['minimum_stock' => '2.5', 'maximum_stock' => '12.75']))
            ->assertCreated();
        $product = Product::where('code', 'BOT-1L')->firstOrFail();

        // Antes el detalle los convertía a entero (2 y 12) y al volver a
        // guardar el producto se quedaban así.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.minimum_stock', 2.5)
            ->assertJsonPath('data.maximum_stock', 12.75);
    }

    public function test_removing_the_last_photo_deletes_it_only_when_the_app_says_the_list_is_complete(): void
    {
        Storage::fake('public');

        $this->withHeaders($this->tenantHeaders())
            ->post('/api/auth/admin/products', $this->payload([
                'product_images' => [UploadedFile::fake()->image('coco.jpg')],
            ]))
            ->assertCreated();
        $product = Product::where('code', 'BOT-1L')->firstOrFail();
        $this->assertSame(1, $product->images()->count());

        // Sin la marca (una versión vieja de la app) una lista vacía no toca las fotos.
        $this->withHeaders($this->tenantHeaders())
            ->post("/api/auth/admin/products/{$product->id}", $this->payload(['_method' => 'PUT']))
            ->assertOk();
        $this->assertSame(1, $product->images()->count());

        // Con product_images_sync la lista vacía quiere decir "quitar todas".
        $this->withHeaders($this->tenantHeaders())
            ->post("/api/auth/admin/products/{$product->id}", $this->payload([
                '_method' => 'PUT',
                'product_images_sync' => '1',
            ]))
            ->assertOk();
        $this->assertSame(0, $product->images()->count());
    }
}
