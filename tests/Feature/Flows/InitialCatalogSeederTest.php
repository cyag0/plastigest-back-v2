<?php

namespace Tests\Feature\Flows;

use Database\Seeders\CompaniesSeeder;
use Database\Seeders\FormulasSeeder;
use Database\Seeders\InitialCatalogSeeder;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ResourceSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UnitsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Carga del catálogo real en una instalación de producción: una sola vez,
 * sin stock y sin duplicar nada si se vuelve a correr.
 */
class InitialCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_the_real_catalog_once_without_stock(): void
    {
        $this->seed([ResourceSeeder::class, PermissionSeeder::class, UnitsSeeder::class, CompaniesSeeder::class, UserSeeder::class]);
        $this->seed(InitialCatalogSeeder::class);

        $counts = fn() => [
            'categories' => DB::table('categories')->count(),
            'suppliers' => DB::table('suppliers')->count(),
            'products' => DB::table('products')->count(),
            'packages' => DB::table('product_packages')->count(),
        ];
        $first = $counts();

        $this->assertGreaterThan(50, $first['products']);
        $this->assertGreaterThan(0, $first['categories']);
        $this->assertGreaterThan(0, $first['suppliers']);
        $this->assertGreaterThan(0, $first['packages']);
        $this->assertSame(0.0, (float) DB::table('product_location')->sum('current_stock'));
        $this->assertSame(
            DB::table('products')->count() * DB::table('locations')->count(),
            DB::table('product_location')->count()
        );

        // Un precio cambiado desde la app no se pisa al volver a correrlo.
        DB::table('products')->orderBy('id')->limit(1)->update(['sale_price' => 999]);
        $this->seed(InitialCatalogSeeder::class);
        $this->seed([SupplierSeeder::class, PackageSeeder::class]);

        $this->assertSame($first, $counts());
        $this->assertSame(999.0, (float) DB::table('products')->orderBy('id')->value('sale_price'));
    }

    public function test_bottles_count_in_pieces_and_come_from_bulk_with_their_packaging(): void
    {
        $this->seed([ResourceSeeder::class, PermissionSeeder::class, UnitsSeeder::class, CompaniesSeeder::class, UserSeeder::class]);
        $this->seed(InitialCatalogSeeder::class);

        $product = fn(string $code) => DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->join('inventory_types', 'inventory_types.id', '=', 'products.inventory_type_id')
            ->where('products.code', $code)
            ->select('products.*', 'units.name as unit_name', 'inventory_types.code as type_code')
            ->first();

        // La botella de ½ L es una pieza: lo que lleva dentro va en su receta.
        $bottle = $product('BEB-AC-500');
        $this->assertSame('Pieza', $bottle->unit_name);
        $this->assertSame('finished_product', $bottle->type_code);
        $this->assertSame(1, (int) $bottle->requires_packaging);
        $this->assertSame((int) $product('GRA-AGUA')->id, (int) $bottle->packaging_source_product_id);
        $this->assertEquals(0.5, (float) $bottle->packaging_source_quantity);
        $this->assertSame(
            ['PET-BOT-500T', 'PET-TAP-R28'],
            DB::table('product_packaging_materials')
                ->join('products', 'products.id', '=', 'product_packaging_materials.material_product_id')
                ->where('product_packaging_materials.product_id', $bottle->id)
                ->orderBy('product_packaging_materials.sort_order')
                ->pluck('products.code')
                ->all()
        );

        // El granel se mide en litros o kilos y no se vende suelto.
        $water = $product('GRA-AGUA');
        $this->assertSame('Litro', $water->unit_name);
        $this->assertSame('semi_finished', $water->type_code);
        $this->assertSame(0, (int) $water->for_sale);

        // Ningún producto envasado quedó en litros o kilos.
        $this->assertSame(0, DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->where('products.requires_packaging', true)
            ->where('units.name', '!=', 'Pieza')
            ->count());

        // Los procesos: abrir cocos da agua y pulpa a partir del coco.
        $processes = DB::table('formulas')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Abrir cocos', 'Coco para tomar', 'Dulces', 'Horchata', 'Rallado y tostado', 'Tuba'], $processes);
        $open = DB::table('formulas')->where('name', 'Abrir cocos')->value('id');
        $this->assertSame(['COC-ENT-001'], DB::table('formula_items')->join('products', 'products.id', '=', 'formula_items.product_id')
            ->where('formula_id', $open)->pluck('products.code')->all());
        $this->assertSame(['GRA-AGUA', 'GRA-PULPA'], DB::table('formula_outputs')->join('products', 'products.id', '=', 'formula_outputs.product_id')
            ->where('formula_id', $open)->orderBy('sort_order')->pluck('products.code')->all());

        // Ningún proceso lleva material de empaque entre sus ingredientes.
        $this->assertSame(0, DB::table('formula_items')
            ->join('products', 'products.id', '=', 'formula_items.product_id')
            ->join('inventory_types', 'inventory_types.id', '=', 'products.inventory_type_id')
            ->where('inventory_types.code', 'packaging_material')
            ->count());

        // Volver a sembrar los procesos no los duplica.
        $this->seed(FormulasSeeder::class);
        $this->assertSame(6, DB::table('formulas')->count());
        $this->assertSame(2, DB::table('formula_outputs')->where('formula_id', $open)->count());
    }
}
