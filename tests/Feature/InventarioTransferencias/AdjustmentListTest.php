<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\Admin\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Listado "Ajustes de inventario" como lo pide la app (AppList).
 */
class AdjustmentListTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    private function adjust(Product $product, string $direction, float $qty, ?Location $location = null, string $reason = 'damage'): void
    {
        $this->actingInBranch($this->user, $location)
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $product->id,
                    'direction' => $direction,
                    'quantity' => $qty,
                    'unit_id' => $product->unit_id,
                    'reason_code' => $reason,
                ]],
            ])->assertCreated();
    }

    public function test_list_has_the_standard_pagination_that_applist_reads(): void
    {
        $coco = $this->createProduct([], 100);
        foreach ([1, 2, 3] as $qty) {
            $this->adjust($coco, 'out', $qty);
        }

        // Antes venía en "pagination" (AppList lee "meta") y no había pager:
        // solo se veían los más recientes.
        $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/inventory-adjustments?location_id={$this->location->id}&paginated=1&per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 3)
            ->assertJsonStructure(['links' => ['next', 'prev']])
            ->assertJsonMissingPath('pagination');
    }

    public function test_type_filter_and_search_work(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'code' => 'COCO-1'], 100);
        $agua = $this->createProduct(['name' => 'Agua de coco', 'code' => 'AGUA-1'], 100, 'Litro');
        $this->adjust($coco, 'out', 2);
        $this->adjust($coco, 'in', 1, null, 'found');
        $this->adjust($agua, 'out', 3);

        $url = "/api/auth/admin/inventory-adjustments?location_id={$this->location->id}&paginated=1&per_page=20";

        // "Tipo": Entrada / Salida. Antes se ignoraba.
        $this->actingInBranch($this->user)->getJson("{$url}&direction=in")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.direction', 'in');
        $this->actingInBranch($this->user)->getJson("{$url}&direction=out")
            ->assertOk()->assertJsonCount(2, 'data');

        // Buscar por producto (nombre o código). Antes se ignoraba.
        $this->actingInBranch($this->user)->getJson("{$url}&search=" . urlencode('Agua'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.name', 'Agua de coco');
        $this->actingInBranch($this->user)->getJson("{$url}&search=COCO-1")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_branch_user_only_lists_their_branch_whatever_location_they_send(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct([], 100);
        $this->setStock($coco, $otra, 100);
        $this->adjust($coco, 'out', 1);
        $this->adjust($coco, 'out', 2, $otra);

        $cajero = $this->createBranchUser($this->location, ['inventory_list']);

        $this->actingInBranch($cajero)
            ->getJson("/api/auth/admin/inventory-adjustments?location_id={$otra->id}&paginated=1&per_page=20")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_id', $this->location->id);

        // Quien ve todas las sucursales elige.
        $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/inventory-adjustments?location_id={$otra->id}&paginated=1&per_page=20")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_id', $otra->id);
    }
}
