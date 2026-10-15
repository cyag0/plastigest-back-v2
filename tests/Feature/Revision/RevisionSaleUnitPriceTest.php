<?php

namespace Tests\Feature\Revision;

use App\Models\Sale;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * En la pantalla de ventas anterior, al elegir otra unidad (gramos de un
 * producto que se vende por kilo) la app manda precio base × factor de la
 * unidad. El servidor lo comparaba contra el precio base y, sin el permiso
 * sales_discount, rechazaba un precio correcto ("No tienes permiso para
 * cambiar el precio"). Ahora el precio de catálogo se convierte con el mismo
 * factor que usa el inventario.
 */
class RevisionSaleUnitPriceTest extends VentasCajaTestCase
{
    private function line($product, string $unit, float|string $quantity, float|string $price): array
    {
        return ['product_id' => $product->id, 'unit_id' => $this->unitId($unit), 'quantity' => $quantity, 'unit_price' => $price];
    }

    public function test_a_grams_line_at_the_converted_catalog_price_passes_without_sales_discount(): void
    {
        $cashier = $this->cashier(['sales_create']);
        // $25 el kilo: el gramo vale $0.025, que la app manda como $0.03.
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        $saleId = $this->legacySale($cashier, [
            'payment_method' => 'cash',
            'paid_amount' => 15,
            'details' => [$this->line($coco, 'Gramo', 500, 0.03)],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(15, (float) Sale::findOrFail($saleId)->total);
        $this->assertEqualsWithDelta(9.5, $this->stockOf($coco), 0.0001, 'se descuentan 500 g = 0.5 kg');
    }

    public function test_any_other_price_in_another_unit_is_still_rejected(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        // El precio del kilo por gramo (antes era el único aceptado) y otros.
        foreach ([25, 0.05, 0.01, 0] as $price) {
            $this->legacySale($cashier, [
                'payment_method' => 'cash',
                'paid_amount' => 500 * $price,
                'details' => [$this->line($coco, 'Gramo', 500, $price)],
            ])->assertStatus(422)->assertJsonValidationErrors(['details.0.unit_price']);
        }

        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_a_larger_unit_is_converted_exactly(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $pieza = $this->createProduct(['sale_price' => 8.5], 100);

        // Docena = 12 piezas × $8.50 = $102.00 exactos: no se acepta $101.99.
        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 101.99,
            'details' => [$this->line($pieza, 'Docena', 1, 101.99)],
        ])->assertStatus(422);

        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 102,
            'details' => [$this->line($pieza, 'Docena', 1, 102)],
        ])->assertCreated();
        $this->assertEquals(88, $this->stockOf($pieza));
    }

    public function test_a_unit_of_another_type_is_rejected(): void
    {
        $cashier = $this->cashier(['sales_create']);
        $coco = $this->createProduct(['sale_price' => 25], 10, 'Kilogramo');

        $this->legacySale($cashier, [
            'payment_method' => 'card', 'paid_amount' => 25,
            'details' => [$this->line($coco, 'Pieza', 1, 25)],
        ])->assertStatus(422)->assertJsonValidationErrors(['details.0.unit_id']);
    }

    public function test_the_base_unit_still_requires_the_exact_catalog_price(): void
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
}
