<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\CashRegister;
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
}
