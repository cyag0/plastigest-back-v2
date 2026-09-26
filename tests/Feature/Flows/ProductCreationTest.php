<?php

namespace Tests\Feature\Flows;

use App\Models\InventoryType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('raw_material', $product->product_type, 'product_type se sincroniza con el tipo');
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
            'product_type' => 'commercial',
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
}
