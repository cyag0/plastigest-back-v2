<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Lo aprobado va aparte de lo pedido: antes aprobar menos reescribía lo pedido
 * y ya no se sabía cuánto se había pedido.
 */
class TransferApprovedQuantityTest extends TestCase
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

    public function test_approving_less_keeps_what_was_requested(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->requestTransfer($this->matriz, $this->location, $coco, 10);
        $detail = $transfer->details->first();

        $this->actingInBranch($this->user, $this->matriz)
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/approve", [
                'items' => [['detail_id' => $detail->id, 'quantity_requested' => 8]],
            ])->assertOk();

        $line = $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/inventory-transfers/{$transfer->id}")
            ->assertOk()->json('data.details.0');

        $this->assertEquals(10, $line['quantity_requested']);
        $this->assertEquals(8, $line['quantity_approved']);
    }

    public function test_lines_not_adjusted_are_approved_as_requested(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->approveTransfer($this->requestTransfer($this->matriz, $this->location, $coco, 6));

        $this->assertEquals(6, (float) $transfer->details->first()->quantity_approved);
        $this->assertEquals(6, (float) $transfer->details->first()->quantity_requested);
    }

    public function test_a_pending_line_has_no_approved_quantity_yet(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->setStock($coco, $this->matriz, 50);
        $transfer = $this->requestTransfer($this->matriz, $this->location, $coco, 4);

        $line = $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/inventory-transfers/{$transfer->id}")
            ->assertOk()->json('data.details.0');

        $this->assertNull($line['quantity_approved']);
        $this->assertEquals(4, $line['quantity_requested']);
    }
}
