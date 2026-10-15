<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Operations\FormulaOutput;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Catálogo de producción: la ficha de un producto y el clonado de fórmulas
 * se limitan a la empresa actual.
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
}
