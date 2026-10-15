<?php

namespace Tests\Feature\Revision;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\CashMovementService;
use App\Services\CashRegisterSessionService;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * Decisión conservadora (C14): devolver el efectivo de una venta exige un
 * turno abierto en esa sucursal, también para el dueño. El 422 ahora dice qué
 * hacer: abrir un turno o pedirle al cajero en turno que cancele la venta.
 */
class RevisionCashRefundMessageTest extends VentasCajaTestCase
{
    public function test_cancelling_a_cash_sale_without_an_open_shift_explains_what_to_do(): void
    {
        $cashier = $this->cashier(['sales_update']);
        $product = $this->createProduct(['sale_price' => 50], 10);

        $saleId = $this->posSale($cashier, [['product_id' => $product->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 100, 'tendered' => 100]])
            ->assertCreated()
            ->json('data.id');

        // El cajero cierra su turno; al día siguiente el dueño (sin turno)
        // quiere cancelar la venta, y el cajero tampoco tiene turno.
        $sessions = app(CashRegisterSessionService::class);
        $sessions->close($sessions->current($cashier), 600, null, $cashier);

        foreach ([$this->user, $cashier] as $actor) {
            $this->actingInBranch($actor)
                ->postJson("/api/auth/admin/sales/{$saleId}/cancel")
                ->assertStatus(422)
                ->assertJsonPath('code', 'no_open_session')
                ->assertJsonPath('message', CashMovementService::CASH_REFUND_NEEDS_SHIFT);
        }

        $this->assertSame(SaleStatus::CLOSED, Sale::findOrFail($saleId)->status);
        $this->assertEquals(8, $this->stockOf($product), 'la venta no se canceló: el stock no regresa');
        $this->assertStringContainsString('pide al cajero en turno', CashMovementService::CASH_REFUND_NEEDS_SHIFT);
    }

    public function test_with_an_open_shift_the_cash_refund_goes_through(): void
    {
        $cashier = $this->cashier(['sales_update']);
        $product = $this->createProduct(['sale_price' => 50], 10);

        $saleId = $this->posSale($cashier, [['product_id' => $product->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 100, 'tendered' => 100]])
            ->assertCreated()
            ->json('data.id');

        $this->actingInBranch($cashier)->postJson("/api/auth/admin/sales/{$saleId}/cancel")->assertOk();
        $this->assertEquals(10, $this->stockOf($product));
        $this->assertSame(SaleStatus::CANCELLED, Sale::findOrFail($saleId)->status);
    }
}
