<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\StockLedgerReason;
use App\Services\MovementService;
use App\Support\StockContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * inventory:reconcile: cuadre nocturno del libro mayor contra la existencia
 * por producto y sucursal. Solo lee.
 */
class ReconcileInventoryTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->movements = app(MovementService::class);
    }

    public function test_reconcile_reports_stock_changed_outside_the_ledger_without_touching_it(): void
    {
        // Stock de antes del libro mayor: cuenta como existencia inicial.
        $coco = $this->createProduct(['name' => 'Coco entero'], 50);
        $agua = $this->createProduct(['name' => 'Agua de coco'], 0);
        $this->movements->decrement($this->location->id, $coco->id, $coco->unit_id, 5, null, StockContext::for(StockLedgerReason::SALE));
        $this->movements->increment($this->location->id, $agua->id, $agua->unit_id, 12, null, StockContext::for(StockLedgerReason::PRODUCTION_OUTPUT));

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('Inventario cuadrado: 2 producto(s)')
            ->assertExitCode(0);

        // Alguien cambia la existencia sin pasar por MovementService.
        DB::table('product_location')->where('product_id', $agua->id)->where('location_id', $this->location->id)->update(['current_stock' => 20]);
        Log::spy();

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('no cuadra')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) use ($agua) {
            return str_contains($message, 'no cuadra')
                && $context['differences'] === 1
                && $context['rows'][0]['product_id'] === $agua->id
                && $context['rows'][0]['expected_stock'] == 12
                && $context['rows'][0]['difference'] == 8;
        });

        // Solo lee: la existencia sigue como estaba.
        $this->assertEquals(20, $this->stockOf($agua));
        $this->assertEquals(45, $this->stockOf($coco));

        // Otra empresa no tiene diferencias.
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $this->artisan('inventory:reconcile', ['--company' => $otraEmpresa->id])->assertExitCode(0);
    }

    public function test_rounded_movements_reconcile_exactly(): void
    {
        $azucar = $this->createProduct([], 0, 'Kilogramo');
        $this->movements->increment($this->location->id, $azucar->id, $this->unitId('Gramo'), 123.4, null, StockContext::for(StockLedgerReason::PURCHASE));
        $this->movements->decrement($this->location->id, $azucar->id, $azucar->unit_id, 0.0006, null, StockContext::for(StockLedgerReason::SALE));

        $this->artisan('inventory:reconcile', ['--tolerance' => 0])->assertExitCode(0);
    }

    public function test_reconcile_runs_every_night(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('inventory:reconcile')
            ->assertExitCode(0);
    }
}
