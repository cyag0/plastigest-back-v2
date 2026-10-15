<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\InventoryType;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Services\Replenishment\ReplenishmentPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * "Qué pedir": las reversas no inflan la demanda ni inventan rutas, el
 * primer día de un producto se calcula sin recorrer todo el libro mayor y el
 * plan se reutiliza unos minutos hasta que cambian transferencias o compras.
 *
 * $this->location es la Matriz (surte); Ixtapa vende.
 */
class ComprasProduccionReplenishmentTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const NOW = '2026-10-15 08:00:00';

    private Location $ixtapa;
    private Location $pitillal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->createTenant();

        $this->location->update(['name' => 'Matriz']);
        $this->ixtapa = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Ixtapa']);
        $this->pitillal = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Pitillal']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW)->startOfDay();
    }

    private function ledger(Location $location, Product $product, string $reason, string $direction, float $quantity, CarbonImmutable $at, ?Location $counterpart = null, ?object $reference = null): void
    {
        DB::table('stock_ledger_entries')->insert([
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'direction' => $direction,
            'reason' => $reason,
            'quantity' => $quantity,
            'previous_stock' => 100,
            'new_stock' => 100,
            'counterpart_location_id' => $counterpart?->id,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function daily(Location $location, Product $product, float $quantity, int $days = 28): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $this->ledger($location, $product, 'sale', 'out', $quantity, $this->today()->subDays($i)->setTime(12, 0));
        }
    }

    private function product(string $name = 'Agua de coco 1 L'): Product
    {
        return $this->createProduct(['name' => $name], 0, 'Pieza', InventoryType::FINISHED_PRODUCT);
    }

    private function productionOrder(string $status): ProductionOrder
    {
        $id = DB::table('production_orders')->insertGetId([
            'company_id' => $this->company->id, 'location_id' => $this->ixtapa->id, 'folio' => 'PROD-' . uniqid(),
            'production_date' => $this->today()->toDateString(), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ProductionOrder::findOrFail($id);
    }

    private function transferRecord(Location $from, Location $to, string $status): InventoryTransfer
    {
        $transfer = InventoryTransfer::create([
            'company_id' => $this->company->id,
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'content' => InventoryTransfer::defaultWorkflowContent($this->user->id),
            'requested_by' => $this->user->id,
        ]);
        DB::table('inventory_transfers')->where('id', $transfer->id)->update(['status' => $status]);

        return $transfer->fresh();
    }

    private function plan(): array
    {
        return (new ReplenishmentPlanner($this->company->id, now(), 1))->plan();
    }

    /** @return array{0: array, 1: array}|null [grupo, renglón] del producto en los traspasos a $to */
    private function suggestion(array $plan, Location $to, Product $product): ?array
    {
        foreach ($plan['transfers'] as $group) {
            foreach ($group['items'] as $item) {
                if ($group['to']['id'] === $to->id && $item['product']['id'] === $product->id) {
                    return [$group, $item];
                }
            }
        }

        return null;
    }

    private function usuallySupplies(Location $from, Location $to, Product $product, int $daysAgo = 10): void
    {
        $at = $this->today()->subDays($daysAgo)->setTime(7, 0);
        $this->ledger($from, $product, 'transfer_out', 'out', 5, $at, $to);
        $this->ledger($to, $product, 'transfer_in', 'in', 5, $at->addHour(), $from);
    }

    public function test_cancelled_production_and_reverted_packaging_are_not_demand(): void
    {
        $agua = $this->product();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);
        $this->usuallySupplies($this->location, $this->ixtapa, $agua);

        // Cinco producciones que consumieron 40 y se cancelaron al día siguiente.
        for ($i = 2; $i <= 10; $i += 2) {
            $cancelada = $this->productionOrder('cancelled');
            $this->ledger($this->ixtapa, $agua, 'production_consumption', 'out', 40, $this->today()->subDays($i)->setTime(9, 0), null, $cancelada);
            $this->ledger($this->ixtapa, $agua, 'production_consumption', 'in', 40, $this->today()->subDays($i - 1)->setTime(9, 0), null, $cancelada);
        }
        // Un empaque que se revirtió el mismo día en una producción vigente.
        $vigente = $this->productionOrder('packaging');
        $this->ledger($this->ixtapa, $agua, 'packaging_consumption', 'out', 20, $this->today()->subDays(3)->setTime(10, 0), null, $vigente);
        $this->ledger($this->ixtapa, $agua, 'packaging_consumption', 'in', 20, $this->today()->subDays(3)->setTime(11, 0), null, $vigente);

        [, $item] = $this->suggestion($this->plan(), $this->ixtapa, $agua);

        // Antes: los 200 consumidos (y los 20 del empaque) contaban como demanda.
        $this->assertEquals(10, $item['destination']['daily_average']);
        $this->assertEquals(18, $item['quantity']);
    }

    public function test_stock_returned_by_a_cancelled_transfer_is_not_a_supply_route(): void
    {
        $agua = $this->product();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);
        $this->setStock($agua, $this->pitillal, 100);
        $this->usuallySupplies($this->location, $this->ixtapa, $agua);

        // Ixtapa le envió dos veces a Pitillal y las dos se cancelaron: la
        // mercancía regresó a Ixtapa "desde" Pitillal.
        foreach ([5, 8] as $daysAgo) {
            $cancelada = $this->transferRecord($this->ixtapa, $this->pitillal, 'cancelled');
            $at = $this->today()->subDays($daysAgo)->setTime(7, 0);
            $this->ledger($this->ixtapa, $agua, 'transfer_out', 'out', 3, $at, $this->pitillal, $cancelada);
            $this->ledger($this->ixtapa, $agua, 'transfer_in', 'in', 3, $at->addHours(2), $this->pitillal, $cancelada);
        }

        [$group] = $this->suggestion($this->plan(), $this->ixtapa, $agua);

        // Antes salía de Pitillal (dos "entradas desde Pitillal" contra una de la Matriz).
        $this->assertSame($this->location->id, $group['from']['id']);
    }

    public function test_a_product_new_in_the_history_window_keeps_its_own_first_day(): void
    {
        $nuevo = $this->product('Agua de coco 500 ml');
        $viejo = $this->product('Agua de coco 2 L');
        foreach ([$nuevo, $viejo] as $product) {
            $this->daily($this->ixtapa, $product, 10, 10);
            $this->setStock($product, $this->ixtapa, 0);
            $this->setStock($product, $this->location, 100);
        }
        // El viejo ya tenía movimientos hace 100 días (fuera del historial).
        $this->ledger($this->ixtapa, $viejo, 'transfer_in', 'in', 4, $this->today()->subDays(100)->setTime(9, 0), $this->location);

        $plan = $this->plan();

        // El nuevo empezó hace 10 días: su promedio es de esos días.
        $this->assertEquals(10, $this->suggestion($plan, $this->ixtapa, $nuevo)[1]['destination']['daily_average']);
        // Al viejo los días sin venta del historial sí le cuentan.
        $deViejo = $this->suggestion($plan, $this->ixtapa, $viejo);
        $this->assertTrue($deViejo === null || $deViejo[1]['destination']['daily_average'] < 10);
    }

    public function test_the_plan_is_reused_until_transfers_or_purchases_change(): void
    {
        $agua = $this->product();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);
        $this->usuallySupplies($this->location, $this->ixtapa, $agua);

        $quantity = function () use ($agua) {
            $transfers = $this->actingInBranch($this->user)->getJson('/api/auth/admin/replenishment?coverage_days=1')
                ->assertOk()
                ->json('data.transfers');
            foreach ($transfers as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['product']['id'] === $agua->id) {
                        return $item['quantity'];
                    }
                }
            }

            return null;
        };

        $this->assertEquals(18, $quantity());

        // Un cambio de existencia se refleja a los pocos minutos, no al instante.
        $this->setStock($agua, $this->ixtapa, 10);
        $this->assertEquals(18, $quantity());

        // Pedir el traspaso (o cualquier cambio de transferencias o compras)
        // recalcula: ya no se sugiere lo mismo dos veces.
        $pedido = InventoryTransfer::create([
            'company_id' => $this->company->id,
            'from_location_id' => $this->location->id,
            'to_location_id' => $this->ixtapa->id,
            'content' => InventoryTransfer::defaultWorkflowContent($this->user->id),
            'requested_by' => $this->user->id,
        ]);
        $pedido->details()->save(new InventoryTransferDetail([
            'product_id' => $agua->id, 'unit_id' => $agua->unit_id, 'quantity_requested' => 5,
        ]));

        // Faltan 20; tiene 10 y vienen 5.
        $this->assertEquals(5, $quantity());

        // Y pasados los minutos, también lo vendido o movido.
        Carbon::setTestNow(CarbonImmutable::parse(self::NOW)->addMinutes(6));
        $this->setStock($agua, $this->ixtapa, 12);
        $this->assertEquals(3, $quantity());
    }
}
