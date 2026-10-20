<?php

namespace Tests\Feature\Logic;

use App\Enums\SaleStatus;
use App\Models\Admin\Location;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Services\CashMovementService;
use App\Services\CashRegisterSessionService;
use App\Services\SalesOrderStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Ventas, pedidos y caja: cancelar, preparar y cobrar deciden con la fila
 * bloqueada (los casos "stale" simulan la segunda de dos peticiones
 * simultáneas), y el dinero solo entra al turno de la sucursal donde ocurre.
 */
class SalesAndCashLockingTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Releer para traer is_active (default de la columna): el middleware
        // rechaza cuentas inactivas.
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
    }

    private function openShiftAt(Location $location): CashRegisterSession
    {
        $register = CashRegister::create([
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'name' => 'Caja ' . $location->id,
            'code' => 'C' . uniqid(),
            'is_active' => true,
        ]);

        return app(CashRegisterSessionService::class)->open($register, 0, $this->user);
    }

    /** Turno del usuario en la sucursal del contexto (cobrar en efectivo lo exige). */
    private ?CashRegisterSession $shift = null;

    private function sell(Product $product, float $qty, float $price): int
    {
        $this->shift ??= $this->openShiftAt($this->location);

        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/sales', [
                'payment_method' => 'cash',
                'paid_amount' => $qty * $price,
                'details' => [[
                    'product_id' => $product->id,
                    'unit_id' => $product->unit_id,
                    'quantity' => $qty,
                    'unit_price' => $price,
                ]],
            ])->assertCreated()->json('data.id');
    }

    private function order(Product $product, float $qty, float $price, string $status = 'pending', string $mode = 'delivery'): SalesOrder
    {
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'order_number' => 'SOR-' . uniqid(),
            'order_date' => now()->toDateString(),
            'channel' => 'admin',
            'service_mode' => $mode,
            'status' => $status,
            'subtotal' => $qty * $price,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $qty * $price,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'requested_quantity' => $qty,
            'unit_price' => $price,
            'line_subtotal' => $qty * $price,
            'line_total' => $qty * $price,
        ]);

        return $order;
    }

    private function reservedStock(Product $product): float
    {
        return (float) DB::table('product_location')
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->value('reserved_stock');
    }

    // ─── Ventas ──────────────────────────────────────────────────────────────

    public function test_cancelling_a_stale_sale_does_not_revert_stock_or_cash_twice(): void
    {
        $galon = $this->createProduct([], 10);
        $saleId = $this->sell($galon, 2, 65);
        $stale = Sale::findOrFail($saleId); // la "segunda petición", cargada cerrada

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/sales/{$saleId}/cancel")
            ->assertOk();

        $this->assertFalse($stale->transitionTo(SaleStatus::CANCELLED), 'la segunda cancelación no hace nada');
        $this->assertSame(SaleStatus::CANCELLED, $stale->status);

        $this->assertEquals(10, $this->stockOf($galon), 'el stock se devuelve una sola vez');
        $this->assertSame(1, CashMovement::where('source_type', 'sale_cancellation')->where('source_id', $saleId)->count());
    }

    public function test_sale_refund_never_lands_in_a_shift_of_another_branch(): void
    {
        $galon = $this->createProduct([], 10);
        $saleId = $this->sell($galon, 1, 65); // venta en efectivo en el turno de la sucursal del contexto

        // El turno de la venta se cierra y quien cancela solo tiene turno en otra sucursal.
        app(CashRegisterSessionService::class)->close($this->shift, 65, null, $this->user);
        $otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $foreignShift = $this->openShiftAt($otherBranch);

        // La devolución en efectivo exige un turno abierto en la sucursal de la
        // venta: ni el de otra sucursal ni fuera de turno.
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/sales/{$saleId}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_open_session');

        $this->assertSame(0, CashMovement::where('source_type', 'sale_cancellation')->where('source_id', $saleId)->count());
        $this->assertSame(0, CashMovement::where('cash_register_session_id', $foreignShift->id)->count());
        $this->assertSame(SaleStatus::CLOSED, Sale::findOrFail($saleId)->status);
        $this->assertEquals(9, $this->stockOf($galon), 'la venta no se cancela a medias');
    }

    public function test_expense_uses_only_a_shift_of_its_own_branch(): void
    {
        $otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $foreignShift = $this->openShiftAt($otherBranch);

        $expense = Expense::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'category' => array_key_first(Expense::getCategories()), 'amount' => 50, 'payment_method' => 'efectivo',
            'paid_from_cash' => true, 'description' => 'Gas', 'expense_date' => now()->toDateString(),
        ]);
        // Su turno es de otra sucursal: de aquí no sale de ninguna caja.
        try {
            CashMovementService::syncExpense($expense);
            $this->fail('Sin turno abierto en la sucursal del gasto, el efectivo no sale de ninguna caja.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('no_open_session', $e->errorCode());
        }
        $this->assertSame(0, CashMovement::where('cash_register_session_id', $foreignShift->id)->count());

        // En su propia sucursal sí sale de esa caja.
        $local = Expense::create([
            'company_id' => $this->company->id, 'location_id' => $otherBranch->id, 'user_id' => $this->user->id,
            'category' => array_key_first(Expense::getCategories()), 'amount' => 30, 'payment_method' => 'efectivo',
            'paid_from_cash' => true, 'description' => 'Hielo', 'expense_date' => now()->toDateString(),
        ]);
        CashMovementService::syncExpense($local);
        $this->assertSame($foreignShift->id, (int) CashMovement::where('source_type', 'expense')->where('source_id', $local->id)->value('cash_register_session_id'));
    }

    // ─── Pedidos ─────────────────────────────────────────────────────────────

    public function test_preparing_a_stale_order_does_not_reserve_twice(): void
    {
        $frijol = $this->createProduct([], 10);
        $order = $this->order($frijol, 4, 10);
        $stale = SalesOrder::with('details')->findOrFail($order->id);

        $service = app(SalesOrderStockService::class);
        $service->reserveOrder(SalesOrder::with('details')->findOrFail($order->id));
        $order->fresh()->transitionTo(\App\Enums\SalesOrderStatus::PREPARING);
        $this->assertEquals(4, $this->reservedStock($frijol));

        try {
            $service->reserveOrder($stale);
            $this->fail('Reservar dos veces debe rechazarse.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertEquals(4, $this->reservedStock($frijol));
    }

    public function test_checking_out_a_stale_order_creates_a_single_sale(): void
    {
        $frijol = $this->createProduct([], 10);
        $order = $this->order($frijol, 3, 10, 'pending', 'counter');
        $stale = SalesOrder::with('details')->findOrFail($order->id);

        $service = app(SalesOrderStockService::class);
        $service->checkoutOrder(SalesOrder::with('details')->findOrFail($order->id), ['payment_method' => 'card', 'paid_amount' => 30]);

        try {
            $service->checkoutOrder($stale, ['payment_method' => 'card', 'paid_amount' => 30]);
            $this->fail('Cobrar dos veces debe rechazarse.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame(1, Sale::count());
        $this->assertEquals(7, $this->stockOf($frijol), 'el stock se descuenta una sola vez');
    }

    public function test_checkout_registers_the_cash_in_the_shift_of_the_order_branch(): void
    {
        $frijol = $this->createProduct([], 10);
        $shift = $this->openShiftAt($this->location);
        $order = $this->order($frijol, 2, 190, 'pending', 'counter'); // total 380

        // Pagan con 500: 380 entran a caja y 120 son cambio.
        $sale = app(SalesOrderStockService::class)->checkoutOrder($order->fresh('details'), [
            'payment_method' => 'cash',
            'paid_amount' => 500,
        ]);

        $this->assertDatabaseHas('cash_movements', [
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'type' => 'income',
            'payment_method' => 'cash',
            'amount' => 380,
            'cash_register_session_id' => $shift->id,
        ]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'method' => 'cash', 'amount' => 380, 'tendered' => 500, 'change' => 120]);
        $this->assertSame($shift->id, (int) $sale->cash_register_session_id);
        $this->assertEquals(380, (float) $sale->paid_amount);
        $this->assertSame('cash', $sale->payment_method);

        $summary = app(CashRegisterSessionService::class)->summary($shift->fresh());
        $this->assertEquals(380, $summary['expected_cash'], 'el efectivo esperado del turno incluye el cobro');
        $this->assertSame(1, $summary['sales_count']);
    }

    public function test_checkout_does_not_use_a_shift_of_another_branch(): void
    {
        $frijol = $this->createProduct([], 10);
        $foreignShift = $this->openShiftAt(Location::factory()->create(['company_id' => $this->company->id]));
        $order = $this->order($frijol, 1, 50, 'pending', 'counter');

        // En efectivo exige turno en la sucursal del pedido: el de otra no cuenta.
        try {
            app(SalesOrderStockService::class)->checkoutOrder($order->fresh('details'), ['payment_method' => 'cash']);
            $this->fail('Cobrar en efectivo sin turno en la sucursal del pedido debe rechazarse.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('no_open_session', $e->errorCode());
        }
        $this->assertSame(0, Sale::count());

        // Con tarjeta se cobra fuera de turno, nunca en el turno de otra sucursal.
        $sale = app(SalesOrderStockService::class)->checkoutOrder($order->fresh('details'), ['payment_method' => 'card']);

        $movement = CashMovement::where('source_type', 'sale')->where('source_id', $sale->id)->firstOrFail();
        $this->assertEquals(50, (float) $movement->amount);
        $this->assertNull($movement->cash_register_session_id);
        $this->assertNull($sale->cash_register_session_id);
        $this->assertSame(0, CashMovement::where('cash_register_session_id', $foreignShift->id)->count());
    }
}
