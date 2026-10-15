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

    public function test_direction_filter_separates_sent_and_received(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz']);
        $coco = $this->createProduct([], 100);
        $this->setStock($coco, $matriz, 100);

        $received = $this->requestTransfer($matriz, $this->location, $coco, 5);
        $sent = $this->requestTransfer($this->location, $matriz, $coco, 3);

        $encargado = $this->createBranchUser($this->location, ['transfers_list']);
        $url = '/api/auth/admin/inventory-transfers?status=pending,approved,in_transit&per_page=20';

        // Antes el filtro "Dirección" no hacía nada.
        $this->actingInBranch($encargado)->getJson("{$url}&direction=sent")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sent->id);

        $this->actingInBranch($encargado)->getJson("{$url}&direction=received")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $received->id);

        $this->actingInBranch($encargado)->getJson("{$url}&direction=")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_finds_by_folio_branch_and_product(): void
    {
        $matriz = $this->createBranch(['name' => 'Matriz Centro']);
        $guayabitos = $this->createBranch(['name' => 'Guayabitos']);
        $coco = $this->createProduct(['name' => 'Coco entero'], 100);
        $agua = $this->createProduct(['name' => 'Agua de coco'], 100, 'Litro');
        $this->setStock($coco, $matriz, 100);
        $this->setStock($agua, $guayabitos, 100);

        $deMatriz = $this->requestTransfer($matriz, $this->location, $coco, 5);
        $deGuayabitos = $this->requestTransfer($guayabitos, $this->location, $agua, 2);

        $url = '/api/auth/admin/inventory-transfers?per_page=20&search=';

        $this->actingInBranch($this->user)->getJson($url . urlencode('Agua'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $deGuayabitos->id);

        $this->actingInBranch($this->user)->getJson($url . urlencode('Matriz'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $deMatriz->id);

        $this->actingInBranch($this->user)->getJson($url . urlencode($deGuayabitos->transfer_number))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $deGuayabitos->id);
    }

    public function test_paginated_list_has_the_standard_meta_for_applist(): void
    {
        $matriz = $this->createBranch();
        $coco = $this->createProduct();
        $this->setStock($coco, $matriz, 100);

        foreach ([1, 2, 3] as $qty) {
            $this->requestTransfer($matriz, $this->location, $coco, $qty);
        }

        // Antes la meta no traía from/to: "Mostrando 0 - 0 de 3".
        $this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/inventory-transfers?paginated=1&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 3)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    }
}
