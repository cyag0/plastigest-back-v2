<?php

namespace Tests\Feature\Integracion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Filtro de fechas de las listas de CrudController (date_from/date_to o
 * start_date/end_date): un rango sobre la columna en lugar de DATE(columna),
 * para que use el índice, con el mismo resultado en columnas DATE
 * (cash_movements.movement_date) y DATETIME (customers.created_at).
 */
class CrudDateRangeTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function movement(string $day, string $concept): void
    {
        DB::table('cash_movements')->insert([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'type' => 'income',
            'amount' => 10,
            'concept' => $concept,
            'payment_method' => 'cash',
            'movement_date' => $day,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(string $createdAt, string $name): void
    {
        DB::table('customers')->insert([
            'company_id' => $this->company->id,
            'name' => $name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function listed(string $uri, string $field): array
    {
        return collect($this->withHeaders($this->tenantHeaders())->getJson($uri)->assertOk()->json('data'))
            ->pluck($field)->sort()->values()->all();
    }

    public function test_una_columna_date_se_filtra_por_rango_con_los_dos_dias_incluidos(): void
    {
        $this->movement('2026-03-09', 'antes');
        $this->movement('2026-03-10', 'desde');
        $this->movement('2026-03-11', 'hasta');
        $this->movement('2026-03-12', 'despues');

        DB::enableQueryLog();
        $this->assertSame(['desde', 'hasta'], $this->listed('/api/auth/admin/cash-movements?date_from=2026-03-10&date_to=2026-03-11', 'concept'));

        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn($q) => str_contains($q, 'from `cash_movements`'));
        $this->assertNotNull($sql);
        $this->assertStringContainsString('`movement_date` >= ?', $sql);
        $this->assertStringContainsString('`movement_date` < ?', $sql);
        $this->assertStringNotContainsString('date(`movement_date`)', $sql);

        // Alias start_date/end_date y fechas con hora (se toma el día).
        $this->assertSame(['hasta'], $this->listed('/api/auth/admin/cash-movements?start_date=2026-03-11T08:00:00&end_date=2026-03-11', 'concept'));
        $this->assertSame(['despues', 'hasta'], $this->listed('/api/auth/admin/cash-movements?date_from=2026-03-11', 'concept'));
        $this->assertSame(['antes'], $this->listed('/api/auth/admin/cash-movements?date_to=2026-03-09', 'concept'));
    }

    public function test_una_columna_datetime_incluye_todo_el_ultimo_dia_y_nada_del_siguiente(): void
    {
        $this->customer('2026-03-09 23:59:59', 'Antes');
        $this->customer('2026-03-10 00:00:00', 'Primero');
        $this->customer('2026-03-11 23:59:59', 'Ultimo');
        $this->customer('2026-03-12 00:00:00', 'Despues');

        $this->assertSame(['Primero', 'Ultimo'], $this->listed('/api/auth/admin/customers?date_from=2026-03-10&date_to=2026-03-11', 'name'));
    }

    public function test_un_valor_que_no_es_fecha_no_rompe_la_lista(): void
    {
        $this->movement('2026-03-10', 'desde');

        $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/cash-movements?date_from=ayer&date_to=2026-02-31')->assertOk();
    }
}
