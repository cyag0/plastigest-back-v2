<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo inicial real de Cocos Francisco para producción: categorías,
 * proveedores, productos (stock en 0 en todas las sucursales) y paquetes.
 *
 * Se corre UNA vez, después de CompaniesSeeder y de `db:seed`:
 *   php artisan db:seed --class=InitialCatalogSeeder --force
 *
 * Se niega si la empresa ya tiene productos: ProductsSeeder sobrescribe
 * nombres y precios, y no debe pisar lo que ya se cambió desde la app.
 * No incluye fórmulas (FormulasSeeder borra las existentes) ni datos demo.
 */
class InitialCatalogSeeder extends Seeder
{
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

        $this->call([
            CategoriesSeeder::class,
            SupplierSeeder::class,
            ProductsSeeder::class,
            PackageSeeder::class,
        ]);
    }
}
