<?php

namespace Tests\Feature\VentasCaja;

use App\Models\CashClosing;
use App\Models\Sale;

/**
 * Registros de dinero que ya no se reescriben por la API: una venta no se
 * edita (se cancela y se vuelve a vender) y un corte de caja anterior no se
 * elimina.
 */
class VentasCajaReadOnlyRecordsTest extends VentasCajaTestCase
{
    public function test_a_sale_cannot_be_rewritten_through_the_api(): void
    {
        $coco = $this->createProduct(['sale_price' => 20], 100);
        $cashier = $this->cashier();
        $saleId = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 10]], [['method' => 'cash', 'amount' => 200]])
            ->assertCreated()->json('data.id');

        // El abuso de la auditoría: 10 piezas → 100 a $2, para luego cancelar
        // y "devolver" 90 piezas que nunca existieron.
        $payload = [
            'payment_method' => 'cash',
            'details' => [$this->legacyLine($coco, 100, 2)],
        ];
        foreach (['putJson', 'patchJson'] as $method) {
            $this->actingInBranch($this->user)->{$method}("/api/auth/admin/sales/{$saleId}", $payload)
                ->assertStatus(405)
                ->assertJsonPath('code', 'sale_update_not_allowed');
        }

        $sale = Sale::with('details')->findOrFail($saleId);
        $this->assertSame('200.00', $sale->total);
        $this->assertSame($cashier->id, $sale->user_id);
        $this->assertCount(1, $sale->details);
        $this->assertSame('10.000', $sale->details[0]->quantity);
        $this->assertEquals(90, $this->stockOf($coco));

        // La corrección es cancelar: devuelve exactamente lo vendido.
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/cancel")->assertOk();
        $this->assertEquals(100, $this->stockOf($coco));
    }

    public function test_a_past_cash_closing_cannot_be_deleted(): void
    {
        $closing = CashClosing::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'closing_date' => '2026-09-20', 'opening_balance' => 0, 'total_income' => 100, 'total_expense' => 0,
            'expected_balance' => 100, 'physical_count' => 100, 'difference' => 0, 'total_cash' => 100,
            'total_card' => 0, 'total_transfer' => 0, 'total_other' => 0, 'movements_count' => 1, 'status' => 'closed',
        ]);

        $this->actingInBranch($this->user)->deleteJson("/api/auth/admin/cash-closings/{$closing->id}")
            ->assertStatus(405)
            ->assertJsonPath('code', 'closing_read_only');

        $this->assertDatabaseHas('cash_closings', ['id' => $closing->id]);

        // La consulta sigue funcionando.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/cash-closings/{$closing->id}")->assertOk();
    }
}
