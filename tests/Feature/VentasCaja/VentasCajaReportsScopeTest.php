<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\CashClosing;
use App\Models\Sale;
use App\Models\User;

/**
 * Estadísticas de ventas y PDFs de los cierres de caja: cada quien ve su
 * sucursal (otra solo con acceso a todas las sucursales) y las URLs de PDF
 * piden el mismo permiso que el detalle.
 */
class VentasCajaReportsScopeTest extends VentasCajaTestCase
{
    private function sale(Location $location, float $total): Sale
    {
        $sale = Sale::create([
            'company_id' => $location->company_id, 'location_id' => $location->id, 'user_id' => $this->user->id,
            'sale_date' => now()->toDateString(), 'status' => 'closed',
            'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total, 'paid_amount' => $total,
            'payment_method' => 'card', 'payment_status' => 'paid',
        ]);

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

    public function test_pdf_urls_need_the_read_permission_and_the_branch(): void
    {
        $otherBranch = $this->createBranch();
        $ownClosing = CashClosing::create(['company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id, 'closing_date' => now()->toDateString()]);
        $otherClosing = CashClosing::create(['company_id' => $this->company->id, 'location_id' => $otherBranch->id, 'user_id' => $this->user->id, 'closing_date' => now()->toDateString()]);

        $reader = $this->createBranchUser($this->location, ['cash_movements_read', 'cash_movements_list'])->fresh();
        $this->actingInBranch($reader)->getJson("/api/auth/admin/cash-closings/{$ownClosing->id}/pdf-url")->assertOk()->assertJsonStructure(['url', 'expires_at']);
        $this->actingInBranch($reader)->getJson("/api/auth/admin/cash-closings/{$otherClosing->id}/pdf-url")->assertNotFound();

        // Sin permiso de ver la caja no se emite la URL.
        $noCash = $this->createBranchUser($this->location, ['sales_read'])->fresh();
        $this->actingInBranch($noCash)->getJson("/api/auth/admin/cash-closings/{$ownClosing->id}/pdf-url")->assertForbidden();
    }
}
