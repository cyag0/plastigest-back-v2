<?php

namespace Tests\Feature\Revision;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Support\Str;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * Renglones en otra unidad del mismo tipo (gramos de un producto que se vende
 * por kilo, docenas de uno por pieza). La pantalla de ventas anterior mandaba
 * el precio por gramo redondeado al centavo ($25/kg → $0.03/g) y el servidor
 * lo aceptaba: 500 g se cobraban $15.00 en lugar de $12.50. Ahora el renglón
 * se guarda en la unidad del producto (cantidad convertida, precio por unidad
 * del producto) y ese precio se compara con el de catálogo.
 */
class RevisionSaleUnitPriceTest extends VentasCajaTestCase
{
    private const KIOSK = ['sales_orders_create', 'sales_orders_update', 'sales_orders_read', 'sales_orders_list', 'products_list'];

    private function line($product, string $unit, float|string $quantity, float|string $price): array
    {
        return ['product_id' => $product->id, 'unit_id' => $this->unitId($unit), 'quantity' => $quantity, 'unit_price' => $price];
    }

    private function onlyDetail(int $saleId): SaleDetail
    {
        return SaleDetail::where('sale_id', $saleId)->sole();
    }

    public function test_500_grams_of_a_25_per_kilo_product_cost_12_50_without_sales_discount(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        // Lo que mandaba la app: el precio exacto por gramo ($0.025).
        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 12.5,
            'details' => [$this->line($coco, 'Gramo', 500, 0.025)],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(12.5, (float) Sale::findOrFail($saleId)->total);
        $detail = $this->onlyDetail($saleId);
        $this->assertSame($this->unitId('Kilogramo'), (int) $detail->unit_id, 'se guarda en la unidad del producto');
        $this->assertEquals(0.5, (float) $detail->quantity);
        $this->assertEquals(25, (float) $detail->unit_price);
        $this->assertEquals(12.5, (float) $detail->total);
        $this->assertEqualsWithDelta(9.5, $this->stockOf($coco), 0.0001, 'se descuentan 500 g = 0.5 kg, una sola vez');

        // Lo que manda ahora la app: el renglón ya en kilos.
        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 12.5,
            'details' => [$this->line($coco, 'Kilogramo', 0.5, 25)],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(12.5, (float) Sale::findOrFail($saleId)->total);
        $this->assertEqualsWithDelta(9, $this->stockOf($coco), 0.0001);
    }

