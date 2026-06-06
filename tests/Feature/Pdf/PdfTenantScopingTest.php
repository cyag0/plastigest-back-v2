<?php

namespace Tests\Feature\Pdf;

use App\Models\Category;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Unit;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\SalesReport;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifica que los endpoints de generación/emisión de PDFs de inventory-counts,
 * sales-reports y products respetan el aislamiento por compañía (anti-IDOR).
 *
 * Cubre dos barreras:
 *  1. `*-pdf-url` autenticados: solo emiten URL si el registro pertenece a la
 *     compañía activa del request (header X-Company-ID).
 *  2. Rutas públicas con URL firmada: el `company_id` va dentro de la URL
 *     firmada, y el controller lo valida contra el registro antes de renderizar.
 */
class PdfTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private Location $locationA;
    private Location $locationB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA  = $this->makeCompany('A');
        $this->companyB  = $this->makeCompany('B');
        $this->locationA = $this->makeLocation('A', $this->companyA->id);
        $this->locationB = $this->makeLocation('B', $this->companyB->id);

        $this->user = User::factory()->create();
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

    public function test_inventory_count_pdf_url_is_404_for_other_tenant(): void
    {
        $inventoryCount = $this->makeInventoryCount($this->companyA, $this->locationA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/inventory-counts/{$inventoryCount->id}/pdf-url");

        $response->assertStatus(404);
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

    // ─── SalesReport ────────────────────────────────────────────────────────

    public function test_sales_report_pdf_url_is_404_for_other_tenant(): void
    {
        $salesReport = $this->makeSalesReport($this->companyA, $this->locationA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/sales-reports/{$salesReport->id}/pdf-url");

        $response->assertStatus(404);
    }

    public function test_sales_report_pdf_url_is_200_for_own_tenant(): void
    {
        $salesReport = $this->makeSalesReport($this->companyA, $this->locationA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyA->id,
            'X-Location-ID' => $this->locationA->id,
        ])->getJson("/api/auth/admin/sales-reports/{$salesReport->id}/pdf-url");

        $response->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);
    }

    public function test_sales_report_render_rejects_signed_url_with_wrong_company(): void
    {
        $salesReport = $this->makeSalesReport($this->companyA, $this->locationA);

        $signedUrl = URL::temporarySignedRoute(
            'sales-reports.pdf',
            now()->addHour(),
            ['id' => $salesReport->id, 'company_id' => $this->companyB->id]
        );

        $response = $this->get($signedUrl);
        $response->assertStatus(404);
    }

    // ─── Product (label printing) ────────────────────────────────────────────

    public function test_product_label_pdf_url_is_404_for_other_tenant(): void
    {
        $product = $this->makeProduct($this->companyA);

        $response = $this->withHeaders([
            'X-Company-ID'  => $this->companyB->id,
            'X-Location-ID' => $this->locationB->id,
        ])->getJson("/api/auth/admin/products/{$product->id}/labels/pdf-url?quantity=2");

        $response->assertStatus(404);
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

    private function makeSalesReport(Company $company, Location $location): SalesReport
    {
        return SalesReport::create([
            'company_id'         => $company->id,
            'location_id'        => $location->id,
            'user_id'            => $this->user->id,
            'report_date'        => now()->toDateString(),
            'total_sales'        => 0,
            'total_cash'         => 0,
            'total_card'         => 0,
            'total_transfer'     => 0,
            'total_expenses'     => 0,
            'net_income'         => 0,
            'transactions_count' => 0,
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
            'product_type'   => Product::PRODUCT_TYPE_PROCESSED,
            'for_sale'       => true,
        ]);
    }
}
