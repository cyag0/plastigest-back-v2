<?php

namespace Tests\Feature\RendimientoReportes;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Reports\CashDifferencesReport;
use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El PDF de diferencias de caja lista los turnos con mayor diferencia (con
 * tope) y remite al Excel para la lista completa.
 */
class CashDifferencesPdfTest extends TestCase
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

    public function test_el_pdf_de_diferencias_de_caja_lista_las_mayores_y_remite_al_excel(): void
    {
        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'name' => 'Caja 1', 'code' => 'C1',
        ]);
        $cashier = User::factory()->create(['name' => 'Ana']);
        $total = CashDifferencesReport::PDF_LIST_LIMIT + 30;
        for ($i = 1; $i <= $total; $i++) {
            // Diferencias de -1 a -180 (y una cuadrada que no se lista).
            $difference = $i === $total ? 0 : -1 * $i;
            CashRegisterSession::create([
                'company_id' => $this->company->id, 'location_id' => $this->location->id, 'cash_register_id' => $register->id,
                'user_id' => $cashier->id, 'status' => CashRegisterSession::STATUS_CLOSED, 'opened_at' => now()->subHours(8),
                'opening_amount' => 500, 'closed_at' => now()->subMinutes($total - $i), 'closed_by' => $cashier->id,
                'expected_cash' => 1000, 'counted_cash' => 1000 + $difference, 'difference' => $difference,
            ]);
        }

        $report = new CashDifferencesReport(new ReportScope($this->company, null), $this->monthOf(now()));
        $data = $report->data();

        $this->assertSame($total - 1, $data['with_difference_count']);
        $this->assertCount(CashDifferencesReport::PDF_LIST_LIMIT, $data['listed_differences']);
        // Los de mayor diferencia primero; los chicos quedan solo en el Excel.
        $this->assertEquals(-1 * ($total - 1), $data['listed_differences'][0]['difference']);
        $this->assertEquals(-1 * ($total - CashDifferencesReport::PDF_LIST_LIMIT), end($data['listed_differences'])['difference']);
        $this->assertSame($total, $data['totals']['sessions']);

        $html = view($report->view(), $data + ['company' => $this->company, 'document' => $report])->render();
        $this->assertStringContainsString('Se muestran los ' . CashDifferencesReport::PDF_LIST_LIMIT . ' turnos con mayor diferencia', $html);
        $this->assertStringContainsString('la lista completa está en el Excel', $html);

        // El Excel sí trae todos los turnos.
        $sheetRows = collect($report->sheets())->sum(fn($sheet) => count($sheet['rows'] ?? []));
        $this->assertGreaterThanOrEqual($total, $sheetRows);
    }
}