    public function test_a_per_gram_price_rounded_to_the_cent_is_rejected_without_sales_discount(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        // $0.03 el gramo son $30 el kilo (lo que antes se cobraba); $25 el
        // gramo, el precio del kilo por gramo; y otros.
        foreach ([0.03, 25, 0.05, 0.01, 0] as $price) {
            $this->legacySale($cashier, [
                'payment_method' => 'card',
                'paid_amount' => round(500 * $price, 2),
                'details' => [$this->line($coco, 'Gramo', 500, $price)],
            ])->assertStatus(422)->assertJsonValidationErrors(['details.0.unit_price']);
        }

        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_two_dozens_of_a_10_per_piece_product_cost_240_and_take_24_pieces(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $pieza = $this->createProduct(['sale_price' => 10], 100);

        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 240,
            'details' => [$this->line($pieza, 'Docena', 2, 120)],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(240, (float) Sale::findOrFail($saleId)->total);
        $detail = $this->onlyDetail($saleId);
        $this->assertSame($this->unitId('Pieza'), (int) $detail->unit_id);
        $this->assertEquals(24, (float) $detail->quantity);
        $this->assertEquals(10, (float) $detail->unit_price);
        $this->assertEquals(76, $this->stockOf($pieza), 'salen 24 piezas');

        // Otro precio por docena ($100 = $8.33 la pieza) se rechaza.
        $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 100,
            'details' => [$this->line($pieza, 'Docena', 1, 100)],
        ])->assertStatus(422)->assertJsonValidationErrors(['details.0.unit_price']);
        $this->assertSame(1, Sale::count());
    }

    public function test_a_discount_user_sets_a_custom_price_per_product_unit(): void
    {
        $seller = $this->cashier(['sales_create', 'sales_discount']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        // $20 el kilo: en kilos (lo que manda la app) o como $0.02 el gramo.
        $first = $this->legacySale($seller, [
            'payment_method' => 'card',
            'paid_amount' => 10,
            'details' => [$this->line($coco, 'Kilogramo', 0.5, 20)],
        ])->assertCreated()->json('data.id');
        $second = $this->legacySale($seller, [
            'payment_method' => 'card',
            'paid_amount' => 10,
            'details' => [$this->line($coco, 'Gramo', 500, 0.02)],
        ])->assertCreated()->json('data.id');

        foreach ([$first, $second] as $saleId) {
            $this->assertEquals(10, (float) Sale::findOrFail($saleId)->total);
            $this->assertEquals(20, (float) $this->onlyDetail($saleId)->unit_price);
            $this->assertEquals(0.5, (float) $this->onlyDetail($saleId)->quantity);
        }
        $this->assertEqualsWithDelta(9, $this->stockOf($coco), 0.0001);
    }

    public function test_products_whose_unit_is_not_the_base_of_its_type(): void
    {
        $cashier = $this->cashier(['sales_create']);
        // Por gramo ($0.30) vendido en kilos; por docena ($120) vendido en piezas.
        $especia = $this->createProduct(['sale_price' => 0.3], 5000, 'Gramo');
        $huevo = $this->createProduct(['sale_price' => 120], 10, 'Docena');

        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'card',
            'paid_amount' => 510,
            'details' => [
                $this->line($especia, 'Kilogramo', 1.5, 300),
                $this->line($huevo, 'Pieza', 6, 10),
            ],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(510, (float) Sale::findOrFail($saleId)->total);
        $details = SaleDetail::where('sale_id', $saleId)->get()->keyBy('product_id');
        $this->assertSame($this->unitId('Gramo'), (int) $details[$especia->id]->unit_id);
        $this->assertEquals(1500, (float) $details[$especia->id]->quantity);
        $this->assertEquals(0.3, (float) $details[$especia->id]->unit_price);
        $this->assertSame($this->unitId('Docena'), (int) $details[$huevo->id]->unit_id);
        $this->assertEquals(0.5, (float) $details[$huevo->id]->quantity);
        $this->assertEquals(120, (float) $details[$huevo->id]->unit_price);
        $this->assertEqualsWithDelta(3500, $this->stockOf($especia), 0.0001);
        $this->assertEqualsWithDelta(9.5, $this->stockOf($huevo), 0.0001);
    }

    public function test_a_unit_of_another_type_is_rejected(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 25,
            'details' => [$this->line($coco, 'Pieza', 1, 25)],
        ])->assertStatus(422)->assertJsonValidationErrors(['details.0.unit_id']);
        $this->assertSame(0, Sale::count());
    }

    public function test_less_than_a_thousandth_of_the_product_unit_is_rejected(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        // 0.4 g = 0.0004 kg: con tres decimales queda en 0.
        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 0.01,
            'details' => [$this->line($coco, 'Gramo', 0.4, 0.025)],
        ])->assertStatus(422)->assertJsonValidationErrors(['details.0.quantity']);

        // 5 g = 0.005 kg, ya en kilos como lo manda la app (antes el mínimo era 0.01).
        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 0.13,
            'details' => [$this->line($coco, 'Kilogramo', 0.005, 25)],
        ])->assertCreated();
        $this->assertEqualsWithDelta(9.995, $this->stockOf($coco), 0.0001);
    }

    public function test_the_product_unit_still_requires_the_exact_catalog_price(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 24.99,
            'details' => [$this->line($coco, 'Kilogramo', 1, 24.99)],
        ])->assertStatus(422);

        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 25,
            'details' => [$this->line($coco, 'Kilogramo', 1, 25)],
        ])->assertCreated();
    }

    public function test_a_replay_with_the_same_key_returns_the_sale_without_moving_stock_again(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');
        $payload = [
            'payment_method' => 'card',
            'paid_amount' => 12.5,
            'details' => [$this->line($coco, 'Gramo', 500, 0.025)],
            'client_request_id' => (string) Str::uuid(),
        ];

        $first = $this->legacySale($cashier, $payload)->assertCreated()->json('data.id');
        $second = $this->legacySale($cashier, $payload)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Sale::count());
        $this->assertEquals(12.5, (float) Sale::findOrFail($first)->total);
        $this->assertEqualsWithDelta(9.5, $this->stockOf($coco), 0.0001);
    }

    public function test_orders_and_the_kiosk_quick_checkout_store_the_line_in_the_product_unit(): void
    {
        $kiosk = $this->createBranchUser($this->location, self::KIOSK)->fresh();
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');
        $grams = fn(float $price) => [[
            'product_id' => $coco->id,
            'package_id' => null,
            'unit_id' => $this->unitId('Gramo'),
            'requested_quantity' => 500,
            'unit_price' => $price,
        ]];
        $payload = fn(float $price) => [
            'channel' => 'kiosk',
            'service_mode' => 'counter',
            'customer_id' => null,
            'details' => $grams($price),
            'payment_method' => 'card',
            'paid_amount' => 12.5,
            'client_request_id' => (string) Str::uuid(),
        ];

        // Sin sales_discount, $0.03 el gramo ($30 el kilo) no pasa.
        $this->actingInBranch($kiosk)->postJson('/api/auth/admin/sales-orders/quick-checkout', $payload(0.03))
            ->assertStatus(422);
        $this->assertSame(0, SalesOrder::count());

        $saleId = $this->actingInBranch($kiosk)->postJson('/api/auth/admin/sales-orders/quick-checkout', $payload(0.025))
            ->assertCreated()->json('sale.id');

        $this->assertEquals(12.5, (float) Sale::findOrFail($saleId)->total);
        $orderDetail = SalesOrderDetail::sole();
        $this->assertSame($this->unitId('Kilogramo'), (int) $orderDetail->unit_id);
        $this->assertEquals(0.5, (float) $orderDetail->requested_quantity);
        $this->assertEquals(25, (float) $orderDetail->unit_price);
        $this->assertEquals(12.5, (float) $orderDetail->line_total);
        $saleDetail = $this->onlyDetail($saleId);
        $this->assertSame($this->unitId('Kilogramo'), (int) $saleDetail->unit_id);
        $this->assertEquals(0.5, (float) $saleDetail->quantity);
        $this->assertEqualsWithDelta(9.5, $this->stockOf($coco), 0.0001);

        // Alta de un pedido (se cobra después): igual, en kilos.
        $orderId = $this->actingInBranch($kiosk)->postJson('/api/auth/admin/sales-orders', [
            'channel' => 'admin',
            'service_mode' => 'counter',
            'details' => $grams(0.025),
        ])->assertOk()->json('data.id');
        $order = SalesOrder::with('details')->findOrFail($orderId);
        $this->assertEquals(12.5, (float) $order->total_amount);
        $this->assertSame($this->unitId('Kilogramo'), (int) $order->details->sole()->unit_id);
        $this->assertEquals(0.5, (float) $order->details->sole()->requested_quantity);
    }
}
