<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $companies = DB::table('companies')->get();

        foreach ($companies as $company) {
            $products = DB::table('products')
                ->where('company_id', $company->id)
                ->get();

            // Crear paquetes para algunos productos (no todos)
            $productsToPackage = $products->random(min(10, $products->count()));

            foreach ($productsToPackage as $product) {
                // Paquete de 6 unidades
                DB::table('product_packages')->insert([
                    'product_id' => $product->id,
                    'package_name' => 'Paquete 6 pzas',
                    'barcode' => $product->code . '-PQ6',
                    'quantity_per_package' => 6,
                    'purchase_price' => $product->purchase_price * 6 * 0.95, // 5% descuento
                    'sale_price' => $product->sale_price * 6 * 0.95,
                    'is_active' => true,
                    'is_default' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'company_id' => $company->id,
                ]);

                // Paquete de 12 unidades (caja)
                DB::table('product_packages')->insert([
                    'product_id' => $product->id,
                    'package_name' => 'Caja 12 pzas',
                    'barcode' => $product->code . '-CJ12',
                    'quantity_per_package' => 12,
                    'purchase_price' => $product->purchase_price * 12 * 0.90, // 10% descuento
                    'sale_price' => $product->sale_price * 12 * 0.90,
                    'is_active' => true,
                    'is_default' => true, // Este es el paquete por defecto
                    'created_at' => $now,
                    'updated_at' => $now,
                    'company_id' => $company->id,
                ]);

                // Paquete de 24 unidades (para algunos productos)
                if (rand(0, 1)) {
                    DB::table('product_packages')->insert([
                        'product_id' => $product->id,
                        'package_name' => 'Display 24 pzas',
                        'barcode' => $product->code . '-DS24',
                        'quantity_per_package' => 24,
                        'purchase_price' => $product->purchase_price * 24 * 0.85, // 15% descuento
                        'sale_price' => $product->sale_price * 24 * 0.85,
                        'is_active' => true,
                        'is_default' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'company_id' => $company->id,
                    ]);
                }
            }
        }

        $this->command->info('✅ Paquetes creados exitosamente');
    }
}
