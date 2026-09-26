<?php

namespace Tests\Feature\Flows;

use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\PurchaseV2;
use App\Models\Sale;
use App\Services\CashMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Venta → caja → corte. Puntos 4, 5.1, 9 y 10 del checklist.
 */
class CashFlowsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function sell(int $productId, float $qty, float $price, string $method = 'cash', ?float $paid = null): int
    {
        return $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/sales', [
                'payment_method' => $method,
                'paid_amount' => $paid ?? $qty * $price,
                'details' => [[
                    'product_id' => $productId,
                    'unit_id' => $this->unitId('Pieza'),
                    'quantity' => $qty,
                    'unit_price' => $price,
                ]],
            ])->assertCreated()->json('data.id');
    }

    private function cashNet(): float
    {
        return (float) CashMovement::where('location_id', $this->location->id)
            ->selectRaw("SUM(CASE WHEN type='income' THEN amount ELSE -amount END) AS net")
            ->value('net');
    }

    private function dayStats(string $date): array
    {
        return $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/cash-movements/stats?date=' . $date)
            ->assertOk()->json('date_stats');
    }

    // ─── Ventas ──────────────────────────────────────────────────────────────

    public function test_cash_sale_moves_stock_and_registers_income(): void
    {
        $galon = $this->createProduct([], 10);

        $this->sell($galon->id, 2, 65);

        $this->assertEquals(8, $this->stockOf($galon));
        $this->assertEquals(130, $this->cashNet());
    }

    public function test_credit_sale_with_zero_payment_does_not_touch_cash(): void
    {
        $galon = $this->createProduct([], 10);

        $saleId = $this->sell($galon->id, 1, 65, 'credit', 0);

        $this->assertEquals(0, $this->cashNet());
        $this->assertNotSame('paid', Sale::find($saleId)->payment_status ?? null);
    }

    public function test_cancelling_a_sale_reverses_stock_and_cash(): void
    {
        $galon = $this->createProduct([], 10);
        $saleId = $this->sell($galon->id, 2, 65);

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/sales/{$saleId}/cancel")
            ->assertOk();

        $this->assertEquals(10, $this->stockOf($galon));
        $this->assertEquals(0, $this->cashNet(), 'el cobro se devuelve en caja');
    }

    public function test_sales_stats_exclude_cancelled_sales(): void
    {
        $galon = $this->createProduct([], 10);
        $this->sell($galon->id, 2, 65);
        $cancelled = $this->sell($galon->id, 1, 65);
        $this->withHeaders($this->tenantHeaders())->postJson("/api/auth/admin/sales/{$cancelled}/cancel");

        $today = now()->toDateString();
        $this->withHeaders($this->tenantHeaders())
            ->getJson("/api/auth/admin/sales/stats?start_date={$today}&end_date={$today}")
            ->assertOk()
            ->assertJsonPath('data.overview.total_amount', 130)
            ->assertJsonPath('data.overview.cancelled_count', 1);
    }

    // ─── Corte de caja (punto 10) ────────────────────────────────────────────

    private function movement(string $type, float $amount, string $method, string $date): void
    {
        CashMovement::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'type' => $type, 'amount' => $amount, 'concept' => 't', 'payment_method' => $method,
            'source_type' => 'manual', 'movement_date' => $date,
        ]);
    }

    public function test_opening_balance_starts_from_last_closing_count(): void
    {
        // Día 1: venta en efectivo y una compra grande: el historial queda en -4000.
        $this->movement('income', 1000, 'cash', '2026-09-20');
        $this->movement('expense', 5000, 'cash', '2026-09-20');
        $this->movement('income', 800, 'card', '2026-09-20');

        $this->assertEquals(-4000, $this->dayStats('2026-09-21')['opening_balance'], 'sin cortes, se usa el historial');

        // El cajero hace el corte del día 1 y cuenta 300 físicos.
        CashClosing::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'closing_date' => '2026-09-20', 'opening_balance' => 0, 'total_income' => 1800, 'total_expense' => 5000,
            'expected_balance' => -4000, 'physical_count' => 300, 'difference' => 0, 'total_cash' => -4000,
            'total_card' => 800, 'total_transfer' => 0, 'total_other' => 0, 'movements_count' => 3, 'status' => 'closed',
        ]);
        $this->movement('income', 200, 'cash', '2026-09-21');
        $this->movement('income', 150, 'card', '2026-09-21');

        $stats = $this->dayStats('2026-09-21');
        $this->assertEquals(300, $stats['opening_balance'], 'ya no arrastra el negativo');
        $this->assertEquals(['cash' => 300], $stats['opening_by_method'], 'tarjeta no arrastra saldo');
        $this->assertEquals(350, $stats['date_income'], 'los ingresos del día incluyen todos los métodos');

        $this->assertEquals(500, $this->dayStats('2026-09-22')['opening_balance']);
    }

    // ─── Gastos y compras: ¿salió de la caja? ────────────────────────────────

    public function test_expense_paid_from_cash_is_synced_with_the_register(): void
    {
        $payload = fn(array $o = []) => array_merge([
            'category' => array_key_first(Expense::getCategories()),
            'amount' => 50,
            'payment_method' => 'efectivo',
            'description' => 'Gas',
            'expense_date' => now()->toDateString(),
            // La app lo manda como texto desde FormData.
            'paid_from_cash' => 'true',
        ], $o);

        $id = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/expenses', $payload())->assertCreated()->json('data.id');
        $this->assertEquals(-50, $this->cashNet());

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/expenses/{$id}", ['amount' => 70])->assertOk();
        $this->assertEquals(-70, $this->cashNet(), 'editar recalcula el egreso');

        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/expenses/{$id}")->assertOk();
        $this->assertEquals(0, $this->cashNet(), 'eliminar lo quita de la caja');

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/expenses', $payload(['amount' => 999, 'paid_from_cash' => 'false']))
            ->assertCreated();
        $this->assertEquals(0, $this->cashNet(), 'pagado por fuera no toca la caja');
    }

    public function test_purchase_not_paid_from_cash_does_not_leave_the_register(): void
    {
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'purchase_number' => 'PUR-TEST', 'purchase_date' => now(), 'status' => PurchaseV2::STATUS_RECEIVED,
            'total' => 3000, 'payment_method' => 'cash', 'paid_from_cash' => false, 'user_id' => $this->user->id,
        ]);
        CashMovementService::fromPurchaseV2($purchase);
        $this->assertEquals(0, $this->cashNet());

        $purchase->update(['paid_from_cash' => true]);
        CashMovementService::fromPurchaseV2($purchase->fresh());
        $this->assertEquals(-3000, $this->cashNet());
    }
}
