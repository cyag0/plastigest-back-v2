<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo inicial real de Cocos Francisco para producción: categorías,
 * proveedores, productos (stock en 0 en todas las sucursales, con sus recetas
 * de envasado), paquetes y los procesos de producción.
 *
 * Se corre UNA vez, después de CompaniesSeeder y de `db:seed`:
 *   php artisan db:seed --class=InitialCatalogSeeder --force
 *
 * Se niega si la empresa ya tiene productos: ProductsSeeder sobrescribe
 * nombres y precios, y no debe pisar lo que ya se cambió desde la app.
 * No incluye datos demo.
 */
class InitialCatalogSeeder extends Seeder
{
    /** true mientras corre run(): ahí sí se aceptan los seeders del catálogo. */
    private static bool $running = false;

    /**
     * Los seeders del catálogo (categorías, proveedores, productos, paquetes,
     * fórmulas) sobrescriben precios o borran datos: fuera de local/testing
     * solo corren a través de este seeder, que se niega si la empresa ya
     * tiene productos. Un `db:seed --class=ProductsSeeder` suelto en
     * producción no hace nada.
     */
    public static function allows(Seeder $seeder): bool
    {
        if (self::$running || app()->environment('local', 'testing')) {
            return true;
        }

        $seeder->command?->error(
            class_basename($seeder) . ': en "' . app()->environment()
            . '" solo se corre a través de InitialCatalogSeeder; no se hizo nada.'
        );

        return false;
    }

    public function run(): void
    {
        $company = Company::where('name', 'Cocos Francisco')->first();
        if (!$company) {
            $this->command->error('No existe la empresa Cocos Francisco: corre primero CompaniesSeeder.');

            return;
        }

        if (DB::table('products')->where('company_id', $company->id)->exists()) {
            $this->command->warn('La empresa ya tiene productos: no se carga el catálogo inicial para no sobrescribirlos.');

            return;
        }

        self::$running = true;
        try {
            $this->call([
                CategoriesSeeder::class,
                SupplierSeeder::class,
                ProductsSeeder::class,
                PackageSeeder::class,
                FormulasSeeder::class,
            ]);
        } finally {
            self::$running = false;
        }
    }
}
