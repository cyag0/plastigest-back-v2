<?php

namespace Tests\Feature\RendimientoReportes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Permisos y sucursal de los reportes.
 */
class ReportPermissionsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    public function test_las_estadisticas_de_transferencias_piden_reports_view(): void
    {
        $headers = $this->tenantHeaders();

        Sanctum::actingAs($this->createUserWithRole($this->location, ['transfers_list', 'transfers_read']), ['*']);
        $this->withHeaders($headers)->getJson('/api/auth/admin/reports/transfer-stats')->assertForbidden();

        Sanctum::actingAs($this->createUserWithRole($this->location, ['reports_view']), ['*']);
        $this->withHeaders($headers)->getJson('/api/auth/admin/reports/transfer-stats')->assertOk();
    }

    public function test_quien_no_ve_todas_las_sucursales_y_no_tiene_sucursal_no_saca_el_reporte_de_todas(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->location, ['reports_view']), ['*']);

        // Antes salía el reporte de todas las sucursales (location_id=all).
        $this->withHeaders($this->contextHeaders($this->company, null))
            ->getJson('/api/auth/admin/business-reports/sales/url')
            ->assertForbidden();
    }
}
