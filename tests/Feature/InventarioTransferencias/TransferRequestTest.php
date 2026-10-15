<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\InventoryTransfer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Solicitud de transferencia ("Nueva transferencia" y "Qué pedir"): la lista
 * de sucursales a las que se puede pedir y las reglas al crearla.
 */
class TransferRequestTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    private function payload(int $fromId, int $toId, Product $product, float $qty): array
    {
        return [
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'details' => [['product_id' => $product->id, 'unit_id' => $product->unit_id, 'quantity_requested' => $qty, 'unit_cost' => 10]],
        ];
    }

    // ─── "Pedir a" ──────────────────────────────────────────────────────────

    public function test_branch_role_gets_the_other_active_branches_of_its_company_as_origins(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $guayabitos = $this->createBranch(['name' => 'Guayabitos']);
        $this->createBranch(['name' => 'Cerrada', 'is_active' => false]);
        [, $ajena] = $this->createCompanyWithBranch();

        // Plantilla "Operativo": transfers_create pero no locations_list. Antes
        // el formulario usaba la lista de sucursales y le salía vacía.
        $encargado = $this->createBranchUser($this->location, $this->presetPermissions('operativo'));

        $origins = $this->actingInBranch($encargado)
            ->getJson('/api/auth/admin/inventory-transfers/origins')
            ->assertOk()
            ->json('data');

        $this->assertSame([
            ['id' => $guayabitos->id, 'name' => 'Guayabitos'],
            ['id' => $matriz->id, 'name' => 'Matriz'],
        ], $origins);
        $this->assertNotContains($ajena->id, array_column($origins, 'id'));
    }

    public function test_origins_require_permission_to_create_transfers(): void
    {
        $soloLectura = $this->createBranchUser($this->location, ['transfers_list', 'transfers_read']);

        $this->actingInBranch($soloLectura)
            ->getJson('/api/auth/admin/inventory-transfers/origins')
            ->assertForbidden();
    }

    // ─── Crear la solicitud ─────────────────────────────────────────────────

    public function test_branch_role_can_request_stock_from_another_branch(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 50);

        $encargado = $this->createBranchUser($this->location, $this->presetPermissions('operativo'));

        $this->actingInBranch($encargado)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($matriz->id, $this->location->id, $coco, 10))
            ->assertCreated()
            ->assertJsonPath('data.from_location_id', $matriz->id)
            ->assertJsonPath('data.to_location_id', $this->location->id);
    }

    public function test_request_without_enough_stock_at_the_origin_is_a_422_with_the_reason(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $matriz, 4);

        $encargado = $this->createBranchUser($this->location, ['transfers_create']);

        // Antes: 500 "Error al crear la transferencia" con el motivo escondido.
        $this->actingInBranch($encargado)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($matriz->id, $this->location->id, $coco, 10))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 4 Pieza, solicitado: 10 Pieza.')
            ->assertJsonPath('details.location_id', $matriz->id);

        $this->assertSame(0, InventoryTransfer::count());
    }

    public function test_lines_of_the_same_product_add_up_against_the_origin_stock(): void
    {
        $matriz = $this->createBranch();
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 15);

        $payload = $this->payload($matriz->id, $this->location->id, $coco, 10);
        $payload['details'][] = $payload['details'][0];

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('details.requested', 20);
    }

    public function test_origin_from_another_company_is_rejected(): void
    {
        [, $ajena] = $this->createCompanyWithBranch();
        $coco = $this->createProduct();

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($ajena->id, $this->location->id, $coco, 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_location_id');
    }

    public function test_inactive_origin_is_rejected(): void
    {
        $cerrada = $this->createBranch(['is_active' => false]);
        $coco = $this->createProduct();
        $this->setStock($coco, $cerrada, 50);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($cerrada->id, $this->location->id, $coco, 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_location_id');
    }

    public function test_product_from_another_company_is_rejected(): void
    {
        $matriz = $this->createBranch();
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $ajeno = $this->createProduct(['company_id' => $otraEmpresa->id]);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($matriz->id, $this->location->id, $ajeno, 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.product_id');
    }

    public function test_branch_user_cannot_create_a_transfer_between_two_other_branches(): void
    {
        $matriz = $this->createBranch();
        $guayabitos = $this->createBranch();
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 50);

        $encargado = $this->createBranchUser($this->location, ['transfers_create']);

        $this->actingInBranch($encargado)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($matriz->id, $guayabitos->id, $coco, 5))
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_location');

        $this->assertSame(0, InventoryTransfer::count());
    }

    public function test_origin_branch_can_propose_a_shipment_from_replenishment(): void
    {
        // "Qué pedir" muestra a la sucursal de origen lo que le toca mandar y
        // le deja crear la solicitud (origen = su sucursal).
        $guayabitos = $this->createBranch();
        $coco = $this->createProduct([], 50);

        $encargado = $this->createBranchUser($this->location, ['transfers_create']);

        $this->actingInBranch($encargado)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($this->location->id, $guayabitos->id, $coco, 5))
            ->assertCreated();
    }

    public function test_store_does_not_log_the_request_payload(): void
    {
        $matriz = $this->createBranch();
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 50);

        Log::spy();

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/inventory-transfers', $this->payload($matriz->id, $this->location->id, $coco, 5))
            ->assertCreated();

        Log::shouldNotHaveReceived('info');
    }
}
