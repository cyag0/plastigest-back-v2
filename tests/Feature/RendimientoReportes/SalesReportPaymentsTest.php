<?php

namespace Tests\Feature\RendimientoReportes;

use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use App\Reports\SalesPeriodReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Reporte de ventas: lo cobrado por método cuenta lo pagado al vender el día
 * de la venta y cada abono el día en que se registró.
 */
class SalesReportPaymentsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function monthOf(\Carbon\CarbonInterface $day): ReportPeriod
    {
        return ReportPeriod::between($day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString());
    }

    public function test_un_abono_cuenta_el_dia_en_que_se_registra_y_no_el_de_la_venta(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 100], 10);

        // Venta con anticipo de 40 con tarjeta; queda debiendo 60.
        $saleId = $this->withHeaders($this->tenantHeaders())->postJson('/api/auth/admin/sales', [
            'payment_method' => 'card',
            'paid_amount' => 40,
            'details' => [['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');

        // La venta (y su cobro) fue el mes pasado.
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(9)->setTime(12, 0);
        DB::table('sales')->where('id', $saleId)->update(['sale_date' => $lastMonth->toDateString(), 'created_at' => $lastMonth]);
        DB::table('cash_movements')->where('source_type', 'sale')->where('source_id', $saleId)
            ->update(['movement_date' => $lastMonth->toDateString(), 'created_at' => $lastMonth]);

        // Hoy paga el resto por transferencia.
        $this->withHeaders($this->tenantHeaders())->postJson("/api/auth/admin/sales/{$saleId}/add-payment", [
            'amount' => 60, 'payment_method' => 'transfer',
        ])->assertSuccessful();

        $scope = new ReportScope($this->company, null);

        $thisMonth = (new SalesPeriodReport($scope, $this->monthOf(now())))->data();
        $methods = collect($thisMonth['payments'])->keyBy('method');
        $this->assertEquals(60.0, $methods['transfer']['total'] ?? null, 'el abono cuenta este mes');
        $this->assertArrayNotHasKey('card', $methods->all(), 'el anticipo es del mes de la venta');
        $this->assertEquals(60.0, $thisMonth['abonos']);
        $this->assertEquals(0.0, $thisMonth['totals']['total'], 'la venta no es de este mes');

        $previous = (new SalesPeriodReport($scope, $this->monthOf($lastMonth)))->data();
        $methods = collect($previous['payments'])->keyBy('method');
        $this->assertEquals(40.0, $methods['card']['total'] ?? null);
        $this->assertArrayNotHasKey('transfer', $methods->all(), 'el abono no se mueve al mes de la venta');
        $this->assertEquals(0.0, $previous['abonos']);
        $this->assertEquals(100.0, $previous['totals']['total']);
    }
}
