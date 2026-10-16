<?php

namespace Tests\Feature\Pdf;

use App\Models\Category;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Unit;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Verifica que los endpoints de generación/emisión de PDFs de inventory-counts
 * y products respetan el aislamiento por compañía (anti-IDOR).
 *
 * Cubre dos barreras:
 *  1. `*-pdf-url` autenticados: solo emiten URL si el registro pertenece a la
 *     compañía activa del request (header X-Company-ID).
 *  2. Rutas públicas con URL firmada: el `company_id` va dentro de la URL
 *     firmada, y el controller lo valida contra el registro antes de renderizar.
 */
class PdfTenantScopingTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private Company $companyA;
    private Company $companyB;
    private Location $locationA;
    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA  = $this->makeCompany('A');
        $this->companyB  = $this->makeCompany('B');
        $this->locationA = $this->makeLocation('A', $this->companyA->id);
        $this->locationB = $this->makeLocation('B', $this->companyB->id);

        // Asignado a la sucursal de la empresa A con todos los permisos: sin
        // eso todo responde 403 y no se prueba el aislamiento entre empresas.
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->user = $this->createUserWithRole($this->locationA);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function makeCompany(string $tag): Company
    {
        return Company::create([
            'name'          => "Company {$tag}",
            'business_name' => "Company {$tag} S.A. de C.V.",
            'rfc'           => strtoupper(fake()->bothify('???######???')),
            'address'       => fake()->address(),
            'phone'         => fake()->phoneNumber(),
            'email'         => "company{$tag}@" . fake()->domainName(),
            'is_active'     => true,
        ]);
    }

    private function makeLocation(string $tag, int $companyId): Location
    {
        return Location::create([
            'company_id' => $companyId,
            'name'       => "Location {$tag}",
            'code'       => strtoupper($tag),
            'address'    => fake()->address(),
            'is_active'  => true,
        ]);
    }

    protected function tearDown(): void
    {
        CurrentCompany::clear();
        parent::tearDown();
    }

    // ─── InventoryCount ─────────────────────────────────────────────────────

    public function test_inventory_count_pdf_url_is_403_for_other_tenant(): void
    {
        $inventoryCount = $this->makeInventoryCount($this->companyA, $this->locationA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/inventory-counts/{$inventoryCount->id}/pdf-url");

        // El header de otra empresa se rechaza en la puerta (company_forbidden).
        $response->assertStatus(403);
    }

    public function test_inventory_count_pdf_url_is_200_for_own_tenant(): void
    {
        $inventoryCount = $this->makeInventoryCount($this->companyA, $this->locationA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyA->id,
            'X-Location-ID' => $this->locationA->id,
        ])->getJson("/api/auth/admin/inventory-counts/{$inventoryCount->id}/pdf-url");

        $response->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);
    }

    public function test_inventory_count_render_rejects_signed_url_with_wrong_company(): void
    {
        $inventoryCount = $this->makeInventoryCount($this->companyA, $this->locationA);

        // URL firmada que dice pertenecer a companyB sobre un registro de companyA
        $signedUrl = URL::temporarySignedRoute(
            'inventory-counts.pdf',
            now()->addHour(),
            ['id' => $inventoryCount->id, 'company_id' => $this->companyB->id]
        );

        $response = $this->get($signedUrl);
        $response->assertStatus(404);
    }

    // ─── Product (label printing) ────────────────────────────────────────────

    public function test_product_label_pdf_url_is_403_for_other_tenant(): void
    {
        $product = $this->makeProduct($this->companyA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/products/{$product->id}/labels/pdf-url?quantity=2");

        $response->assertStatus(403);
    }

    public function test_product_label_pdf_url_is_200_for_own_tenant(): void
    {
        $product = $this->makeProduct($this->companyA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyA->id,
            'X-Location-ID' => $this->locationA->id,
        ])->getJson("/api/auth/admin/products/{$product->id}/labels/pdf-url?quantity=2");

        $response->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);
    }

    public function test_product_label_render_rejects_signed_url_with_wrong_company(): void
    {
        $product = $this->makeProduct($this->companyA);

        $signedUrl = URL::temporarySignedRoute(
            'products.labels.pdf',
            now()->addHour(),
            ['product' => $product->id, 'quantity' => 1, 'company_id' => $this->companyB->id]
        );

        $response = $this->get($signedUrl);
        $response->assertStatus(404);
    }

    public function test_product_label_render_returns_pdf_for_matching_company(): void
    {
        $product = $this->makeProduct($this->companyA);

        $signedUrl = URL::temporarySignedRoute(
            'products.labels.pdf',
            now()->addHour(),
            ['product' => $product->id, 'quantity' => 2, 'company_id' => $this->companyA->id]
        );

        $response = $this->get($signedUrl);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    // ─── ProductPackage (label printing) ─────────────────────────────────────

    public function test_package_label_pdf_url_is_403_for_other_tenant(): void
    {
        $package = $this->makePackage($this->companyA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/product-packages/{$package->id}/labels/pdf-url?quantity=2");

        $response->assertStatus(403);
    }

    public function test_package_label_pdf_url_is_200_for_own_tenant_and_renders_pdf(): void
    {
        $package = $this->makePackage($this->companyA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyA->id,
            'X-Location-ID' => $this->locationA->id,
        ])->getJson("/api/auth/admin/product-packages/{$package->id}/labels/pdf-url?quantity=3");

        $response->assertStatus(200)->assertJsonStructure(['url', 'expires_at']);

        $pdf = $this->get($response->json('url'));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_package_label_pdf_url_validates_quantity(): void
    {
        $package = $this->makePackage($this->companyA);

        $this->withHeaders([
            'X-Company-ID'  => $this->companyA->id,
            'X-Location-ID' => $this->locationA->id,
        ])->getJson("/api/auth/admin/product-packages/{$package->id}/labels/pdf-url?quantity=500")
            ->assertStatus(422);
    }

    public function test_package_label_render_rejects_signed_url_with_wrong_company(): void
    {
        $package = $this->makePackage($this->companyA);

        $signedUrl = URL::temporarySignedRoute(
            'product-packages.labels.pdf',
            now()->addHour(),
            ['package' => $package->id, 'quantity' => 1, 'company_id' => $this->companyB->id]
        );

        $this->get($signedUrl)->assertStatus(404);
    }

    public function test_package_label_render_rejects_unsigned_url(): void
    {
        $package = $this->makePackage($this->companyA);

        $this->get("/api/product-packages/{$package->id}/labels/pdf?quantity=1&company_id={$this->companyA->id}")
            ->assertStatus(403);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeInventoryCount(Company $company, Location $location): InventoryCount
    {
        return InventoryCount::create([
            'company_id'  => $company->id,
            'location_id' => $location->id,
            'user_id'     => $this->user->id,
            'name'        => 'Conteo test',
            'count_date'  => now()->toDateString(),
            'status'      => 'completed',
            'content'     => [],
        ]);
    }

    private function makePackage(Company $company): ProductPackage
    {
        $product = $this->makeProduct($company);

        return ProductPackage::create([
            'product_id'           => $product->id,
            'company_id'           => $company->id,
            'package_name'         => 'Caja',
            'barcode'              => 'PKG-' . $product->id . '-' . random_int(1000, 9999),
            'quantity_per_package' => 12,
            'sale_price'           => 216.00,
            'is_active'            => true,
        ]);
    }

    private function makeProduct(Company $company): Product
    {
        $category = Category::create([
            'company_id' => $company->id,
            'name'       => 'Test',
            'is_active'  => true,
        ]);
        $unit = Unit::create([
            'company_id'   => $company->id,
            'name'         => 'PZA-' . $company->id,
            'abbreviation' => 'pza',
            'unit_type'    => 'count',
            'is_base_unit' => true,
            'factor_to_base' => 1,
        ]);

        return Product::create([
            'company_id'     => $company->id,
            'name'           => 'Producto test',
            'code'           => 'TEST-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchase_price' => 10.00,
            'sale_price'     => 20.00,
            'category_id'    => $category->id,
            'unit_id'        => $unit->id,
            'inventory_type_id' => \App\Models\InventoryType::idForCode(\App\Models\InventoryType::FINISHED_PRODUCT),
            'for_sale'       => true,
        ]);
    }
}
