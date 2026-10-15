<?php

namespace Tests\Feature\Revision;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * El punto de venta aceptaba un descuento mayor que el importe del renglón:
 * los pagos se comparaban contra un total y la venta se guardaba con otro
 * (SaleService recorta el descuento), y quedaba un saldo pendiente de un
 * cliente de mostrador que no existía. Ahora se rechaza con 422.
 */
class RevisionSaleDiscountTest extends VentasCajaTestCase
{
    public function test_a_discount_larger_than_the_line_is_rejected(): void
    {
        $cashier = $this->cashier(['sales_discount']);
        $a = $this->createProduct(['sale_price' => 50], 10);
        $b = $this->createProduct(['sale_price' => 200], 10);

        $this->posSale($cashier, [
            ['product_id' => $a->id, 'quantity' => 1, 'unit_price' => 50, 'discount' => 100],
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 200],
        ], [['method' => 'cash', 'amount' => 150, 'tendered' => 150]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.discount']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, DB::table('cash_movements')->count());
        $this->assertSame(10.0, $this->stockOf($a));
    }

    public function test_a_discount_of_the_whole_line_is_still_allowed(): void
    {
        $cashier = $this->cashier(['sales_discount']);
        $a = $this->createProduct(['sale_price' => 50], 10);
        $b = $this->createProduct(['sale_price' => 200], 10);

        $this->posSale($cashier, [
            ['product_id' => $a->id, 'quantity' => 1, 'unit_price' => 50, 'discount' => 50],
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 200],
        ], [['method' => 'cash', 'amount' => 200, 'tendered' => 200]])
            ->assertCreated()
            ->assertJsonPath('data.total', 200);

        $sale = Sale::firstOrFail();
        $this->assertEquals(200, (float) $sale->total);
        $this->assertEquals(200, (float) $sale->paid_amount);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_the_limit_is_the_rounded_line_amount(): void
    {
        $cashier = $this->cashier(['sales_discount']);
        // 2.489 kg × $25 = $62.225 → $62.23 (mitad hacia arriba, en centavos).
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        $this->posSale($cashier, [
            ['product_id' => $coco->id, 'quantity' => 2.489, 'unit_price' => 25, 'discount' => 62.24],
        ], [['method' => 'card', 'amount' => 0.01]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.discount']);

        $this->posSale($cashier, [
            ['product_id' => $coco->id, 'quantity' => 2.489, 'unit_price' => 25, 'discount' => 62.22],
        ], [['method' => 'card', 'amount' => 0.01]])
            ->assertCreated();
    }
}
