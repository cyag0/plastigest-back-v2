<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\Admin\Location;
use App\Models\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * La caja de otra sucursal se abría, editaba y borraba por URL: show, update y
 * destroy solo revisaban la compañía, aunque el listado ya se limitaba a la
 * sucursal en la que se trabaja.
 */
class AccesosCashRegisterBranchTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MANAGER = [
        'cash_registers_list', 'cash_registers_read', 'cash_registers_create',
        'cash_registers_update', 'cash_registers_delete',
    ];

    private Location $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->otherBranch = $this->createBranch(['name' => 'Matriz']);
    }

    private function register(Location $branch, string $name): CashRegister
    {
        return CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $branch->id,
            'name' => $name, 'code' => 'C' . uniqid(),
        ]);
    }

    private function url(CashRegister $register): string
    {
        return "/api/auth/admin/cash-registers/{$register->id}";
    }

    public function test_a_branch_only_manager_does_not_reach_another_branch_register_by_url(): void
    {
        $manager = $this->createBranchUser($this->location, self::MANAGER);
        $foreign = $this->register($this->otherBranch, 'Caja 2');

        $this->actingInBranch($manager)->getJson($this->url($foreign))->assertNotFound();
        $this->actingInBranch($manager)->putJson($this->url($foreign), ['name' => 'Renombrada'])->assertNotFound();
        $this->actingInBranch($manager)->deleteJson($this->url($foreign))->assertNotFound();

        $this->assertDatabaseHas('cash_registers', ['id' => $foreign->id, 'name' => 'Caja 2']);
    }

    public function test_a_branch_only_manager_manages_the_registers_of_their_branch(): void
    {
        $manager = $this->createBranchUser($this->location, self::MANAGER);
        $mine = $this->register($this->location, 'Caja 1');

        $this->actingInBranch($manager)->getJson($this->url($mine))
            ->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->actingInBranch($manager)->putJson($this->url($mine), ['name' => 'Caja principal'])
            ->assertOk()->assertJsonPath('data.name', 'Caja principal');

        // Tampoco la mueve a otra sucursal ni crea una allá.
        $this->actingInBranch($manager)->putJson($this->url($mine), ['location_id' => $this->otherBranch->id])
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->assertSame($this->location->id, $mine->fresh()->location_id);
        $this->actingInBranch($manager)->postJson('/api/auth/admin/cash-registers', [
            'name' => 'Ajena', 'code' => 'AJ-1', 'location_id' => $this->otherBranch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->actingInBranch($manager)->postJson('/api/auth/admin/cash-registers', [
            'name' => 'Caja 3', 'code' => 'C-3', 'location_id' => $this->location->id,
        ])->assertCreated();

        $this->actingInBranch($manager)->deleteJson($this->url($mine))->assertOk();
        $this->assertDatabaseMissing('cash_registers', ['id' => $mine->id]);
    }

    public function test_an_all_branches_manager_manages_every_branch_register(): void
    {
        $owner = $this->createUserWithRole($this->location, [...self::MANAGER, 'locations_all']);
        $foreign = $this->register($this->otherBranch, 'Caja 2');

        $this->actingInBranch($owner)->getJson($this->url($foreign))
            ->assertOk()->assertJsonPath('data.id', $foreign->id);
        $this->actingInBranch($owner)->putJson($this->url($foreign), ['name' => 'Caja de Matriz'])
            ->assertOk()->assertJsonPath('data.name', 'Caja de Matriz');
        $this->actingInBranch($owner)->postJson('/api/auth/admin/cash-registers', [
            'name' => 'Caja 3', 'code' => 'C-3', 'location_id' => $this->otherBranch->id,
        ])->assertCreated();
        $this->actingInBranch($owner)->deleteJson($this->url($foreign))->assertOk();

        $this->assertDatabaseMissing('cash_registers', ['id' => $foreign->id]);
    }
}
