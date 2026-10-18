<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El detalle dice quién aprobó, envió, recibió, rechazó o canceló (antes
 * esos *_by_user llegaban siempre en null y el historial no tenía nombres).
 */
class TransferStepUsersTest extends TestCase
{
    use RefreshDatabase, CreatesTenant, BuildsTransfers;

    private Location $matriz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
        $this->matriz = $this->createBranch(['name' => 'Matriz']);
    }

    private function detail(InventoryTransfer $transfer): array
    {
        return $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/inventory-transfers/{$transfer->id}")
            ->assertOk()->json('data');
    }

    public function test_the_detail_names_who_approved_and_shipped(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);

        $transfer = $this->shipTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 10));
        $data = $this->detail($transfer);

        $this->assertSame(['id' => $this->user->id, 'name' => $this->user->name], $data['approved_by_user']);
        $this->assertSame(['id' => $this->user->id, 'name' => $this->user->name], $data['shipped_by_user']);
        $this->assertNull($data['received_by_user']);
    }

    public function test_the_detail_names_who_cancelled(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->requestTransfer($this->matriz, $this->location, $coco, 5);

        $this->actingAs($this->user);
        $transfer->cancel('Ya no hace falta');

        $data = $this->detail($transfer->fresh());
        $this->assertSame($this->user->name, $data['cancelled_by_user']['name'] ?? null);
    }
}
