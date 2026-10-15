<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\StockLedgerReason;
use App\Models\StockLedgerEntry;
use App\Services\MovementService;
use App\Support\StockContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Motor de stock: una fila de existencia por producto y sucursal y
 * cantidades a la escala de la existencia.
 */
class StockEngineTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->movements = app(MovementService::class);
    }

    private function rowsOf(int $productId, int $locationId): int
    {
        return DB::table('product_location')->where('product_id', $productId)->where('location_id', $locationId)->count();
    }

    public function test_the_stock_row_is_unique_per_product_and_branch(): void
    {
        $coco = $this->createProduct([], 10);

        $this->expectException(QueryException::class);
        DB::table('product_location')->insert([
            'product_id' => $coco->id, 'location_id' => $this->location->id, 'current_stock' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_migration_stops_on_duplicates_instead_of_merging_them(): void
    {
        // Una tabla temporal con el mismo nombre tapa a la real en esta
        // conexión (y, a diferencia de quitar el índice, no confirma la
        // transacción de la prueba): así se ve qué haría la migración con una
        // base que ya trae filas repetidas.
        DB::statement('CREATE TEMPORARY TABLE product_location (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED, location_id BIGINT UNSIGNED, current_stock DECIMAL(12,3))');

        try {
            DB::table('product_location')->insert([
                ['product_id' => 7, 'location_id' => 3, 'current_stock' => 10],
                ['product_id' => 7, 'location_id' => 3, 'current_stock' => 4],
                ['product_id' => 8, 'location_id' => 3, 'current_stock' => 1],
            ]);

            $migration = require database_path('migrations/2026_10_15_210000_add_unique_product_location_index.php');

            try {
                $migration->up();
                $this->fail('La migración debía detenerse por las filas repetidas.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('producto 7 en sucursal 3 (2 filas)', $e->getMessage());
                $this->assertStringNotContainsString('producto 8', $e->getMessage());
            }

            // Nada se fusionó ni se borró.
            $this->assertSame(3, DB::table('product_location')->count());
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS product_location');
        }
    }

    public function test_the_first_stock_of_a_product_in_a_branch_creates_a_single_row(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct([], 0);
        DB::table('product_location')->where('product_id', $coco->id)->where('location_id', $otra->id)->delete();

        $this->movements->increment($otra->id, $coco->id, $coco->unit_id, 5, null, StockContext::for(StockLedgerReason::PURCHASE));
        $this->movements->increment($otra->id, $coco->id, $coco->unit_id, 3, null, StockContext::for(StockLedgerReason::PURCHASE));

        $this->assertSame(1, $this->rowsOf($coco->id, $otra->id));
        $this->assertEquals(8, $this->stockOf($coco, $otra));
        $this->assertEquals(
            [[0, 5], [5, 8]],
            StockLedgerEntry::where('product_id', $coco->id)->where('location_id', $otra->id)->orderBy('id')
                ->get()->map(fn($e) => [(float) $e->previous_stock, (float) $e->new_stock])->all()
        );
    }

    public function test_quantities_are_rounded_to_the_stock_scale_so_the_ledger_matches(): void
    {
        $azucar = $this->createProduct([], 0, 'Kilogramo');

        // 123.4 g = 0.1234 kg; la existencia guarda 3 decimales.
        $this->movements->increment($this->location->id, $azucar->id, $this->unitId('Gramo'), 123.4, null, StockContext::for(StockLedgerReason::PURCHASE));
        $this->movements->decrement($this->location->id, $azucar->id, $azucar->unit_id, 0.0006, null, StockContext::for(StockLedgerReason::SALE));

        $this->assertEquals(0.122, $this->stockOf($azucar));
        $entries = StockLedgerEntry::where('product_id', $azucar->id)->orderBy('id')->get();
        // Antes el libro mayor guardaba 0.1234 y 0.0006 mientras la existencia
        // quedaba en 0.123 y 0.122: la suma ya no cuadraba.
        $this->assertEquals([0.123, 0.001], $entries->map(fn($e) => (float) $e->quantity)->all());
        $this->assertEquals(0.122, (float) $entries->last()->new_stock);
        $this->assertEquals(
            (float) $entries->last()->new_stock,
            (float) $entries->first()->previous_stock + (float) $entries->first()->quantity - (float) $entries->last()->quantity
        );
    }
}
