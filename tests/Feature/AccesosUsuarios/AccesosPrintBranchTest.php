<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Models\InventoryTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El corte de turno y la remisión de transferencia se podían imprimir de
 * cualquier sucursal de la empresa mandando el id: solo se revisaba el
 * permiso, no la sucursal.
 */
class AccesosPrintBranchTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private Location $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->otherBranch = $this->createBranch(['name' => 'Pitillal']);
    }

    private function closedShift(Location $branch, int $userId): int
    {
        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $branch->id,
            'name' => 'Caja ' . $branch->id, 'code' => 'C' . $branch->id,
        ]);

        return DB::table('cash_register_sessions')->insertGetId([
            'company_id' => $this->company->id, 'location_id' => $branch->id, 'cash_register_id' => $register->id,
            'user_id' => $userId, 'status' => 'closed', 'opened_at' => now()->subHours(8), 'opening_amount' => 500,
            'closed_at' => now(), 'closed_by' => $userId, 'totals' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shiftUrl($user, int $sessionId, ?Location $branch = null)
    {
        return $this->actingInBranch($user, $branch)->getJson("/api/auth/admin/cash-register-sessions/{$sessionId}/ticket-url");
    }

    public function test_a_supervisor_only_prints_shift_cuts_of_their_branch(): void
    {
        $supervisor = $this->createBranchUser($this->location, ['cash_register_sessions_read']);
        $cashierHere = $this->createBranchUser($this->location, ['sales_pos']);
        $cashierThere = $this->createBranchUser($this->otherBranch, ['sales_pos']);

        $this->shiftUrl($supervisor, $this->closedShift($this->location, $cashierHere->id))
            ->assertOk()->assertJsonStructure(['url']);
        $this->shiftUrl($supervisor, $this->closedShift($this->otherBranch, $cashierThere->id))
            ->assertNotFound();
    }

    public function test_the_cashier_prints_their_own_shift_and_all_branches_supervisors_any(): void
    {
        $cashier = $this->createBranchUser($this->otherBranch, ['sales_pos']);
        $shiftThere = $this->closedShift($this->otherBranch, $cashier->id);

        $this->shiftUrl($cashier, $shiftThere, $this->otherBranch)->assertOk();

        $regional = $this->createUserWithRole($this->location, ['cash_register_sessions_read', 'locations_all']);
        $this->shiftUrl($regional, $shiftThere)->assertOk();

        // Sin el permiso de consultar turnos, el de otra persona no.
        $coworker = $this->createBranchUser($this->otherBranch, ['sales_pos']);
        $this->shiftUrl($coworker, $shiftThere, $this->otherBranch)->assertForbidden();
    }

    public function test_a_transfer_remission_is_printed_only_by_its_branches(): void
    {
        $destination = $this->createBranch(['name' => 'Guayabitos']);
        $transfer = InventoryTransfer::create([
            'company_id' => $this->company->id, 'from_location_id' => $this->location->id, 'to_location_id' => $destination->id,
            'requested_by' => $this->user->id, 'status' => 'pending',
        ]);
        $url = "/api/auth/admin/inventory-transfers/{$transfer->id}/pdf-url";

        $origin = $this->createBranchUser($this->location, ['transfers_read']);
        $this->actingInBranch($origin)->getJson($url)->assertOk()->assertJsonStructure(['url']);

        $receiver = $this->createBranchUser($destination, ['transfers_read']);
        $this->actingInBranch($receiver, $destination)->getJson($url)->assertOk();

        $outsider = $this->createBranchUser($this->otherBranch, ['transfers_read']);
        $this->actingInBranch($outsider, $this->otherBranch)->getJson($url)->assertNotFound();

        $regional = $this->createUserWithRole($this->otherBranch, ['transfers_read', 'locations_all']);
        $this->actingInBranch($regional, $this->otherBranch)->getJson($url)->assertOk();
    }
}
