<?php

namespace Tests\Feature\Flows;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Inicio: GET /home/summary.
 */
class HomeSummaryTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(float $total, string $at, string $status = 'closed'): void
    {
        DB::table('sales')->insert([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'sale_number' => 'SAL-' . uniqid(), 'sale_date' => substr($at, 0, 10), 'status' => $status,
            'subtotal' => $total, 'total' => $total, 'payment_method' => 'cash', 'payment_status' => 'paid',
            'paid_amount' => $total, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_sales_compare_with_yesterday_at_the_same_time(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->sale(100, '2026-10-14 09:30:00');
        $this->sale(50, '2026-10-14 11:10:00');
        $this->sale(999, '2026-10-14 10:00:00', 'cancelled');
        $this->sale(80, '2026-10-13 10:00:00');   // ayer antes de las 12
        $this->sale(500, '2026-10-13 18:00:00');  // ayer después: no cuenta en "a esta hora"

        $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/home/summary')
            ->assertOk()
            ->assertJsonPath('sales.today_total', 150)
            ->assertJsonPath('sales.today_count', 2)
            ->assertJsonPath('sales.average_ticket', 75)
            ->assertJsonPath('sales.yesterday_same_time', 80)
            ->assertJsonPath('sales.yesterday_total', 580)
            ->assertJsonPath('sales.by_hour.9.today', 100)
            ->assertJsonPath('sales.by_hour.18.yesterday', 500);
    }

    public function test_alerts_and_forgotten_shifts(): void
    {
        $out = $this->createProduct([], 0);
        $low = $this->createProduct([], 2);
        DB::table('product_location')->where('product_id', $low->id)->update(['minimum_stock' => 5]);
        $this->createProduct([], 50);

        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'name' => 'Caja 1', 'code' => 'C1',
        ]);
        $cashier = $this->createUserWithRole($this->location, ['sales_pos']);
        CashRegisterSession::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'cash_register_id' => $register->id,
            'user_id' => $cashier->id, 'status' => 'open', 'opened_at' => now()->subDays(3), 'opening_amount' => 500,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/home/summary')->assertOk();

        $alerts = collect($response->json('alerts'))->pluck('count', 'key');
        $this->assertSame(1, $alerts['out_of_stock']);
        $this->assertSame(1, $alerts['low_stock']);
        $this->assertTrue($response->json('cash.open_in_branch.0.stale'));
        $this->assertNull($response->json('cash.mine'));
    }

    public function test_blocks_follow_permissions(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->location, ['sales_pos']), ['*']);

        $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/home/summary')
            ->assertOk()
            ->assertJsonPath('sales', null)
            ->assertJsonPath('production_today', null)
            ->assertJsonPath('cash.can_sell', true)
            ->assertJsonPath('cash.open_in_branch', [])
            ->assertJsonPath('alerts', []);
    }
}
