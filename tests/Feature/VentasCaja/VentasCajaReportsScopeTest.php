<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\CashClosing;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalesReport;
use App\Models\User;

/**
 * Estadísticas, corte por fecha y PDFs de ventas y caja: cada quien ve su
 * sucursal (otra solo con acceso a todas las sucursales), las URLs de PDF
 * piden el mismo permiso que el detalle, y el PDF del reporte de ventas ya
 * se genera (antes consultaba una columna que no existe).
 */
class VentasCajaReportsScopeTest extends VentasCajaTestCase
{
    private function sale(Location $location, float $total, ?string $date = null, string $status = 'closed', ?array $line = null): Sale
    {
        $sale = Sale::create([
            'company_id' => $location->company_id, 'location_id' => $location->id, 'user_id' => $this->user->id,
            'sale_date' => $date ?? now()->toDateString(), 'status' => $status,
            'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total, 'paid_amount' => $total,
            'payment_method' => 'card', 'payment_status' => 'paid',
        ]);

        if ($line) {
            SaleDetail::create(['sale_id' => $sale->id, 'tax' => 0, 'discount' => 0] + $line);
        }

        $sale->payments()->create(['method' => 'card', 'amount' => $total]);

        return $sale;
    }

    private function salesReader(array $extra = []): User
    {
        return $this->createBranchUser($this->location, array_merge(['sales_read', 'sales_list'], $extra))->fresh();
    }

    public function test_sales_stats_stay_in_the_branch_of_a_branch_user(): void
    {
        $otherBranch = $this->createBranch();
        $this->sale($this->location, 100);
        $this->sale($otherBranch, 700);
        $reader = $this->salesReader();

        // Pedir otra sucursal no cambia nada: siguen siendo las de la suya.
        $this->actingInBranch($reader)->getJson("/api/auth/admin/sales/stats?location_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 100);

        // Con acceso a todas las sucursales: la pedida, o todas.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales/stats?location_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 700);
        $this->actingInBranch($this->user)->getJson('/api/auth/admin/sales/stats?location_id=all')
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 800);
        // Sin parámetro: la sucursal actual, como antes.
        $this->actingInBranch($this->user)->getJson('/api/auth/admin/sales/stats')
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 100);
    }

    public function test_the_cash_cut_stays_in_the_branch_of_a_branch_user(): void
    {
        $otherBranch = $this->createBranch();
        $this->sale($this->location, 100);
        $this->sale($otherBranch, 700);
        $reader = $this->salesReader();
        $today = now()->toDateString();

        $this->actingInBranch($reader)->getJson("/api/auth/admin/sales/cash-register?date={$today}&location_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->location->id)
            ->assertJsonPath('data.summary.total_sales', 100);

        $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales/cash-register?date={$today}&location_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_sales', 700);

        // Sin fecha: 422 (antes salía un 500).
        $this->actingInBranch($reader)->getJson('/api/auth/admin/sales/cash-register')->assertStatus(422);
    }

    public function test_pdf_urls_need_the_read_permission_and_the_branch(): void
    {
        $otherBranch = $this->createBranch();
        $ownReport = SalesReport::create(['company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id, 'report_date' => now()->toDateString()]);
        $otherReport = SalesReport::create(['company_id' => $this->company->id, 'location_id' => $otherBranch->id, 'user_id' => $this->user->id, 'report_date' => now()->toDateString()]);
        $ownClosing = CashClosing::create(['company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id, 'closing_date' => now()->toDateString()]);
        $otherClosing = CashClosing::create(['company_id' => $this->company->id, 'location_id' => $otherBranch->id, 'user_id' => $this->user->id, 'closing_date' => now()->toDateString()]);

        $reader = $this->createBranchUser($this->location, ['reports_read', 'reports_list', 'cash_movements_read', 'cash_movements_list'])->fresh();
        $this->actingInBranch($reader)->getJson("/api/auth/admin/sales-reports/{$ownReport->id}/pdf-url")->assertOk()->assertJsonStructure(['url', 'expires_at']);
        $this->actingInBranch($reader)->getJson("/api/auth/admin/sales-reports/{$otherReport->id}/pdf-url")->assertNotFound();
        $this->actingInBranch($reader)->getJson("/api/auth/admin/cash-closings/{$ownClosing->id}/pdf-url")->assertOk();
        $this->actingInBranch($reader)->getJson("/api/auth/admin/cash-closings/{$otherClosing->id}/pdf-url")->assertNotFound();

        // Sin permiso de ver reportes ya no se emite la URL.
        $noReports = $this->createBranchUser($this->location, ['sales_read'])->fresh();
        $this->actingInBranch($noReports)->getJson("/api/auth/admin/sales-reports/{$ownReport->id}/pdf-url")->assertForbidden();
    }

    public function test_the_sales_report_pdf_is_generated_with_the_days_sales(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $line = ['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity' => 2, 'unit_price' => 30, 'subtotal' => 60, 'total' => 60];
        $this->sale($this->location, 60, now()->toDateString(), 'closed', $line);
        $this->sale($this->location, 60, now()->toDateString(), 'cancelled', $line);
        $report = SalesReport::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'report_date' => now()->toDateString(), 'total_sales' => 60, 'transactions_count' => 1,
        ]);

        $url = $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales-reports/{$report->id}/pdf-url")->assertOk()->json('url');

        // La ruta firmada no lleva sesión ni sucursal (como al abrirla en el navegador).
        $this->flushHeaders();
        $response = $this->get($url);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
