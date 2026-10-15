<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\TransferStatus;
use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Cada paso de la transferencia se hace desde la sucursal que le toca, como
 * en la app: aprueba y envía el origen, recibe el destino, cancela o rechaza
 * cualquiera de los dos. Una tercera sucursal ni siquiera la ve. Antes solo
 * la app lo cuidaba y la API dejaba hacer todo con el id.
 *
 * Matriz (origen) → sucursal del contexto (destino); Guayabitos es ajena.
 */
class TransferBranchAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    private Location $matriz;
    private Location $guayabitos;
    private Product $coco;
    private User $origen;
    private User $destino;
    private User $tercero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');

        $this->matriz = $this->createBranch(['name' => 'Matriz']);
        $this->guayabitos = $this->createBranch(['name' => 'Guayabitos']);
        $this->coco = $this->createProduct();
        $this->setStock($this->coco, $this->matriz, 100);

        // Roles de sucursal armados con la plantilla "Operativo" (más cancelar).
        $permissions = array_merge($this->presetPermissions('operativo'), ['transfers_delete']);
        $this->origen = $this->createBranchUser($this->matriz, $permissions);
        $this->destino = $this->createBranchUser($this->location, $permissions);
        $this->tercero = $this->createBranchUser($this->guayabitos, $permissions);
    }

    private function newRequest(): InventoryTransfer
    {
        return $this->requestTransfer($this->matriz, $this->location, $this->coco, 10);
    }

    private function url(InventoryTransfer $transfer, string $action = ''): string
    {
        return "/api/auth/admin/inventory-transfers/{$transfer->id}" . ($action !== '' ? "/{$action}" : '');
    }

    private function shipPayload(InventoryTransfer $transfer): array
    {
        return [
            'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_shipped' => 10]],
            'evidence' => [UploadedFile::fake()->image('camion.jpg')],
        ];
    }

    public function test_a_third_branch_does_not_see_nor_act_on_the_transfer(): void
    {
        $transfer = $this->newRequest();
        $as = fn() => $this->actingInBranch($this->tercero, $this->guayabitos);

        $as()->getJson($this->url($transfer))->assertNotFound();
        $as()->postJson($this->url($transfer, 'approve'), [])->assertNotFound();
        $as()->postJson($this->url($transfer, 'reject'), ['rejection_reason' => 'No'])->assertNotFound();
        $as()->deleteJson($this->url($transfer))->assertNotFound();
        $as()->putJson($this->url($transfer), ['notes' => 'x'])->assertNotFound();

        $transfer = $this->approveTransfer($transfer);
        $as()->post($this->url($transfer, 'ship'), $this->shipPayload($transfer), ['Accept' => 'application/json'])->assertNotFound();

        $transfer = $this->shipTransfer_fromApproved($transfer);
        $as()->post($this->url($transfer, 'receive'), $this->fullReceivePayload($transfer), ['Accept' => 'application/json'])
            ->assertNotFound();

        // Lo recibido nunca llegó a Guayabitos ni a ningún lado.
        $this->assertSame(TransferStatus::IN_TRANSIT, $transfer->fresh()->status);
        $this->assertEquals(0, $this->stockOf($this->coco));
    }

    public function test_the_destination_cannot_approve_nor_ship_its_own_request(): void
    {
        $transfer = $this->newRequest();

        // b1 de la auditoría: el destino aprobaba y enviaba desde Matriz sin nadie de Matriz.
        $this->actingInBranch($this->destino)
            ->postJson($this->url($transfer, 'approve'), [])
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_location');

        $transfer = $this->approveTransfer($transfer);

        $this->actingInBranch($this->destino)
            ->post($this->url($transfer, 'ship'), $this->shipPayload($transfer), ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_location');

        $this->assertEquals(100, $this->stockOf($this->coco, $this->matriz));
    }

    public function test_the_origin_approves_and_ships_but_cannot_receive(): void
    {
        $transfer = $this->newRequest();

        $this->actingInBranch($this->origen, $this->matriz)
            ->postJson($this->url($transfer, 'approve'), [])
            ->assertOk();

        $transfer = $transfer->fresh('details');
        $this->actingInBranch($this->origen, $this->matriz)
            ->post($this->url($transfer, 'ship'), $this->shipPayload($transfer), ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertEquals(90, $this->stockOf($this->coco, $this->matriz));

        $transfer = $transfer->fresh('details');
        $this->actingInBranch($this->origen, $this->matriz)
            ->post($this->url($transfer, 'receive'), $this->fullReceivePayload($transfer), ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_location');

        $this->actingInBranch($this->destino)
            ->post($this->url($transfer, 'receive'), $this->fullReceivePayload($transfer), ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertEquals(10, $this->stockOf($this->coco));
        $this->assertSame(TransferStatus::COMPLETED, $transfer->fresh()->status);
    }

    public function test_either_side_can_cancel_or_reject(): void
    {
        $first = $this->newRequest();
        $this->actingInBranch($this->destino)->deleteJson($this->url($first))->assertOk();
        $this->assertSame(TransferStatus::CANCELLED, $first->fresh()->status);

        $second = $this->newRequest();
        $this->actingInBranch($this->origen, $this->matriz)
            ->postJson($this->url($second, 'reject'), ['rejection_reason' => 'Sin reparto hoy'])
            ->assertOk();
        $this->assertSame(TransferStatus::REJECTED, $second->fresh()->status);

        $third = $this->newRequest();
        $this->actingInBranch($this->destino)
            ->postJson($this->url($third, 'reject'), ['rejection_reason' => 'Ya no se necesita'])
            ->assertOk();
        $this->assertSame(TransferStatus::REJECTED, $third->fresh()->status);
    }

    public function test_user_of_both_branches_acts_only_from_the_branch_that_corresponds(): void
    {
        // Asignado también a Matriz, pero operando hoy desde el destino: ve la
        // transferencia (es de una de sus sucursales) pero para aprobarla tiene
        // que cambiarse a Matriz, donde sus permisos son los de ese rol.
        $roleId = DB::table('user_location_roles')->where('user_id', $this->destino->id)->value('role_id');
        DB::table('user_location_roles')->insert(['user_id' => $this->destino->id, 'location_id' => $this->matriz->id, 'role_id' => $roleId]);

        $transfer = $this->newRequest();

        $this->actingInBranch($this->destino)->getJson($this->url($transfer))->assertOk();
        $this->actingInBranch($this->destino)->postJson($this->url($transfer, 'approve'), [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Esta transferencia la aprueba la sucursal de origen (Matriz).');

        $this->actingInBranch($this->destino, $this->matriz)->postJson($this->url($transfer, 'approve'), [])->assertOk();
    }

    public function test_the_destination_cannot_move_its_request_to_other_branches(): void
    {
        $transfer = $this->newRequest();

        $this->actingInBranch($this->destino)
            ->putJson($this->url($transfer), ['to_location_id' => $this->guayabitos->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_location');

        $this->assertSame($this->location->id, $transfer->fresh()->to_location_id);
    }

    /** Envía una transferencia ya aprobada (como administrador). */
    private function shipTransfer_fromApproved(InventoryTransfer $transfer): InventoryTransfer
    {
        $this->actingInBranch($this->user, $this->matriz)
            ->post($this->url($transfer, 'ship'), $this->shipPayload($transfer), ['Accept' => 'application/json'])
            ->assertOk();

        return $transfer->fresh('details');
    }
}
