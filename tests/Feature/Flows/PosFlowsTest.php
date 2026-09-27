<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Cajas, turnos y punto de venta.
 */
class PosFlowsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Cajero: vende en el POS y abre/cierra su turno; no administra. */
    protected const CASHIER = ['sales_pos', 'sales_list', 'sales_read', 'products_list', 'products_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    protected function register(array $attributes = []): CashRegister
    {
        return CashRegister::create(array_merge([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Caja 1',
            'code' => 'C' . uniqid(),
        ], $attributes));
    }

    // ─── Catálogo de cajas ───────────────────────────────────────────────────

    public function test_admin_creates_a_register_for_a_branch_of_the_company(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/cash-registers', [
                'name' => 'Caja 1', 'code' => 'CAJA-1', 'location_id' => $this->location->id,
            ])->assertCreated();

        $this->assertDatabaseHas('cash_registers', ['company_id' => $this->company->id, 'code' => 'CAJA-1']);

        // Código repetido en la misma compañía.
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/cash-registers', [
                'name' => 'Otra', 'code' => 'CAJA-1', 'location_id' => $this->location->id,
            ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Sucursal de otra compañía.
        $foreign = Location::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/cash-registers', [
                'name' => 'Ajena', 'code' => 'CAJA-X', 'location_id' => $foreign->id,
            ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_registers_are_scoped_to_company_and_branch(): void
    {
        $mine = $this->register(['name' => 'Mía']);
        $otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $this->register(['name' => 'Otra sucursal', 'location_id' => $otherBranch->id]);
        $otherCompany = Company::factory()->create();
        $foreign = CashRegister::create([
            'company_id' => $otherCompany->id,
            'location_id' => Location::factory()->create(['company_id' => $otherCompany->id])->id,
            'name' => 'Ajena', 'code' => 'AJ-1',
        ]);

        // Admin: todas las de su compañía, ninguna ajena.
        $names = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/cash-registers?per_page=50')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Mía', 'Otra sucursal'], $names);
        $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/cash-registers/{$foreign->id}")->assertNotFound();

        // Cajero: solo las de su sucursal (las necesita para abrir turno).
        Sanctum::actingAs($this->createUserWithRole($this->location, self::CASHIER), ['*']);
        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/cash-registers?per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    // ─── Turnos ──────────────────────────────────────────────────────────────

    protected function actAsCashier(): \App\Models\User
    {
        $cashier = $this->createUserWithRole($this->location, self::CASHIER);
        Sanctum::actingAs($cashier, ['*']);

        return $cashier;
    }

    protected function openShift(CashRegister $register, float $amount = 500): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/cash-register-sessions/open', [
                'cash_register_id' => $register->id,
                'opening_amount' => $amount,
            ]);
    }

    public function test_one_open_shift_per_register_and_per_cashier(): void
    {
        $caja1 = $this->register(['name' => 'Caja 1']);
        $caja2 = $this->register(['name' => 'Caja 2']);

        $cashierA = $this->actAsCashier();
        $this->openShift($caja1)->assertCreated()->assertJsonPath('data.cash_register_name', 'Caja 1');

        // El mismo cajero no abre un segundo turno en otra caja.
        $this->openShift($caja2)->assertStatus(422)->assertJsonValidationErrors('cash_register_id');

        // Otro cajero no abre en una caja ocupada.
        $this->actAsCashier();
        $this->openShift($caja1)->assertStatus(422)
            ->assertJsonPath('errors.cash_register_id.0', "La caja ya tiene un turno abierto de {$cashierA->name}.");

        // Y la base de datos lo impide aunque se salte el servicio (doble clic, dos dispositivos).
        $this->expectException(\Illuminate\Database\QueryException::class);
        CashRegisterSession::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'cash_register_id' => $caja1->id, 'user_id' => $this->user->id,
            'status' => 'open', 'opened_at' => now(), 'opening_amount' => 0,
        ]);
    }

    public function test_cannot_open_a_register_of_another_branch(): void
    {
        $otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $foreign = $this->register(['location_id' => $otherBranch->id]);

        $this->actAsCashier();
        $this->openShift($foreign)->assertStatus(422);
    }

    public function test_closing_computes_expected_cash_and_difference(): void
    {
        $caja = $this->register();
        $this->actAsCashier();
        $sessionId = $this->openShift($caja, 500)->json('data.id');

        foreach ([['income', 300, 'cash'], ['income', 200, 'card'], ['expense', 50, 'cash']] as [$type, $amount, $method]) {
            CashMovement::create([
                'company_id' => $this->company->id, 'location_id' => $this->location->id,
                'cash_register_session_id' => $sessionId, 'user_id' => $this->user->id,
                'type' => $type, 'amount' => $amount, 'concept' => 't', 'payment_method' => $method,
                'source_type' => 'manual', 'movement_date' => now()->toDateString(),
            ]);
        }

        // En vivo: fondo 500 + 300 − 50 en efectivo = 750 (la tarjeta no está en la caja).
        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/cash-register-sessions/current')
            ->assertOk()
            ->assertJsonPath('summary.expected_cash', 750)
            ->assertJsonPath('summary.by_method.card.income', 200);

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 740, 'notes' => 'Faltan 10'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_cash', 750)
            ->assertJsonPath('data.difference', -10);

        // No se cierra dos veces y la caja queda libre.
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 740])
            ->assertStatus(422);
        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/cash-register-sessions/current')->assertJsonPath('data', null);
    }

    public function test_only_the_cashier_or_an_admin_can_close_a_shift(): void
    {
        $caja = $this->register();
        $this->actAsCashier();
        $sessionId = $this->openShift($caja)->json('data.id');

        // Otro cajero no puede cerrarlo.
        $this->actAsCashier();
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 500])
            ->assertForbidden();

        // El administrador sí (turno olvidado abierto).
        Sanctum::actingAs($this->user, ['*']);
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 500])
            ->assertOk()
            ->assertJsonPath('data.closed_by_name', $this->user->name);
    }

    // ─── Punto de venta ──────────────────────────────────────────────────────

    /** Cajero con turno abierto en una caja de la sucursal; devuelve el id del turno. */
    protected function cashierWithShift(array $extraPermissions = []): int
    {
        $cashier = $this->createUserWithRole($this->location, array_merge(self::CASHIER, $extraPermissions));
        Sanctum::actingAs($cashier, ['*']);

        return $this->openShift($this->register(), 500)->assertCreated()->json('data.id');
    }

    protected function sell(array $details, array $payments): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/pos/sales', ['details' => $details, 'payments' => $payments]);
    }

    public function test_cannot_sell_without_an_open_shift(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $this->actAsCashier();

        $this->sell([['product_id' => $coca->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 18]])
            ->assertStatus(422)->assertJsonPath('code', 'no_open_session');
    }

    public function test_cash_sale_uses_catalog_price_returns_change_and_enters_the_shift(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $sessionId = $this->cashierWithShift();

        $this->sell(
            [['product_id' => $coca->id, 'quantity' => 2]],
            [['method' => 'cash', 'amount' => 36, 'tendered' => 50]]
        )->assertCreated()
            ->assertJsonPath('data.total', 36)
            ->assertJsonPath('data.change', 14);

        $this->assertEquals(8, $this->stockOf($coca));
        $this->assertDatabaseHas('sales', ['cash_register_session_id' => $sessionId, 'payment_method' => 'cash', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $sessionId, 'type' => 'income', 'amount' => 36, 'payment_method' => 'cash']);
    }

    public function test_mixed_payment_creates_one_movement_per_method_and_closes_correctly(): void
    {
        $leche = $this->createProduct(['sale_price' => 29], 10);
        $sessionId = $this->cashierWithShift();

        $this->sell(
            [['product_id' => $leche->id, 'quantity' => 2]],
            [['method' => 'cash', 'amount' => 20, 'tendered' => 20], ['method' => 'card', 'amount' => 38]]
        )->assertCreated()->assertJsonPath('data.payment_method', 'mixed');

        $this->assertSame(2, \App\Models\SalePayment::count());
        $this->assertSame(2, CashMovement::where('cash_register_session_id', $sessionId)->count());

        // Esperado en caja: fondo 500 + 20 en efectivo (la tarjeta no entra a la caja).
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 520])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 520)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('summary.sales_total', 58)
            ->assertJsonPath('summary.by_method.card.income', 38);
    }

    public function test_payments_must_add_up_to_the_total(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $this->cashierWithShift();

        $this->sell([['product_id' => $coca->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 30]])
            ->assertStatus(422)->assertJsonValidationErrors('payments');

        $this->sell([['product_id' => $coca->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 36, 'tendered' => 20]])
            ->assertStatus(422)->assertJsonValidationErrors('payments.0.tendered');

        $this->assertEquals(10, $this->stockOf($coca), 'una venta rechazada no mueve stock');
    }

    public function test_price_and_discount_need_permission(): void
    {
        $arroz = $this->createProduct(['sale_price' => 35], 10);
        $this->cashierWithShift();

        // Sin permiso: ni descuento ni precio distinto al del catálogo (aunque la app lo mande).
        $this->sell([['product_id' => $arroz->id, 'quantity' => 1, 'discount' => 5]], [['method' => 'cash', 'amount' => 30]])
            ->assertStatus(422)->assertJsonValidationErrors('details.0.unit_price');
        $this->sell([['product_id' => $arroz->id, 'quantity' => 1, 'unit_price' => 1]], [['method' => 'cash', 'amount' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('details.0.unit_price');

        // Con permiso.
        $this->cashierWithShift(['sales_discount']);
        $this->sell([['product_id' => $arroz->id, 'quantity' => 1, 'discount' => 5]], [['method' => 'cash', 'amount' => 30]])
            ->assertCreated()->assertJsonPath('data.total', 30)->assertJsonPath('data.discount', 5);
    }

    public function test_closed_shift_cannot_sell(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $sessionId = $this->cashierWithShift();
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 500])->assertOk();

        $this->sell([['product_id' => $coca->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 18]])
            ->assertStatus(422)->assertJsonPath('code', 'no_open_session');
    }

    public function test_cancelling_a_pos_sale_reverses_cash_in_the_shift(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $sessionId = $this->cashierWithShift();
        $saleId = $this->sell([['product_id' => $coca->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 36]])->json('data.id');

        // El administrador cancela (el cajero no tiene permiso de cancelar ventas).
        Sanctum::actingAs($this->user, ['*']);
        $this->withHeaders($this->tenantHeaders())->postJson("/api/auth/admin/sales/{$saleId}/cancel")->assertOk();

        // Quien cancela no tiene turno: la devolución entra al turno de la venta, que sigue abierto.
        $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $sessionId, 'type' => 'expense', 'amount' => 36]);
        $this->assertEquals(10, $this->stockOf($coca));
    }

    public function test_package_sale_and_catalog(): void
    {
        $coca = $this->createProduct(['name' => 'Coca Cola 600ml', 'code' => 'COCA600', 'sale_price' => 18], 30);
        $pack = \App\Models\ProductPackage::create([
            'product_id' => $coca->id, 'company_id' => $this->company->id, 'package_name' => 'Caja 12',
            'barcode' => '7501055300075', 'quantity_per_package' => 12, 'unit_id' => $coca->unit_id,
            'sale_price' => 190, 'is_active' => true,
        ]);
        $this->cashierWithShift();

        $catalog = collect($this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/pos/catalog')->assertOk()->json('data'));
        $this->assertSame(30.0, (float) $catalog->firstWhere('key', "p{$coca->id}")['stock']);
        $packItem = $catalog->firstWhere('key', "k{$pack->id}");
        $this->assertSame('7501055300075', $packItem['code']);
        $this->assertSame(190.0, (float) $packItem['price']);
        $this->assertSame(2.0, (float) $packItem['stock'], 'cajas completas que alcanzan con 30 piezas');

        $this->sell([['product_id' => $coca->id, 'package_id' => $pack->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 190]])
            ->assertCreated();
        $this->assertEquals(18, $this->stockOf($coca), 'una caja descuenta 12 piezas');
    }

    public function test_old_sales_screen_still_works_and_joins_the_open_shift(): void
    {
        $coca = $this->createProduct(['sale_price' => 18], 10);
        $sessionId = $this->cashierWithShift(['sales_create']);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/sales', [
                'payment_method' => 'cash',
                'paid_amount' => 18,
                'details' => [['product_id' => $coca->id, 'unit_id' => $coca->unit_id, 'quantity' => 1, 'unit_price' => 18]],
            ])->assertCreated();

        $this->assertDatabaseHas('sales', ['cash_register_session_id' => $sessionId, 'total' => 18]);
        $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $sessionId, 'amount' => 18]);
    }
}
