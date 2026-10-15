<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\StockLedgerReason;
use App\Services\MovementService;
use App\Support\StockContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Historial de existencias de un producto (inventory/products/{id}/movements).
 */
class ProductMovementScopeTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    public function test_branch_user_only_sees_their_branch_whatever_location_they_ask_for(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct();
        $movements = app(MovementService::class);
        $context = StockContext::for(StockLedgerReason::ADJUSTMENT);
        $movements->increment($this->location->id, $coco->id, $coco->unit_id, 5, null, $context);
        $movements->increment($otra->id, $coco->id, $coco->unit_id, 7, null, $context);

        $cajero = $this->createBranchUser($this->location, ['inventory_list']);
        $url = "/api/auth/admin/products/{$coco->id}/movements";

        // Antes: ?location_id= de otra sucursal mostraba su historial, y sin
        // filtro salía el de todas.
        $this->actingInBranch($cajero)->getJson("{$url}?location_id={$otra->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('product.current_stock', 5);

        $this->actingInBranch($cajero)->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('product.current_stock', 5);
    }

    public function test_all_branches_user_can_filter_by_branch_or_see_every_branch(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct();
        $movements = app(MovementService::class);
        $context = StockContext::for(StockLedgerReason::ADJUSTMENT);
        $movements->increment($this->location->id, $coco->id, $coco->unit_id, 5, null, $context);
        $movements->increment($otra->id, $coco->id, $coco->unit_id, 7, null, $context);

        $url = "/api/auth/admin/products/{$coco->id}/movements";

        $this->actingInBranch($this->user)->getJson("{$url}?location_id={$otra->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('product.current_stock', 7);

        $this->actingInBranch($this->user)->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('product.current_stock', 12);

        [, $ajena] = $this->createCompanyWithBranch();
        $this->actingInBranch($this->user)->getJson("{$url}?location_id={$ajena->id}")->assertNotFound();
    }
}
