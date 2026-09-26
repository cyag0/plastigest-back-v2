<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenant();
    }

    // ─── Dashboard Stats ──────────────────────────────────────────────────────

    public function test_dashboard_stats_returns_correct_structure(): void
    {
        $response = $this->withHeaders([
            'X-Company-ID'  => $this->company->id,
            'X-Location-ID' => $this->location->id,
        ])->getJson("/api/auth/admin/inventory/reports/dashboard?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_products',
                    'total_stock',
                    'inventory_value',
                    'movements_count',
                    'low_stock_products',
                ],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_dashboard_stats_with_location_filter(): void
    {
        $response = $this->withHeaders([
            'X-Company-ID'  => $this->company->id,
            'X-Location-ID' => $this->location->id,
        ])->getJson(
            "/api/auth/admin/inventory/reports/dashboard"
                . "?company_id={$this->company->id}"
                . "&location_id={$this->location->id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsInt($data['total_products']);
        $this->assertIsFloat((float) $data['total_stock']);
        $this->assertIsFloat((float) $data['inventory_value']);
        $this->assertIsInt($data['movements_count']);
        $this->assertIsInt($data['low_stock_products']);
    }

    public function test_dashboard_stats_requires_company_id(): void
    {
        $response = $this->withHeaders([
            'X-Company-ID'  => $this->company->id,
            'X-Location-ID' => $this->location->id,
        ])->getJson('/api/auth/admin/inventory/reports/dashboard');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_dashboard_stats_supports_period_filter(): void
    {
        foreach (['today', 'week', 'month'] as $period) {
            $response = $this->withHeaders([
                'X-Company-ID'  => $this->company->id,
                'X-Location-ID' => $this->location->id,
            ])->getJson(
                "/api/auth/admin/inventory/reports/dashboard"
                    . "?company_id={$this->company->id}"
                    . "&period={$period}"
            );

            $response->assertStatus(200, "Failed for period: {$period}");
        }
    }

    public function test_dashboard_stats_requires_authentication(): void
    {
        // Sin sesión: forgetInstance no borraba al usuario de Sanctum::actingAs.
        $this->app['auth']->forgetGuards();

        $response = $this->getJson(
            "/api/auth/admin/inventory/reports/dashboard?company_id={$this->company->id}"
        );

        // Route is protected — should be 401 or redirect
        $this->assertContains($response->status(), [401, 302]);
    }
}
