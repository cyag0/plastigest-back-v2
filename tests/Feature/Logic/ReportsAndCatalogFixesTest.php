<?php

namespace Tests\Feature\Logic;

use App\Models\Admin\Location;
use App\Models\InventoryCount;
use App\Models\InventoryCountDetail;
use App\Models\PurchaseV2;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Dashboard, unidades, conteos y listado de transferencias.
 */
class ReportsAndCatalogFixesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Releer para traer is_active (default de la columna): el middleware
        // rechaza cuentas inactivas.
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
    }

    // ─── Dashboard ───────────────────────────────────────────────────────────

    private function sale(float $total, string $status, int $productId): Sale
    {
        $sale = Sale::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'sale_date' => now()->toDateString(), 'status' => $status, 'subtotal' => $total, 'total' => $total,
            'payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => $total,
            'content' => ['payment_method' => 'cash'],
        ]);
        SaleDetail::create([
            'sale_id' => $sale->id, 'product_id' => $productId, 'unit_id' => $this->unitId('Pieza'),
            'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total,
        ]);

        return $sale;
    }

    private function purchase(float $total, string $status): void
    {
        PurchaseV2::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'purchase_number' => 'PUR-' . uniqid(), 'purchase_date' => now(), 'status' => $status,
            'total' => $total, 'payment_method' => 'cash', 'paid_from_cash' => false,
        ]);
    }

    public function test_dashboard_excludes_cancelled_sales_and_purchases_not_received(): void
    {
        $coco = $this->createProduct([], 10);
        $this->sale(100, 'closed', $coco->id);
        $this->sale(50, 'cancelled', $coco->id);
        $this->purchase(300, PurchaseV2::STATUS_RECEIVED);
        $this->purchase(200, PurchaseV2::STATUS_ORDERED);
        $this->purchase(70, PurchaseV2::STATUS_DRAFT);

        $data = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/reports-v2/dashboard?period=month&scope=location')
            ->assertOk()
            ->json('data');

        $this->assertEquals(100, $data['kpis']['sales']);
        $this->assertEquals(300, $data['kpis']['purchases']);
        $this->assertEquals(-200, $data['kpis']['profit']);
        $this->assertEquals(100, collect($data['sales_trend'])->sum('total'));
        $this->assertEquals(100, collect($data['sales_by_location'])->sum('value'));
        $this->assertEquals(100, collect($data['top_products'])->sum('sales'));
        $this->assertEquals(100, collect($data['payment_methods'])->sum('value'));

        $amounts = collect($data['recent_activity'])->whereIn('type', ['sale', 'purchase'])->pluck('amount')->sort()->values()->all();
        $this->assertEquals([100, 300], $amounts, 'la actividad reciente no muestra canceladas ni compras sin recibir');
    }

    public function test_dashboard_payment_methods_use_the_sale_method_and_split_mixed_payments(): void
    {
        $coco = $this->createProduct([], 10);
        $this->sale(100, 'closed', $coco->id);
        $card = $this->sale(60, 'closed', $coco->id);
        $card->update(['payment_method' => 'card', 'content' => ['payment_method' => 'card']]);
        $card->payments()->create(['method' => 'card', 'amount' => 60]);
        $mixed = $this->sale(50, 'closed', $coco->id);
        $mixed->update(['payment_method' => 'mixed', 'content' => ['payment_method' => 'mixed']]);
        $mixed->payments()->create(['method' => 'cash', 'amount' => 30, 'tendered' => 30, 'change' => 0]);
        $mixed->payments()->create(['method' => 'card', 'amount' => 20]);

        $data = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/reports-v2/dashboard?period=month&scope=location')
            ->assertOk()
            ->json('data');

        $this->assertEquals(
            ['Efectivo' => 130, 'Tarjeta' => 80],
            collect($data['payment_methods'])->pluck('value', 'name')->all(),
            'cada venta cuenta en su método (no "Sin especificar") y la mixta se reparte',
        );
    }

    // ─── Unidades ────────────────────────────────────────────────────────────

    public function test_editing_a_unit_without_base_unit_keeps_its_factor(): void
    {
        $gram = Unit::where('abbreviation', 'g')->firstOrFail();

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/units/{$gram->id}", ['name' => 'Gramo'])
            ->assertOk();

        // Antes quedaba en 1: un consumo de 500 g descontaba 500 kg.
        $this->assertEquals(0.001, (float) $gram->fresh()->factor_to_base);
        $this->assertSame('mass', $gram->fresh()->unit_type);
    }

    public function test_global_units_can_only_be_edited_by_a_system_admin(): void
    {
        $gram = Unit::where('abbreviation', 'g')->firstOrFail();

        Sanctum::actingAs($this->createUserWithRole($this->location, ['units_list', 'units_read', 'units_update', 'units_delete'])->fresh(), ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/units/{$gram->id}", ['name' => 'Gramito', 'factor_to_base' => 1])
            ->assertForbidden();
        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/units/{$gram->id}")
            ->assertForbidden();

        $this->assertSame('Gramo', $gram->fresh()->name);
        $this->assertEquals(0.001, (float) $gram->fresh()->factor_to_base);
    }

    public function test_company_unit_derived_from_a_reference_unit(): void
    {
        $dozen = Unit::where('abbreviation', 'dz')->firstOrFail();

        // "Caja = 2 docenas" (como lo manda la app). Antes respondía 500.
        $id = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/units', [
                'name' => 'Caja', 'abbreviation' => 'cj', 'company_id' => $this->company->id,
                'base_unit_id' => $dozen->id, 'factor_to_base' => 2,
            ])->assertCreated()->json('data.id');

        $box = Unit::findOrFail($id);
        $this->assertSame('quantity', $box->unit_type);
        $this->assertEquals(24, (float) $box->factor_to_base, '2 docenas = 24 piezas');
        $this->assertSame($this->company->id, (int) $box->company_id);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/units/{$id}", ['name' => 'Caja chica', 'company_id' => $this->company->id])
            ->assertOk();
        $this->assertEquals(24, (float) $box->fresh()->factor_to_base);
    }

    public function test_unit_list_shows_the_company_units_besides_the_global_ones(): void
    {
        // Quien no es system_admin crea la unidad para su empresa.
        Sanctum::actingAs($this->createUserWithRole($this->location, ['units_list', 'units_read', 'units_create'])->fresh(), ['*']);

        $sackId = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/units', [
                'name' => 'Costal', 'abbreviation' => 'cst',
                'base_unit_id' => $this->unitId('Kilogramo'), 'factor_to_base' => 25,
            ])->assertCreated()->json('data.id');
        $this->assertSame($this->company->id, (int) Unit::findOrFail($sackId)->company_id);

        [$otherCompany] = $this->createCompanyWithBranch();
        $foreign = Unit::create([
            'name' => 'Bulto', 'abbreviation' => 'blt', 'unit_type' => 'mass',
            'company_id' => $otherCompany->id, 'factor_to_base' => 50,
        ]);

        $ids = collect(
            $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/units?all=true')->assertOk()->json('data')
        )->pluck('id');

        // Antes la lista solo traía las globales: la unidad recién creada no aparecía.
        $this->assertSame($sackId, $ids->first(), 'la más nueva primero, como la lista de la app');
        $this->assertContains($this->unitId('Kilogramo'), $ids);
        $this->assertNotContains($foreign->id, $ids, 'las unidades de otra empresa no');

        $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/units/{$sackId}")->assertOk();
        $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/units/{$foreign->id}")->assertNotFound();
    }

    public function test_grouped_units_include_the_company_units_but_not_another_company(): void
    {
        $sack = Unit::create([
            'name' => 'Costal', 'abbreviation' => 'cst', 'unit_type' => 'mass',
            'company_id' => $this->company->id, 'factor_to_base' => 25,
        ]);
        [$otherCompany] = $this->createCompanyWithBranch();
        $foreign = Unit::create([
            'name' => 'Bulto', 'abbreviation' => 'blt', 'unit_type' => 'mass',
            'company_id' => $otherCompany->id, 'factor_to_base' => 50,
        ]);

        // Por tipo (compras): antes solo las globales.
        $mass = collect(
            $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/units/grouped-by-type')->assertOk()->json('data.mass')
        )->pluck('id');
        $this->assertContains($sack->id, $mass);
        $this->assertContains($this->unitId('Gramo'), $mass);
        $this->assertNotContains($foreign->id, $mass);

        // Por base (ajustes): el company_id de la petición ya no abre las de otra empresa.
        $group = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/units/grouped-by-base?unit_id=' . $this->unitId('Kilogramo') . "&company_id={$otherCompany->id}")
            ->assertOk()
            ->json('data');
        $ids = collect($group)->flatten(1)->pluck('id');
        $this->assertContains($sack->id, $ids);
        $this->assertContains($this->unitId('Kilogramo'), $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    // ─── Conteos ─────────────────────────────────────────────────────────────

    public function test_count_difference_keeps_decimals(): void
    {
        $pulpa = $this->createProduct([], 10.4, 'Kilogramo');
        $count = InventoryCount::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'name' => 'Semanal', 'count_date' => now()->toDateString(), 'status' => 'counting',
        ]);

        $detail = InventoryCountDetail::create([
            'inventory_count_id' => $count->id, 'product_id' => $pulpa->id, 'location_id' => $this->location->id,
            'system_quantity' => 10.4, 'counted_quantity' => 9,
        ]);

        // Antes se redondeaba a enteros y la diferencia aplicada era -1.
        $this->assertEqualsWithDelta(-1.4, (float) $detail->fresh()->difference, 0.0001);
        $this->assertEqualsWithDelta(10.4, (float) $detail->fresh()->system_quantity, 0.0001);
    }

    // ─── Transferencias ──────────────────────────────────────────────────────

    public function test_transfer_list_reports_available_stock_at_the_origin(): void
    {
        Storage::fake('public');
        $origin = Location::factory()->create(['company_id' => $this->company->id]);
        $coco = $this->createProduct();
        $this->setStock($coco, $origin, 36);
        \Illuminate\Support\Facades\DB::table('product_location')
            ->where('product_id', $coco->id)->where('location_id', $origin->id)
            ->update(['reserved_stock' => 6]);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-transfers', [
                'from_location_id' => $origin->id,
                'to_location_id' => $this->location->id,
                'details' => [['product_id' => $coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity_requested' => 10, 'unit_cost' => 10]],
            ])->assertCreated();

        $detail = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/inventory-transfers?per_page=20&company_id=' . $this->company->id)
            ->assertOk()
            ->json('data.0.details.0');

        $this->assertEquals(30, $detail['available_stock'], 'existencia en el origen menos lo reservado');
        $this->assertSame($coco->id, $detail['product']['id']);
        $this->assertSame('Pieza', $detail['unit']['name']);
        $this->assertEquals(10, $detail['quantity_requested']);
    }
}
