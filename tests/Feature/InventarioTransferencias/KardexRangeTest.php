<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\Admin\Location;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Kárdex general: sin fechas muestra los últimos 30 días y cuenta una sola
 * vez por petición; con fechas respeta lo pedido.
 */
class KardexRangeTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const URL = '/api/auth/admin/inventory-movements';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function ledger(Product $product, Location $location, string $direction, float $qty, int $daysAgo): void
    {
        $entry = StockLedgerEntry::create([
            'company_id' => $this->company->id, 'location_id' => $location->id, 'product_id' => $product->id,
            'direction' => $direction, 'reason' => $direction === 'in' ? 'purchase' : 'sale',
            'quantity' => $qty, 'previous_stock' => 0, 'new_stock' => 0,
        ]);
        $entry->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    public function test_without_dates_it_shows_the_last_30_days(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $this->ledger($coco, $this->location, 'in', 100, 45);
        $this->ledger($coco, $this->location, 'out', 10, 29);
        $this->ledger($coco, $this->location, 'out', 5, 3);
        $this->ledger($coco, $this->location, 'in', 2, 0);

        $this->actingInBranch($this->user)->getJson(self::URL . '?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 2)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.entries_out', 2)
            ->assertJsonPath('range.default', true)
            ->assertJsonPath('range.date_from', now()->subDays(29)->toDateString())
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['filters' => ['reasons', 'users', 'locations', 'can_choose_location']]);

        $this->actingInBranch($this->user)->getJson(self::URL . '?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonCount(1, 'data');

        // Con un producto, los totales son del mismo rango (2 - 15).
        $this->actingInBranch($this->user)->getJson(self::URL . "?product_id={$coco->id}")
            ->assertOk()
            ->assertJsonPath('summary.product.in', 2)
            ->assertJsonPath('summary.product.out', 15)
            ->assertJsonPath('summary.product.net', -13);

        // Con fecha se respeta lo pedido, aunque sea más atrás.
        $this->actingInBranch($this->user)->getJson(self::URL . '?date_from=' . now()->subDays(60)->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('range.default', false);
        $this->actingInBranch($this->user)->getJson(self::URL . '?date_to=' . now()->subDays(40)->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_csv_uses_the_same_default_range_as_the_list(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'code' => 'COC-1']);
        $this->ledger($coco, $this->location, 'in', 100, 45);
        $this->ledger($coco, $this->location, 'out', 7, 1);

        $url = $this->actingInBranch($this->user)->getJson(self::URL . '/export-url')->assertOk()->json('url');
        $this->assertStringContainsString('date_from=' . now()->subDays(29)->toDateString(), $url);

        $csv = $this->get($url)->assertOk()->streamedContent();
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'Encabezado y un movimiento');
    }
}
