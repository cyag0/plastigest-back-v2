<?php

namespace Tests\Feature\Flows;

use Database\Seeders\CompaniesSeeder;
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
}
