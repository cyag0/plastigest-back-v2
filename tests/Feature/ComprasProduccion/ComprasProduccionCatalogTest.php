<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Operations\FormulaOutput;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\StockLedgerEntry;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Catálogo de producción: la ficha de un producto y el clonado de fórmulas
 * se limitan a la empresa actual, y la medida de un producto (unidad base,
 * factor de una unidad, contenido de una presentación) no cambia una vez que
 * tiene existencia, movimientos o documentos abiertos.
 */
class ComprasProduccionCatalogTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Fórmula de la empresa dada: 14 cocos → 10 kg de granel. */
    private function formulaOf(int $companyId, Product $input, Product $output, string $name = 'Dulce de coco'): Formula
    {
        $formula = Formula::create(['company_id' => $companyId, 'name' => $name, 'version' => 1, 'is_active' => true]);
        FormulaItem::create(['formula_id' => $formula->id, 'product_id' => $input->id, 'unit_id' => $input->unit_id, 'expected_quantity' => 14]);
        FormulaOutput::create(['formula_id' => $formula->id, 'product_id' => $output->id, 'unit_id' => $output->unit_id, 'expected_quantity' => 10, 'is_primary' => true]);

        return $formula;
    }

    public function test_a_product_of_another_company_is_not_found(): void
    {
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $ajeno = $this->createProduct(['company_id' => $otraEmpresa->id, 'name' => 'Ajeno']);
        $propio = $this->createProduct(['name' => 'Coco'], 12);

        // Antes: 200 con costo, receta y existencias del producto ajeno.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/products/{$ajeno->id}")->assertNotFound();

        $this->actingInBranch($this->user)->getJson("/api/auth/admin/products/{$propio->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Coco')
            ->assertJsonPath('data.current_stock', 12);
    }

    public function test_seeing_a_product_needs_permission_to_see_products(): void
    {
        $coco = $this->createProduct(['name' => 'Coco']);

        $cajero = $this->createBranchUser($this->location, ['sales_pos']);
        $this->actingInBranch($cajero)->getJson("/api/auth/admin/products/{$coco->id}")->assertForbidden();

        // Quien puede listar productos (ajustes, producción) ve la ficha.
        $almacen = $this->createBranchUser($this->location, ['products_list', 'inventory_create']);
        $this->actingInBranch($almacen)->getJson("/api/auth/admin/products/{$coco->id}")->assertOk();
    }

    public function test_a_formula_of_another_company_cannot_be_cloned(): void
    {
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $coco = $this->createProduct(['company_id' => $otraEmpresa->id]);
        $granel = $this->createProduct(['company_id' => $otraEmpresa->id]);
        $ajena = $this->formulaOf($otraEmpresa->id, $coco, $granel, 'Receta ajena');

        // Antes: 201 y la copia quedaba en la otra empresa.
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/formulas/{$ajena->id}/clone")->assertNotFound();
        $this->assertSame(1, Formula::where('company_id', $otraEmpresa->id)->count());
    }

    public function test_cloning_an_own_formula_copies_it_into_the_company(): void
    {
        $coco = $this->createProduct();
        $granel = $this->createProduct();
        $propia = $this->formulaOf($this->company->id, $coco, $granel);

        $id = $this->actingInBranch($this->user)->postJson("/api/auth/admin/formulas/{$propia->id}/clone", ['name' => 'Dulce de coco v2'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dulce de coco v2')
            ->json('data.id');

        $copia = Formula::with(['items', 'outputs'])->findOrFail($id);
        $this->assertSame($this->company->id, (int) $copia->company_id);
        $this->assertSame([$coco->id], $copia->items->pluck('product_id')->map(fn($v) => (int) $v)->all());
        $this->assertSame([$granel->id], $copia->outputs->pluck('product_id')->map(fn($v) => (int) $v)->all());
    }

    public function test_a_formula_only_takes_products_of_the_company(): void
    {
        [$otraEmpresa] = $this->createCompanyWithBranch();
        $ajeno = $this->createProduct(['company_id' => $otraEmpresa->id]);
        $coco = $this->createProduct();
        $granel = $this->createProduct();
        $propia = $this->formulaOf($this->company->id, $coco, $granel);

        $line = fn(Product $product, float $qty) => ['product_id' => $product->id, 'unit_id' => $product->unit_id, 'expected_quantity' => $qty];

        $this->actingInBranch($this->user)->postJson('/api/auth/admin/formulas', [
            'name' => 'Con ingrediente ajeno',
            'items' => [$line($ajeno, 3)],
            'outputs' => [$line($granel, 1)],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->actingInBranch($this->user)->putJson("/api/auth/admin/formulas/{$propia->id}", [
            'outputs' => [$line($ajeno, 1)],
        ])->assertStatus(422)->assertJsonValidationErrors('outputs.0.product_id');

        $this->assertSame([$granel->id], $propia->outputs()->pluck('product_id')->map(fn($v) => (int) $v)->all());
    }

    private function ledgerEntry(Product $product): void
    {
        StockLedgerEntry::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'product_id' => $product->id,
            'direction' => 'in', 'reason' => 'purchase', 'quantity' => 5, 'previous_stock' => 0, 'new_stock' => 5,
        ]);
    }

    public function test_the_base_unit_of_a_product_with_stock_or_history_cannot_change(): void
    {
        $kg = $this->unitId('Kilogramo');

        // Producto nuevo: se puede corregir la unidad.
        $nuevo = $this->createProduct(['name' => 'Recién dado de alta'], 0);
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/products/{$nuevo->id}", ['unit_id' => $kg])->assertOk();
        $this->assertSame($kg, (int) $nuevo->fresh()->unit_id);

        // Con existencia: 422 y la unidad sigue igual.
        $conStock = $this->createProduct(['name' => 'Coco'], 5);
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/products/{$conStock->id}", ['unit_id' => $kg])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unit_locked')
            ->assertJsonFragment(['message' => 'No se puede cambiar la unidad base de Coco: tiene existencia en alguna sucursal, y esas cantidades están en Pieza. Crea un producto nuevo con la unidad correcta y desactiva este.']);
        $this->assertSame($this->unitId('Pieza'), (int) $conStock->fresh()->unit_id);

        // Sin existencia pero con historial, igual.
        $conHistorial = $this->createProduct(['name' => 'Agua de coco'], 0);
        $this->ledgerEntry($conHistorial);
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/products/{$conHistorial->id}", ['unit_id' => $kg])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unit_locked');

        // Guardar el formulario con la misma unidad (como lo manda la app) sí pasa.
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/products/{$conStock->id}", [
            'unit_id' => $conStock->unit_id, 'name' => 'Coco entero',
        ])->assertOk();
        $this->assertSame('Coco entero', $conStock->fresh()->name);
    }

    public function test_a_package_content_cannot_change_once_the_product_has_stock(): void
    {
        $coco = $this->createProduct(['name' => 'Coco'], 40);
        $caja = ProductPackage::create([
            'company_id' => $this->company->id, 'product_id' => $coco->id, 'package_name' => 'Caja de 10',
            'barcode' => 'CJ-' . uniqid(), 'quantity_per_package' => 10, 'is_active' => true,
        ]);

        $this->actingInBranch($this->user)->putJson("/api/auth/admin/product-packages/{$caja->id}", ['quantity_per_package' => 12])
            ->assertStatus(422)
            ->assertJsonPath('code', 'package_in_use');
        $this->assertEquals(10, (float) $caja->fresh()->quantity_per_package);

        // Nombre y precio sí, aunque venga el mismo contenido.
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/product-packages/{$caja->id}", [
            'quantity_per_package' => 10, 'package_name' => 'Caja chica', 'sale_price' => 180,
        ])->assertOk();
        $this->assertSame('Caja chica', $caja->fresh()->package_name);

        // La presentación de un producto nuevo se puede corregir.
        $nuevo = $this->createProduct([], 0);
        $bolsa = ProductPackage::create([
            'company_id' => $this->company->id, 'product_id' => $nuevo->id, 'package_name' => 'Bolsa',
            'barcode' => 'BL-' . uniqid(), 'quantity_per_package' => 5, 'is_active' => true,
        ]);
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/product-packages/{$bolsa->id}", ['quantity_per_package' => 6])
            ->assertOk();
        $this->assertEquals(6, (float) $bolsa->fresh()->quantity_per_package);
    }

    public function test_a_unit_in_use_keeps_its_factor_and_type(): void
    {
        $caja = Unit::create([
            'name' => 'Caja', 'abbreviation' => 'cj', 'unit_type' => 'quantity', 'is_base_unit' => false,
            'company_id' => $this->company->id, 'factor_to_base' => 12,
        ]);
        $libre = Unit::create([
            'name' => 'Costal', 'abbreviation' => 'cs', 'unit_type' => 'quantity', 'is_base_unit' => false,
            'company_id' => $this->company->id, 'factor_to_base' => 50,
        ]);
        $this->createProduct(['name' => 'Coco en caja', 'unit_id' => $caja->id], 3);

        $this->actingInBranch($this->user)->putJson("/api/auth/admin/units/{$caja->id}", ['factor_to_base' => 10])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unit_in_use');
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/units/{$caja->id}", ['base_unit_id' => $this->unitId('Kilogramo')])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unit_in_use');
        $this->assertEquals(12, (float) $caja->fresh()->factor_to_base);
        $this->assertSame('quantity', $caja->fresh()->unit_type);

        // Renombrar sí (aunque la app mande el mismo factor).
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/units/{$caja->id}", ['name' => 'Caja grande', 'factor_to_base' => 12])
            ->assertOk();
        $this->assertSame('Caja grande', $caja->fresh()->name);

        // Una unidad que nadie usa se corrige.
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/units/{$libre->id}", ['factor_to_base' => 25])->assertOk();
        $this->assertEquals(25, (float) $libre->fresh()->factor_to_base);
    }
}
