<?php

namespace Tests\Feature\Flows;

use App\Enums\AdjustmentReasonCode;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\InventoryAdjustmentDetail;
use App\Models\Operations\Formula;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\PurchaseV2;
use App\Models\User;
use App\Reports\AdjustmentsReport;
use App\Reports\CashDifferencesReport;
use App\Reports\InventoryValuationReport;
use App\Reports\ProductCosts;
use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use App\Reports\SalesPeriodReport;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Reportes del dueño (ventas, inventario valorizado, mermas, diferencias de
 * caja) y la hoja de producción.
 */
class BusinessReportsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const CASHIER = ['sales_pos', 'sales_list', 'sales_read', 'products_list', 'products_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function scope(?Location $location = null): ReportScope
    {
        return new ReportScope($this->company, $location);
    }

    private function register(?Location $location = null): CashRegister
    {
        return CashRegister::create([
            'company_id' => $this->company->id,
            'location_id' => ($location ?? $this->location)->id,
            'name' => 'Caja ' . uniqid(),
            'code' => 'C' . uniqid(),
        ]);
    }

    /** Cajero con turno abierto; devuelve [cajero, turno]. */
    private function cashierWithShift(array $extra = []): array
    {
        $cashier = $this->createUserWithRole($this->location, array_merge(self::CASHIER, $extra));
        Sanctum::actingAs($cashier, ['*']);
        $sessionId = $this->withHeaders($this->tenantHeaders())->postJson('/api/auth/admin/cash-register-sessions/open', [
            'cash_register_id' => $this->register()->id, 'opening_amount' => 500,
        ])->assertCreated()->json('data.id');

        return [$cashier, $sessionId];
    }

    private function sell(array $details, array $payments): int
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/pos/sales', ['details' => $details, 'payments' => $payments])
            ->assertCreated()->json('data.id');
    }

    /** Compra recibida de 1; $quantityReceived 0 = no llegó, null = de antes de capturarlo. */
    private function receivedPurchase(Product $product, float $unitPrice, ?int $unitId = null, ?int $packageId = null, ?string $receivedAt = null, ?float $quantityReceived = 1.0): void
    {
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'purchase_number' => 'PUR-' . uniqid(),
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_RECEIVED,
            'total' => $unitPrice,
            'user_id' => $this->user->id,
        ]);
        DB::table('purchase_details')->insert([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'package_id' => $packageId,
            'unit_id' => $unitId ?? $product->unit_id,
            'quantity' => 1,
            'quantity_received' => $quantityReceived,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'received_at' => $receivedAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Ventas ──────────────────────────────────────────────────────────────

    public function test_sales_report_totals_compare_with_previous_period_and_exclude_cancelled(): void
    {
        $cocada = $this->createProduct(['name' => 'Cocada', 'sale_price' => 20], 100);
        $agua = $this->createProduct(['name' => 'Agua de coco', 'sale_price' => 30], 100);
        [$cashier] = $this->cashierWithShift(['sales_discount']);

        $this->sell([['product_id' => $cocada->id, 'quantity' => 3]], [['method' => 'cash', 'amount' => 60, 'tendered' => 100]]);
        $this->sell(
            [['product_id' => $agua->id, 'quantity' => 2, 'discount' => 10]],
            [['method' => 'card', 'amount' => 30], ['method' => 'cash', 'amount' => 20]],
        );
        $cancelled = $this->sell([['product_id' => $cocada->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 20]]);
        $lastMonth = $this->sell([['product_id' => $cocada->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 40]]);

        Sanctum::actingAs($this->user, ['*']);
        $this->withHeaders($this->tenantHeaders())->postJson("/api/auth/admin/sales/{$cancelled}/cancel")->assertOk();
        DB::table('sales')->where('id', $lastMonth)->update(['sale_date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);

        $period = ReportPeriod::between(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());
        $data = (new SalesPeriodReport($this->scope(), $period))->data();

        $this->assertEquals(110.0, $data['totals']['total']); // 60 + (60 − 10)
        $this->assertSame(2, $data['totals']['tickets']);
        $this->assertEquals(55.0, $data['totals']['average']);
        $this->assertEquals(10.0, $data['totals']['discount']);
        $this->assertSame(1, $data['totals']['discounted']);
        $this->assertSame(1, $data['totals']['cancelled_count']);
        $this->assertEquals(20.0, $data['totals']['cancelled_total']);

        // Mes completo: se compara contra el mes calendario anterior.
        $this->assertSame(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $data['previous']->fromDate());
        $this->assertEquals(40.0, $data['previous_totals']['total']);

        $methods = collect($data['payments'])->keyBy('method');
        $this->assertEquals(80.0, $methods['cash']['total']); // el cambio no es venta
        $this->assertEquals(30.0, $methods['card']['total']);

        $products = collect($data['products'])->keyBy('name');
        $this->assertEquals(3.0, $products['Cocada']['quantity']);
        $this->assertEquals(50.0, $products['Agua de coco']['total']);

        $this->assertSame($cashier->name, $data['cashiers'][0]['name']);
        $this->assertSame(1, $data['cashiers'][0]['cancelled_count']);
        $this->assertSame($this->user->name, $data['cancellations'][0]['cancelled_by']);
        $this->assertCount(1, $data['discounts']);
        $this->assertCount($period->days(), $data['by_day']);
    }

    public function test_period_validation(): void
    {
        $h = $this->tenantHeaders();
        $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/sales/url?date_from=2026-10-10&date_to=2026-10-01')
            ->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/sales/url?date_from=2025-01-01&date_to=2026-06-01')
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
        $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/nope/url')->assertNotFound();
    }

    // ─── Costos ──────────────────────────────────────────────────────────────

    public function test_costs_come_from_last_purchase_then_recipe_then_catalog(): void
    {
        // Comprado por caja de 12 a $120: $10 la pieza. Cuenta la compra más reciente.
        $tapa = $this->createProduct(['name' => 'Tapa', 'purchase_price' => 99]);
        $caja = DB::table('product_packages')->insertGetId([
            'company_id' => $this->company->id, 'product_id' => $tapa->id, 'package_name' => 'Caja 12',
            'barcode' => 'BX' . uniqid(), 'quantity_per_package' => 12, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->receivedPurchase($tapa, 60, null, $caja, now()->subDays(10)->toDateTimeString());
        $this->receivedPurchase($tapa, 120, null, $caja, now()->subDay()->toDateTimeString());

        // Comprado por kilo, el producto se lleva en gramos: $50 el kg = $0.05 el gramo.
        $azucar = $this->createProduct(['name' => 'Azúcar'], 0, 'Gramo');
        $this->receivedPurchase($azucar, 50, $this->unitId('Kilogramo'));

        // Coco: precio de catálogo, nunca comprado.
        $coco = $this->createProduct(['name' => 'Coco', 'purchase_price' => 8]);

        // Fórmula: 10 cocos + 500 g de azúcar dan 4 kg de pulpa ($105) y 2 L de agua.
        // La pulpa se vende a $60/kg y el agua a $30/L: la pulpa carga 240/300 del costo.
        $pulpa = $this->createProduct(['name' => 'Pulpa', 'purchase_price' => 0, 'sale_price' => 60], 0, 'Kilogramo');
        $agua = $this->createProduct(['name' => 'Agua', 'purchase_price' => 0, 'sale_price' => 30], 0, 'Litro');
        $formula = Formula::create(['company_id' => $this->company->id, 'name' => 'Proceso de coco', 'version' => 1, 'is_active' => true]);
        $formula->items()->create(['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'expected_quantity' => 10]);
        $formula->items()->create(['product_id' => $azucar->id, 'unit_id' => $this->unitId('Kilogramo'), 'expected_quantity' => 0.5]);
        $formula->outputs()->create(['product_id' => $pulpa->id, 'unit_id' => $pulpa->unit_id, 'expected_quantity' => 4, 'is_primary' => true]);
        $formula->outputs()->create(['product_id' => $agua->id, 'unit_id' => $agua->unit_id, 'expected_quantity' => 2, 'is_primary' => false]);

        // Terminado: 250 g de pulpa + una tapa por frasco.
        $frasco = $this->createProduct([
            'name' => 'Frasco de cajeta', 'purchase_price' => 0,
            'packaging_source_product_id' => $pulpa->id, 'packaging_source_quantity' => 250, 'packaging_source_unit_id' => $this->unitId('Gramo'),
        ]);
        DB::table('product_packaging_materials')->insert([
            'product_id' => $frasco->id, 'material_product_id' => $tapa->id, 'unit_id' => $tapa->unit_id, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sinCosto = $this->createProduct(['name' => 'Sin costo', 'purchase_price' => 0]);

        $costs = new ProductCosts($this->company->id);
        $this->assertEqualsWithDelta(10.0, $costs->cost($tapa->id), 0.0001);
        $this->assertSame(ProductCosts::SOURCE_PURCHASE, $costs->resolve($tapa->id)['source']);
        $this->assertEqualsWithDelta(0.05, $costs->cost($azucar->id), 0.0001);
        $this->assertSame(ProductCosts::SOURCE_CATALOG, $costs->resolve($coco->id)['source']);

        // Insumos de la tanda: 10 × 8 + 500 g × 0.05 = 105. Pulpa: 105 × 0.8 / 4 kg = 21 el kg.
        $this->assertEqualsWithDelta(21.0, $costs->cost($pulpa->id), 0.0001);
        $this->assertSame(ProductCosts::SOURCE_FORMULA, $costs->resolve($pulpa->id)['source']);
        $this->assertEqualsWithDelta(10.5, $costs->cost($agua->id), 0.0001); // 105 × 0.2 / 2 L

        // Frasco: 0.25 kg × 21 + 1 tapa × 10 = 15.25.
        $this->assertEqualsWithDelta(15.25, $costs->cost($frasco->id), 0.0001);
        $this->assertSame(ProductCosts::SOURCE_PACKAGING, $costs->resolve($frasco->id)['source']);

        $this->assertNull($costs->cost($sinCosto->id));

        // A una fecha de corte anterior a la última compra vale la compra de entonces.
        $before = new ProductCosts($this->company->id, now()->subDays(5));
        $this->assertEqualsWithDelta(5.0, $before->cost($tapa->id), 0.0001);
    }

    public function test_the_last_purchase_cost_only_takes_what_arrived(): void
    {
        $tapa = $this->createProduct(['name' => 'Tapa', 'purchase_price' => 3]);
        $this->receivedPurchase($tapa, 10, receivedAt: now()->subDays(3)->toDateTimeString());
        // La compra más reciente no trajo tapas: su precio nadie lo pagó.
        $this->receivedPurchase($tapa, 99, receivedAt: now()->subDay()->toDateTimeString(), quantityReceived: 0);

        $costs = new ProductCosts($this->company->id);
        $this->assertEqualsWithDelta(10.0, $costs->cost($tapa->id), 0.0001);
        $this->assertSame(ProductCosts::SOURCE_PURCHASE, $costs->resolve($tapa->id)['source']);

        // Una compra de antes de capturar lo recibido llegó completa: sí cuenta.
        $this->receivedPurchase($tapa, 12, receivedAt: now()->toDateTimeString(), quantityReceived: null);
        $this->assertEqualsWithDelta(12.0, (new ProductCosts($this->company->id))->cost($tapa->id), 0.0001);
    }

    // ─── Inventario valorizado ───────────────────────────────────────────────

    public function test_inventory_valuation_values_stock_and_flags_out_and_low(): void
    {
        $this->createProduct(['name' => 'Coco', 'purchase_price' => 8], 50);
        $azucar = $this->createProduct(['name' => 'Azúcar', 'purchase_price' => 20], 2);
        DB::table('product_location')->where('product_id', $azucar->id)->update(['minimum_stock' => 5]);
        $frascos = $this->createProduct(['name' => 'Frascos', 'purchase_price' => 3], 0);
        DB::table('product_location')->where('product_id', $frascos->id)->update(['minimum_stock' => 10]);
        $this->createProduct(['name' => 'Sin mínimo ni existencia'], 0);
        $this->createProduct(['name' => 'Sin costo', 'purchase_price' => 0], 4);

        $data = (new InventoryValuationReport($this->scope($this->location)))->data();
        $rows = collect($data['locations'][0]['rows'])->keyBy('name');

        $this->assertEquals(400.0, $rows['Coco']['value']);
        $this->assertSame(InventoryValuationReport::STATUS_LOW, $rows['Azúcar']['status']);
        $this->assertSame(InventoryValuationReport::STATUS_OUT, $rows['Frascos']['status']);
        $this->assertNull($rows['Sin costo']['value']);
        $this->assertFalse($rows->has('Sin mínimo ni existencia'));
        $this->assertEquals(440.0, $data['totals']['value']); // 50 × 8 + 2 × 20
        $this->assertSame(1, $data['totals']['out']);
        $this->assertSame(1, $data['totals']['low']);
        $this->assertSame(1, $data['totals']['without_cost']);
    }

    public function test_inventory_valuation_at_a_past_date_uses_the_movement_history(): void
    {
        $movements = app(MovementService::class);

        $this->travelTo(now()->subDays(15));
        $coco = $this->createProduct(['name' => 'Coco', 'purchase_price' => 8], 0);
        $this->travelBack();
        $this->travelTo(now()->subDays(10));
        $movements->increment($this->location->id, $coco->id, $coco->unit_id, 30);
        $this->travelBack();
        $this->travelTo(now()->subDays(3));
        $movements->decrement($this->location->id, $coco->id, $coco->unit_id, 12);
        $this->travelBack();

        $at = fn(string $date) => collect((new InventoryValuationReport($this->scope($this->location), $date))->data()['locations'][0]['rows'] ?? [])
            ->firstWhere('name', 'Coco')['stock'] ?? null;

        $this->assertEquals(30.0, $at(now()->subDays(5)->toDateString()));
        $this->assertEquals(18.0, $at(now()->toDateString()));
        $this->assertNull($at(now()->subDays(20)->toDateString())); // todavía no había
    }

    // ─── Mermas y ajustes ────────────────────────────────────────────────────

    public function test_adjustments_report_costs_losses_by_reason_product_and_user(): void
    {
        $azucar = $this->createProduct(['name' => 'Azúcar', 'purchase_price' => 20], 0, 'Kilogramo');
        $coco = $this->createProduct(['name' => 'Coco', 'purchase_price' => 8]);
        $adjust = fn(Product $p, string $direction, float $qty, string $unit, AdjustmentReasonCode $reason) => InventoryAdjustmentDetail::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'created_by' => $this->user->id,
            'product_id' => $p->id, 'direction' => $direction, 'quantity' => $qty, 'unit_id' => $this->unitId($unit),
            'reason_code' => $reason, 'applied_at' => now(),
        ]);

        $adjust($azucar, 'out', 500, 'Gramo', AdjustmentReasonCode::DAMAGE);   // 0.5 kg × 20 = 10
        $adjust($coco, 'out', 5, 'Pieza', AdjustmentReasonCode::EXPIRY);       // 40
        $adjust($coco, 'out', 1, 'Pieza', AdjustmentReasonCode::THEFT);        // 8
        $adjust($coco, 'in', 2, 'Pieza', AdjustmentReasonCode::FOUND);         // +16

        $period = ReportPeriod::between(now()->startOfMonth()->toDateString(), now()->toDateString());
        $data = (new AdjustmentsReport($this->scope(), $period))->data();

        $this->assertEqualsWithDelta(58.0, $data['totals']['loss_cost'], 0.001);
        $this->assertEqualsWithDelta(16.0, $data['totals']['gain_cost'], 0.001);
        $this->assertEqualsWithDelta(-42.0, $data['totals']['net'], 0.001);
        $this->assertSame('Caducidad', $data['by_reason'][0]['name']);
        $this->assertSame('Coco', $data['by_product'][0]['name']);
        $this->assertEquals(6.0, $data['by_product'][0]['quantity']);
        $this->assertEqualsWithDelta(0.5, collect($data['by_product'])->firstWhere('name', 'Azúcar')['quantity'], 0.0001);
        $this->assertSame($this->user->name, $data['by_user'][0]['name']);
    }

    // ─── Diferencias de caja ─────────────────────────────────────────────────

    public function test_cash_differences_flag_cashiers_short_repeatedly(): void
    {
        $register = $this->register();
        $ana = User::factory()->create(['name' => 'Ana']);
        $beto = User::factory()->create(['name' => 'Beto']);
        $shift = fn(User $u, float $difference) => CashRegisterSession::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'cash_register_id' => $register->id,
            'user_id' => $u->id, 'status' => CashRegisterSession::STATUS_CLOSED, 'opened_at' => now()->subHours(8),
            'opening_amount' => 500, 'closed_at' => now(), 'closed_by' => $u->id,
            'expected_cash' => 1000, 'counted_cash' => 1000 + $difference, 'difference' => $difference,
        ]);

        $shift($ana, -50);
        $shift($ana, -20);
        $shift($ana, 0);
        $shift($beto, 10);
        $shift($beto, 0);
        $shift($beto, 0);
        $shift($beto, -5);

        $period = ReportPeriod::between(now()->startOfMonth()->toDateString(), now()->toDateString());
        $data = (new CashDifferencesReport($this->scope(), $period))->data();
        $cashiers = collect($data['cashiers'])->keyBy('name');

        $this->assertSame('Ana', $data['cashiers'][0]['name']); // a quien más le falta, primero
        $this->assertTrue($cashiers['Ana']['flagged']);
        $this->assertEquals(-70.0, $cashiers['Ana']['short_total']);
        $this->assertEquals(-50.0, $cashiers['Ana']['worst_short']);
        $this->assertFalse($cashiers['Beto']['flagged']);
        $this->assertEquals(5.0, $cashiers['Beto']['net']);
        $this->assertSame(7, $data['totals']['sessions']);
        $this->assertSame(3, $data['totals']['exact']);
        $this->assertEquals(-65.0, $data['totals']['net']);
    }

    // ─── Endpoints, permisos y descarga ──────────────────────────────────────

    public function test_admin_downloads_every_report_as_pdf_and_excel(): void
    {
        $this->createProduct(['name' => 'Coco', 'purchase_price' => 8], 10);
        $h = $this->tenantHeaders();

        $options = $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/options')->assertOk();
        $this->assertCount(4, $options->json('reports'));
        $this->assertTrue($options->json('can_choose_location'));

        foreach (['sales', 'inventory-valuation', 'adjustments', 'cash-differences'] as $report) {
            $pdf = $this->get($this->withHeaders($h)->getJson("/api/auth/admin/business-reports/{$report}/url?format=pdf")->assertOk()->json('url'));
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent(), $report);

            $xlsx = $this->get($this->withHeaders($h)->getJson("/api/auth/admin/business-reports/{$report}/url?format=xlsx")->json('url'));
            $xlsx->assertOk();
            $this->assertStringStartsWith('PK', file_get_contents($xlsx->baseResponse->getFile()->getPathname()), $report);
        }
    }

    public function test_reports_need_permission_and_a_branch_user_only_gets_their_branch(): void
    {
        $h = $this->tenantHeaders();

        Sanctum::actingAs($this->createUserWithRole($this->location, self::CASHIER), ['*']);
        $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/options')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/auth/admin/business-reports/sales/url')->assertForbidden();

        // Un encargado con reportes pero sin acceso a todas las sucursales: aunque
        // pida otra o todas, el reporte sale de la suya.
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->createUserWithRole($this->location, ['reports_view']), ['*']);
        $this->assertFalse($this->withHeaders($h)->getJson('/api/auth/admin/business-reports/options')->json('can_choose_location'));
        foreach (['all', (string) $other->id] as $requested) {
            $url = $this->withHeaders($h)->getJson("/api/auth/admin/business-reports/sales/url?location_id={$requested}")->assertOk()->json('url');
            $this->assertStringContainsString("location_id={$this->location->id}", $url);
        }

        // El administrador elige; una sucursal de otra empresa no existe.
        Sanctum::actingAs($this->user, ['*']);
        $foreign = Location::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->withHeaders($h)->getJson("/api/auth/admin/business-reports/sales/url?location_id={$foreign->id}")->assertNotFound();
        $url = $this->withHeaders($h)->getJson("/api/auth/admin/business-reports/sales/url?location_id={$other->id}")->json('url');
        $this->assertStringContainsString("location_id={$other->id}", $url);
    }

    public function test_signed_report_urls_cannot_be_altered(): void
    {
        $url = $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/business-reports/sales/url')->json('url');
        $other = Company::factory()->create();

        $this->get(str_replace("company_id={$this->company->id}", "company_id={$other->id}", $url))->assertForbidden();
        $this->get(str_replace('format=pdf', 'format=xlsx', $url))->assertForbidden();
        $this->get(preg_replace('/&signature=[^&]+/', '', $url))->assertForbidden();
    }

    // ─── Hoja de producción ──────────────────────────────────────────────────

    private function draftOrder(Location $location, Product $input, float $quantity): ProductionOrder
    {
        $output = $this->createProduct(['name' => 'Pulpa de coco'], 0, 'Kilogramo');
        $order = ProductionOrder::create([
            'company_id' => $this->company->id, 'location_id' => $location->id, 'production_date' => now()->toDateString(),
            'responsible_user_id' => $this->user->id, 'status' => 'draft',
        ]);
        $order->consumptions()->create(['product_id' => $input->id, 'unit_id' => $input->unit_id, 'quantity' => $quantity, 'expected_quantity' => $quantity]);
        $order->outputs()->create(['product_id' => $output->id, 'unit_id' => $output->unit_id, 'quantity' => 4, 'expected_quantity' => 4]);

        return $order;
    }

    public function test_production_sheet_lists_what_to_take_and_flags_missing_stock(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 30);
        $order = $this->draftOrder($this->location, $coco, 40);

        $data = \App\Reports\ProductionSheet::load($this->company->id, $order->id)->data();
        $this->assertTrue($data['draft']);
        $this->assertTrue($data['consumptions'][0]['short']); // hay 30, se necesitan 40
        $this->assertSame(1, $data['short_count']);

        $h = $this->tenantHeaders();
        $url = $this->withHeaders($h)->getJson("/api/auth/admin/production-orders/{$order->id}/sheet-url")->assertOk()->json('url');
        $this->assertStringStartsWith('%PDF', $this->get($url)->assertOk()->getContent());

        // Completada: lo producido contra lo esperado.
        DB::table('product_location')->where('product_id', $coco->id)->update(['current_stock' => 100]);
        $this->withHeaders($h)->postJson("/api/auth/admin/production-orders/{$order->id}/complete")->assertOk();
        $data = \App\Reports\ProductionSheet::load($this->company->id, $order->id)->data();
        $this->assertTrue($data['closed']);
        $this->assertFalse($data['consumptions'][0]['short']);
        $this->assertStringStartsWith('%PDF', $this->get(
            $this->withHeaders($h)->getJson("/api/auth/admin/production-orders/{$order->id}/sheet-url")->json('url')
        )->assertOk()->getContent());
    }

    public function test_production_sheet_permissions_and_scoping(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 30);
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $order = $this->draftOrder($other, $coco, 10);

        // Sin permiso de producción.
        Sanctum::actingAs($this->createUserWithRole($this->location, self::CASHIER), ['*']);
        $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/production-orders/{$order->id}/sheet-url")->assertForbidden();

        // Encargado de otra sucursal sin acceso a todas: la orden no existe para él.
        Sanctum::actingAs($this->createUserWithRole($this->location, ['production_orders_read']), ['*']);
        $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/production-orders/{$order->id}/sheet-url")->assertNotFound();

        // Desde otra empresa tampoco (con una sucursal de esa empresa: la de
        // otra empresa ya se rechaza en la puerta con location_forbidden).
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $this->actingInBranch($this->createUserWithRole($foreignBranch), $foreignBranch)
            ->getJson("/api/auth/admin/production-orders/{$order->id}/sheet-url")->assertNotFound();
    }
}
