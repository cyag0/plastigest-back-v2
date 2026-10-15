<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\InventoryType;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Replenishment\ReplenishmentPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Sugerencias de reabastecimiento: qué mandar a cada sucursal y qué comprar
 * o producir, con el pronóstico de lo que se vende.
 *
 * $this->location es la Matriz (compra, produce y surte); Ixtapa y Pitillal
 * venden.
 */
class ReplenishmentTest extends TestCase
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

    // ─── Ayudas ─────────────────────────────────────────────────────────────

    private function ledger(Location $location, Product $product, string $reason, string $direction, float $quantity, CarbonImmutable $at, float $newStock = 100, ?Location $counterpart = null): void
    {
        DB::table('stock_ledger_entries')->insert([
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'direction' => $direction,
            'reason' => $reason,
            'quantity' => $quantity,
            'previous_stock' => $direction === 'out' ? $newStock + $quantity : $newStock - $quantity,
            'new_stock' => $newStock,
            'counterpart_location_id' => $counterpart?->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Salida diaria (venta o consumo) los últimos $days días, a mediodía. */
    private function daily(Location $location, Product $product, float $quantity, int $days = 28, string $reason = 'sale'): void
    {
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        for ($i = 1; $i <= $days; $i++) {
            $this->ledger($location, $product, $reason, 'out', $quantity, $today->subDays($i)->setTime(12, 0));
        }
    }

    /**
     * La Matriz le ha surtido ese producto a la sucursal (dentro del periodo
     * de venta: un movimiento anterior agregaría días sin venta al historial).
     */
    private function usuallySupplies(Location $from, Location $to, Product $product): void
    {
        $at = CarbonImmutable::parse(self::NOW)->subDays(10)->setTime(7, 0);
        $this->ledger($from, $product, 'transfer_out', 'out', 5, $at, 100, $to);
        $this->ledger($to, $product, 'transfer_in', 'in', 5, $at->addHour(), 100, $from);
    }

    private function agua(): Product
    {
        return $this->createProduct(['name' => 'Agua de coco 1 L'], 0, 'Pieza', InventoryType::FINISHED_PRODUCT);
    }

    private function plan(int $coverageDays = 1): array
    {
        return (new ReplenishmentPlanner($this->company->id, now(), $coverageDays))->plan();
    }

    private function transfer(array $plan, Location $from, Location $to): ?array
    {
        foreach ($plan['transfers'] as $group) {
            if ($group['from']['id'] === $from->id && $group['to']['id'] === $to->id) {
                return $group;
            }
        }

        return null;
    }

    // ─── Traspasos ──────────────────────────────────────────────────────────

    public function test_a_branch_gets_what_it_will_sell_until_tomorrow_from_its_usual_supplier(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);          // vende 10 diarios, parejo
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);
        $this->usuallySupplies($this->location, $this->ixtapa, $agua);

        $group = $this->transfer($this->plan(), $this->location, $this->ixtapa);

        // Falta vender hoy 10 + mañana 10 = 20; tiene 2: se piden 18. Sin
        // variación no hay margen.
        $this->assertNotNull($group);
        $this->assertCount(1, $group['items']);
        $item = $group['items'][0];
        $this->assertSame($agua->id, $item['product']['id']);
        $this->assertEquals(18, $item['quantity']);
        $this->assertEquals(20, $item['destination']['target']);
        $this->assertEquals(2, $item['destination']['stock']);
        $this->assertEquals(10, $item['destination']['daily_average']);
    }

    public function test_what_was_sold_today_and_what_is_on_the_way_are_discounted(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);
        $this->usuallySupplies($this->location, $this->ixtapa, $agua);

        // Hoy ya vendió 6 de los 10, y ya pidió 5 a la Matriz.
        $this->ledger($this->ixtapa, $agua, 'sale', 'out', 6, CarbonImmutable::parse(self::NOW)->subHour(), 2);
        $transfer = InventoryTransfer::create([
            'company_id' => $this->company->id,
            'from_location_id' => $this->location->id,
            'to_location_id' => $this->ixtapa->id,
            'content' => InventoryTransfer::defaultWorkflowContent($this->user->id),
            'requested_by' => $this->user->id,
        ]);
        $transfer->details()->save(new InventoryTransferDetail([
            'product_id' => $agua->id, 'unit_id' => $agua->unit_id, 'quantity_requested' => 5,
        ]));

        $item = $this->transfer($this->plan(), $this->location, $this->ixtapa)['items'][0];

        // Falta de hoy 4 + mañana 10 = 14; tiene 2 y vienen 5: faltan 7.
        $this->assertEquals(7, $item['quantity']);
        $this->assertEquals(6, $item['destination']['sold_today']);
        $this->assertEquals(5, $item['destination']['incoming']);
        // Lo que la Matriz ya se comprometió a mandar no cuenta como sobrante.
        $this->assertEquals(5, $item['source']['committed']);
    }

    public function test_days_the_branch_ran_out_do_not_lower_the_suggestion(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        // Los últimos 3 días se agotó: vendió solo 4 y quedó en cero.
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        DB::table('stock_ledger_entries')->where('location_id', $this->ixtapa->id)
            ->where('created_at', '>=', $today->subDays(3))->delete();
        for ($i = 1; $i <= 3; $i++) {
            $this->ledger($this->ixtapa, $agua, 'sale', 'out', 4, $today->subDays($i)->setTime(12, 0), 0);
        }
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);

        $item = $this->transfer($this->plan(), $this->location, $this->ixtapa)['items'][0];

        $this->assertEquals(18, $item['quantity'], 'los días agotados no cuentan');
        $this->assertSame(3, $item['destination']['stockout_days']);
        $this->assertSame(3, $item['destination']['stockout_streak'], 'lleva 3 días seguidos agotado');
    }

    public function test_a_branch_with_spare_stock_can_supply_another_when_the_hub_has_none(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->location, 0);
        // Pitillal no lo vende y tiene 30.
        $this->setStock($agua, $this->pitillal, 30);

        $plan = $this->plan();

        $this->assertNull($this->transfer($plan, $this->location, $this->ixtapa));
        $this->assertEquals(20, $this->transfer($plan, $this->pitillal, $this->ixtapa)['items'][0]['quantity']);
    }

    public function test_nothing_is_suggested_when_the_branch_has_enough(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 40);
        $this->setStock($agua, $this->location, 100);

        $plan = $this->plan();

        $this->assertSame([], $plan['transfers']);
        $this->assertSame([], $plan['purchases']);
        $this->assertSame([], $plan['production']);
    }

    public function test_more_coverage_days_ask_for_more(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 2);
        $this->setStock($agua, $this->location, 100);

        $plan = $this->plan(3);

        // Hoy 10 + 3 días × 10 = 40; tiene 2.
        $this->assertEquals(38, $this->transfer($plan, $this->location, $this->ixtapa)['items'][0]['quantity']);
        $this->assertSame(['from' => '2026-10-16', 'to' => '2026-10-18'], $plan['horizon']);
    }

    public function test_products_sold_by_weight_are_suggested_in_tenths(): void
    {
        $pulpa = $this->createProduct(['name' => 'Pulpa de coco'], 0, 'Kilogramo', InventoryType::FINISHED_PRODUCT);
        $this->daily($this->ixtapa, $pulpa, 1.25);
        $this->setStock($pulpa, $this->ixtapa, 0.5);
        $this->setStock($pulpa, $this->location, 20);

        $item = $this->transfer($this->plan(), $this->location, $this->ixtapa)['items'][0];

        // 1.25 + 1.25 − 0.5 = 2.0
        $this->assertEqualsWithDelta(2.0, $item['quantity'], 0.001);
    }

    public function test_products_that_almost_never_sell_are_not_suggested(): void
    {
        $agua = $this->agua();
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        // En Ixtapa desde hace 50 días, y solo una venta (de 4) hace 20.
        $this->ledger($this->ixtapa, $agua, 'transfer_in', 'in', 4, $today->subDays(50)->setTime(9, 0), 4, $this->location);
        $this->ledger($this->ixtapa, $agua, 'sale', 'out', 4, $today->subDays(20)->setTime(12, 0), 0);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->location, 100);

        $this->assertNull($this->transfer($this->plan(), $this->location, $this->ixtapa));
    }

    public function test_the_margin_never_exceeds_what_is_expected_to_sell(): void
    {
        $agua = $this->agua();
        // Venta muy dispareja: 18 cada 9 días (2 diarios en promedio).
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        for ($i = 1; $i <= 56; $i++) {
            if ($i % 9 === 0) {
                $this->ledger($this->ixtapa, $agua, 'sale', 'out', 18, $today->subDays($i)->setTime(12, 0));
            }
        }
        $this->ledger($this->ixtapa, $agua, 'transfer_in', 'in', 18, $today->subDays(56)->setTime(8, 0), 100, $this->location);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->location, 100);

        $destination = $this->transfer($this->plan(), $this->location, $this->ixtapa)['items'][0]['destination'];

        // La variación da un margen mayor que la venta esperada: se topa.
        $expected = $destination['rest_of_today'] + $destination['forecast'];
        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, $destination['safety'], 0.01);
    }

    // ─── Compras y producción ───────────────────────────────────────────────

    public function test_what_the_hub_lacks_is_suggested_as_a_purchase_from_its_supplier(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerta del Sol']);
        $coco = $this->createProduct(
            ['name' => 'Coco entero', 'supplier_id' => $supplier->id, 'purchase_price' => 12],
            10,
            'Pieza',
            InventoryType::RAW_MATERIAL,
        );
        // La Matriz usa 30 cocos diarios en producción y es donde se compran.
        $this->daily($this->location, $coco, 30, 28, 'production_consumption');
        $this->ledger($this->location, $coco, 'purchase', 'in', 200, CarbonImmutable::parse(self::NOW)->subDays(20));

        $plan = $this->plan();

        $this->assertCount(1, $plan['purchases']);
        $purchase = $plan['purchases'][0];
        $this->assertSame($this->location->id, $purchase['location']['id']);
        $this->assertSame('Huerta del Sol', $purchase['supplier']['name']);
        // Hoy 30 + mañana 30 − 10 en existencia = 50, a $12.
        $this->assertEquals(50, $purchase['items'][0]['quantity']);
        $this->assertEquals(12, $purchase['items'][0]['unit_cost']);
        $this->assertEquals(600, $purchase['total']);
    }

    public function test_finished_products_the_network_lacks_are_suggested_to_produce_at_the_hub(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->location, 0);
        // La Matriz es donde se produce.
        $this->ledger($this->location, $agua, 'production_output', 'in', 50, CarbonImmutable::parse(self::NOW)->subDays(10));

        $plan = $this->plan();

        $this->assertSame([], $plan['transfers']);
        $this->assertCount(1, $plan['production']);
        $production = $plan['production'][0];
        $this->assertSame($this->location->id, $production['location']['id']);
        $this->assertEquals(20, $production['items'][0]['quantity']);
        $this->assertSame($this->ixtapa->id, $production['items'][0]['needs'][0]['location']['id']);
    }

    public function test_the_suggested_purchase_can_be_saved_as_a_draft_without_payment_method(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerta del Sol']);
        $coco = $this->createProduct(['name' => 'Coco entero', 'supplier_id' => $supplier->id], 0, 'Pieza', InventoryType::RAW_MATERIAL);

        // Lo que manda la pantalla de sugerencias: proveedor y renglones, sin
        // forma de pago (se elige al confirmar la compra).
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/purchases-v2/upsert-draft', [
                'supplier_id' => $supplier->id,
                'notes' => 'Sugerida por reabastecimiento',
                'items' => [['id' => "product_{$coco->id}", 'product_id' => $coco->id, 'quantity' => 50, 'unit_id' => $coco->unit_id, 'price' => 12]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('purchases', [
            'location_id' => $this->location->id, 'supplier_id' => $supplier->id, 'status' => 'draft',
        ]);
        $this->assertDatabaseHas('purchase_details', ['product_id' => $coco->id, 'quantity' => 50]);
    }

    // ─── Temporada ──────────────────────────────────────────────────────────

    public function test_last_years_season_raises_the_forecast(): void
    {
        $agua = $this->agua();
        // Vende desde hace un año: todo el historial tiene venta.
        $this->daily($this->ixtapa, $agua, 10, 56);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->location, 100);

        // El año pasado: 10 diarios las semanas previas y 30 diarios en estas
        // fechas (temporada alta). El factor tope es 2.
        $lastYear = CarbonImmutable::parse(self::NOW)->startOfDay()->subYear();
        for ($i = 1; $i <= 28; $i++) {
            $this->ledger($this->ixtapa, $agua, 'sale', 'out', 10, $lastYear->subDays($i)->setTime(12, 0));
        }
        for ($i = -6; $i <= 8; $i++) {
            $this->ledger($this->ixtapa, $agua, 'sale', 'out', 30, $lastYear->addDays($i)->setTime(13, 0));
        }

        $item = $this->transfer($this->plan(), $this->location, $this->ixtapa)['items'][0];

        $this->assertEquals(2.0, $item['destination']['season_factor']);
        // Hoy 20 + mañana 20.
        $this->assertEquals(40, $item['quantity']);
    }

    // ─── Endpoint ───────────────────────────────────────────────────────────

    public function test_the_endpoint_requires_transfers_list(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->location, ['sales_pos']), ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/replenishment')
            ->assertForbidden();
    }

    public function test_a_branch_only_manager_sees_what_concerns_their_branch(): void
    {
        $agua = $this->agua();
        $this->daily($this->ixtapa, $agua, 10);
        $this->daily($this->pitillal, $agua, 10);
        $this->setStock($agua, $this->ixtapa, 0);
        $this->setStock($agua, $this->pitillal, 0);
        $this->setStock($agua, $this->location, 100);

        // El administrador ve los dos traspasos.
        $all = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/replenishment?coverage_days=1')
            ->assertOk()
            ->json('data.transfers');
        $this->assertCount(2, $all);

        // El encargado de Pitillal solo el suyo.
        Sanctum::actingAs($this->createUserWithRole($this->pitillal, ['transfers_list', 'transfers_create']), ['*']);
        $mine = $this->withHeaders($this->tenantHeaders($this->pitillal))
            ->getJson('/api/auth/admin/replenishment')
            ->assertOk()
            ->json('data.transfers');

        $this->assertCount(1, $mine);
        $this->assertSame($this->pitillal->id, $mine[0]['to']['id']);
    }
}
