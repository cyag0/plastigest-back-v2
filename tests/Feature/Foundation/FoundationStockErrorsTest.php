<?php

namespace Tests\Feature\Foundation;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\CashRegister;
use App\Models\ProductPackage;
use App\Models\Sale;
use App\Services\MovementService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Errores de negocio con forma estable: stock insuficiente (422 con code
 * "insufficient_stock" y details) y reglas de negocio en general (422 con
 * {message, code}), lleguen al manejador global o a errorResponse().
 */
class FoundationStockErrorsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const CASHIER = ['sales_pos', 'sales_list', 'sales_read', 'products_list', 'products_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Cajero con turno abierto en la sucursal del contexto. */
    private function cashierWithShift(array $extraPermissions = []): void
    {
        $cashier = $this->createBranchUser($this->location, array_merge(self::CASHIER, $extraPermissions));
        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'name' => 'Caja 1', 'code' => 'C1',
        ]);

        $this->actingInBranch($cashier)
            ->postJson('/api/auth/admin/cash-register-sessions/open', ['cash_register_id' => $register->id, 'opening_amount' => 500])
            ->assertCreated();
    }

    // ─── Punto de venta y pantalla de ventas anterior ───────────────────────

    public function test_pos_sale_without_enough_stock_is_a_422_that_names_the_product(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 20], 5);
        $this->cashierWithShift();

        // Antes: 500 "Server Error" y el cajero no sabía por qué.
        $this->postJson('/api/auth/admin/pos/sales', [
            'details' => [['product_id' => $coco->id, 'quantity' => 6]],
            'payments' => [['method' => 'cash', 'amount' => 120, 'tendered' => 200]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 5 Pieza, solicitado: 6 Pieza.')
            ->assertJsonPath('details.product_id', $coco->id)
            ->assertJsonPath('details.product_name', 'Coco entero')
            ->assertJsonPath('details.location_id', $this->location->id)
            ->assertJsonPath('details.available', 5)
            ->assertJsonPath('details.requested', 6)
            ->assertJsonPath('details.unit', 'Pieza');

        $this->assertEquals(5, $this->stockOf($coco));
        $this->assertSame(0, Sale::count(), 'la venta rechazada no deja nada');
        $this->assertSame(0, DB::table('cash_movements')->count());
    }

    public function test_package_sale_reports_the_quantity_in_base_units(): void
    {
        $coca = $this->createProduct(['name' => 'Coca 600', 'sale_price' => 18], 10);
        $pack = ProductPackage::create([
            'product_id' => $coca->id, 'company_id' => $this->company->id, 'package_name' => 'Caja 12',
            'barcode' => '7501055300099', 'quantity_per_package' => 12, 'unit_id' => $coca->unit_id, 'sale_price' => 190, 'is_active' => true,
        ]);
        $this->cashierWithShift();

        $this->postJson('/api/auth/admin/pos/sales', [
            'details' => [['product_id' => $coca->id, 'package_id' => $pack->id, 'quantity' => 1]],
            'payments' => [['method' => 'card', 'amount' => 190]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('details.available', 10)
            ->assertJsonPath('details.requested', 12);

        $this->assertEquals(10, $this->stockOf($coca));
    }

    public function test_old_sales_screen_without_enough_stock_is_a_422_instead_of_a_500(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 20], 1);
        $this->cashierWithShift(['sales_create']);

        // Pasa por CrudController::store → errorResponse(): antes 500 con el
        // motivo escondido en `error` (y repetido: "Stock insuficiente para
        // 'X'. Stock insuficiente para "X"...").
        $this->postJson('/api/auth/admin/sales', [
            'payment_method' => 'card',
            'details' => [['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity' => 3, 'unit_price' => 20]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Stock insuficiente para "Coco entero". Disponible: 1 Pieza, solicitado: 3 Pieza.');

        $this->assertEquals(1, $this->stockOf($coco));
        $this->assertSame(0, Sale::count());
    }

    // ─── MovementService ─────────────────────────────────────────────────────

    public function test_decrement_throws_insufficient_stock_with_the_same_message_as_before(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 5);

        try {
            app(MovementService::class)->decrement($this->location->id, $coco->id, $coco->unit_id, 6);
            $this->fail('Debió rechazar la salida.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('Stock insuficiente para "Coco entero". Disponible: 5 Pieza, solicitado: 6 Pieza.', $e->getMessage());
            $this->assertSame('insufficient_stock', $e->errorCode());
            $this->assertSame($coco->id, $e->productId);
            $this->assertSame($this->location->id, $e->locationId);
            $this->assertSame(5.0, $e->available);
            $this->assertSame(6.0, $e->requested);
            // Quien atrapa Exception (ProductionOrderController, transferencias,
            // conteos...) la sigue atrapando.
            $this->assertInstanceOf(\Exception::class, $e);
        }

        $this->assertEquals(5, $this->stockOf($coco));
        $this->assertSame(0, DB::table('stock_ledger_entries')->count());
    }

    public function test_product_without_stock_row_is_insufficient_stock_with_zero_available(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero']);
        DB::table('product_location')->where('product_id', $coco->id)->delete();

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Disponible: 0 Pieza, solicitado: 2 Pieza.');

        app(MovementService::class)->decrement($this->location->id, $coco->id, $coco->unit_id, 2);
    }

    public function test_a_failed_conditional_update_is_reported_as_insufficient_stock(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 5);

        // Simula la validación con un saldo viejo (lo que pasaba con la
        // relectura sin bloqueo): el UPDATE condicional es la última barrera y
        // antes respondía "No se pudo decrementar el stock para el producto ID N".
        $service = new class extends MovementService {
            protected function validateStock(int $locationId, int $productId, float $quantity, float $currentStock): void {}
        };

        try {
            $service->decrement($this->location->id, $coco->id, $coco->unit_id, 6);
            $this->fail('Debió rechazar la salida.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(5.0, $e->available);
            $this->assertSame(6.0, $e->requested);
        }

        $this->assertEquals(5, $this->stockOf($coco));
    }

    public function test_a_decrement_that_fits_still_works(): void
    {
        $coco = $this->createProduct([], 5);

        app(MovementService::class)->decrement($this->location->id, $coco->id, $coco->unit_id, 5);

        $this->assertEquals(0, $this->stockOf($coco));
    }

    // ─── Reglas de negocio en general ────────────────────────────────────────

    public function test_business_rule_exception_renders_message_and_code_and_is_not_logged_as_error(): void
    {
        Route::middleware('api')->get('/api/foundation-test/business-rule', function () {
            throw new BusinessRuleException('Abre un turno de caja para cobrar en efectivo', 'no_open_session');
        });

        $this->getJson('/api/foundation-test/business-rule')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Abre un turno de caja para cobrar en efectivo', 'code' => 'no_open_session']);

        $handler = app(ExceptionHandler::class);
        $this->assertFalse($handler->shouldReport(new BusinessRuleException('x', 'closed_session')));
        $this->assertFalse($handler->shouldReport(new InsufficientStockException(1, 0, 1)));
    }

    public function test_insufficient_stock_without_product_data_still_has_a_readable_message(): void
    {
        $e = new InsufficientStockException(99, 1.23456, 2);

        $this->assertSame('Stock insuficiente para "producto ID 99". Disponible: 1.235, solicitado: 2.', $e->getMessage());
        $this->assertSame(422, $e->render()->getStatusCode());
        $this->assertSame(1.235, $e->render()->getData(true)['details']['available']);
    }
}
