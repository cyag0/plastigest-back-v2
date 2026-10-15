<?php

namespace Tests\Feature\Logic;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CalendarDateSerializationTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function sale(array $attributes = []): Sale
    {
        return Sale::create($attributes + [
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'sale_date' => now()->toDateString(), 'status' => 'closed', 'subtotal' => 10, 'total' => 10,
            'payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => 10,
        ]);
    }

    /**
     * Las fechas de calendario viajan como "YYYY-MM-DD". Antes salían como
     * medianoche del servidor en UTC ("…T06:00:00Z"), que en Guayabitos
     * (UTC-7) se veía como el día anterior.
     */
    public function test_calendar_dates_are_serialized_without_time(): void
    {
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
        $sale = $this->sale(['sale_date' => '2026-09-27']);

        $this->assertSame('2026-09-27', $sale->fresh()->toArray()['sale_date']);

        $headers = ['X-Company-ID' => $this->company->id, 'X-Location-ID' => $this->location->id];
        $this->withHeaders($headers)->getJson("/api/auth/admin/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.sale_date', '2026-09-27');
        $this->withHeaders($headers)->getJson('/api/auth/admin/sales')
            ->assertOk()
            ->assertJsonPath('data.0.sale_date', '2026-09-27');
    }
}
