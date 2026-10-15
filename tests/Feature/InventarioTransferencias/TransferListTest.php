<?php

namespace Tests\Feature\InventarioTransferencias;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Listados de transferencias ("Solicitudes pendientes" e "Historial") como los
 * piden las pantallas de la app.
 */
class TransferListTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    public function test_branch_user_without_all_branches_access_can_list_transfers(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $otra = $this->createBranch(['name' => 'Otra']);
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 100);
        $this->setStock($coco, $otra, 100);

        $mine = $this->requestTransfer($matriz, $this->location, $coco, 5);
        $this->requestTransfer($matriz, $otra, $coco, 5);

        // Rol armado con la plantilla "Operativo": tiene transfers_list pero no
        // acceso a todas las sucursales. Antes: 500 (CurrentLocation sin importar).
        $encargado = $this->createBranchUser($this->location, $this->presetPermissions('operativo'));

        $this->actingInBranch($encargado)
            ->getJson('/api/auth/admin/inventory-transfers?mode=pending&status=pending,approved,in_transit&per_page=20&page=1&paginated=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingInBranch($encargado)
            ->getJson('/api/auth/admin/inventory-transfers?mode=history&status=completed,rejected,cancelled&per_page=20&page=1&paginated=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
