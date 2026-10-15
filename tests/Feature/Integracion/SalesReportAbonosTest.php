<?php

namespace Tests\Feature\Integracion;

use App\Models\CashMovement;
use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use App\Reports\SalesPeriodReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * Reporte de ventas con los abonos que ahora registra ventas y caja: un abono
 * cobrado otro día cuenta una sola vez y el día en que entró el dinero, tanto
 * con los renglones nuevos (movimiento de caja fechado el día del abono y su
 * renglón en sale_payments) como con los anteriores (movimiento fechado el día
 * de la venta y sin renglón en sale_payments).
 */
class SalesReportAbonosTest extends VentasCajaTestCase
{
    private const SALE_DAY = '2026-03-10';
    private const MIDDLE_DAY = '2026-03-11';
    private const ABONO_DAY = '2026-03-12';

    /**
     * Venta de 100 con anticipo de 40 en efectivo el día 10 y abono de 60 en
     * efectivo el día 12, en el turno (todavía abierto) de la misma cajera.
     */
    private function saleWithLaterAbono(): int
    {
        $this->travelTo(Carbon::parse(self::SALE_DAY . ' 11:00:00'));
        $coco = $this->createProduct(['sale_price' => 100], 10);
        $cashier = $this->cashier(['sales_create', 'sales_update']);

        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'cash',
            'paid_amount' => 40,
            'details' => [$this->legacyLine($coco, 1)],
        ])->assertCreated()->json('data.id');

        $this->travelTo(Carbon::parse(self::ABONO_DAY . ' 17:30:00'));
        $this->actingInBranch($cashier)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 60, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        return $saleId;
    }

    private function report(string $from, string $to): array
    {
        return (new SalesPeriodReport(new ReportScope($this->company, null), ReportPeriod::between($from, $to)))->data();
    }

    private function cash(array $report): ?array
    {
        return collect($report['payments'])->firstWhere('method', 'cash');
    }

    private function assertAbonoCountedOnceOnItsDay(): void
    {
        $saleDay = $this->report(self::SALE_DAY, self::SALE_DAY);
        $this->assertEquals(40.0, $this->cash($saleDay)['total'] ?? null, 'el día de la venta solo cuenta el anticipo');
        $this->assertEquals(0.0, $saleDay['abonos']);
        $this->assertEquals(100.0, $saleDay['totals']['total']);

        $middle = $this->report(self::MIDDLE_DAY, self::MIDDLE_DAY);
        $this->assertNull($this->cash($middle), 'ese día no entró dinero');
        $this->assertEquals(0.0, $middle['abonos']);

        $abonoDay = $this->report(self::ABONO_DAY, self::ABONO_DAY);
        $this->assertEquals(60.0, $this->cash($abonoDay)['total'] ?? null, 'el abono cuenta el día en que entró el dinero');
        $this->assertEquals(60.0, $abonoDay['abonos']);
        $this->assertEquals(0.0, $abonoDay['totals']['total'], 'la venta no es de ese día');

        $both = $this->report(self::SALE_DAY, self::ABONO_DAY);
        $this->assertEquals(100.0, $this->cash($both)['total'] ?? null, 'anticipo y abono, cada uno una vez');
        $this->assertSame(1, $this->cash($both)['count'], 'es una sola venta');
        $this->assertEquals(60.0, $both['abonos']);
        $this->assertEquals(100.0, $both['totals']['total']);
    }

    public function test_un_abono_de_otro_dia_cuenta_una_vez_y_el_dia_del_abono(): void
    {
        $saleId = $this->saleWithLaterAbono();

        // Forma nueva: el ingreso del abono lleva el día del abono y el abono
        // tiene su renglón en sale_payments (que el reporte no vuelve a sumar).
        $abono = CashMovement::where('source_type', 'sale')->where('source_id', $saleId)
            ->where('amount', 60)->firstOrFail();
        $this->assertSame(self::ABONO_DAY, $abono->movement_date->toDateString());
        $this->assertSame(self::ABONO_DAY, $abono->created_at->toDateString());
        $this->assertSame(2, CashMovement::where('source_type', 'sale')->where('source_id', $saleId)->count());
        $this->assertSame(1, DB::table('sale_payments')->where('sale_id', $saleId)->where('amount', 60)
            ->whereDate('created_at', self::ABONO_DAY)->count());

        $this->assertAbonoCountedOnceOnItsDay();
    }

    public function test_un_abono_registrado_antes_del_cambio_tambien_cuenta_el_dia_del_abono(): void
    {
        $saleId = $this->saleWithLaterAbono();

        // Forma anterior: el ingreso del abono quedaba fechado el día de la
        // venta, con el concepto del cobro inicial, y sin renglón en
        // sale_payments. Su created_at sigue siendo el momento del abono.
        $abono = CashMovement::where('source_type', 'sale')->where('source_id', $saleId)
            ->where('amount', 60)->firstOrFail();
        DB::table('cash_movements')->where('id', $abono->id)->update([
            'movement_date' => self::SALE_DAY,
            'concept' => 'Venta #' . $saleId,
        ]);
        DB::table('sale_payments')->where('sale_id', $saleId)->where('amount', 60)->delete();

        $this->assertAbonoCountedOnceOnItsDay();
    }
}
