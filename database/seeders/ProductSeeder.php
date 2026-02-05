<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $companies = DB::table('companies')->get();

        // Crear categorías primero
        $categories = [
            ['name' => 'Bebidas', 'description' => 'Bebidas y refrescos'],
            ['name' => 'Alimentos', 'description' => 'Productos alimenticios'],
            ['name' => 'Limpieza', 'description' => 'Productos de limpieza'],
            ['name' => 'Higiene Personal', 'description' => 'Productos de higiene'],
            ['name' => 'Abarrotes', 'description' => 'Abarrotes en general'],
        ];

        $categoryIds = [];
        foreach ($companies as $company) {
            foreach ($categories as $category) {
                $categoryIds[$company->id][] = DB::table('categories')->insertGetId([
                    'company_id' => $company->id,
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Obtener unidades
        $unitPieza = DB::table('units')->where('abbreviation', 'pz')->first();
        $unitKg = DB::table('units')->where('abbreviation', 'kg')->first();
        $unitLitro = DB::table('units')->where('abbreviation', 'L')->first();

        // Productos de ejemplo
        $productsData = [
            // Bebidas
            ['code' => 'BEB001', 'name' => 'Coca-Cola 600ml', 'description' => 'Refresco Coca-Cola', 'purchase_price' => 12.50, 'sale_price' => 18.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 0],
            ['code' => 'BEB002', 'name' => 'Pepsi 600ml', 'description' => 'Refresco Pepsi', 'purchase_price' => 12.00, 'sale_price' => 17.50, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 0],
            ['code' => 'BEB003', 'name' => 'Agua Bonafont 1L', 'description' => 'Agua purificada', 'purchase_price' => 8.00, 'sale_price' => 12.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 0],
            ['code' => 'BEB004', 'name' => 'Jugo Del Valle 1L', 'description' => 'Jugo de naranja', 'purchase_price' => 22.00, 'sale_price' => 32.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 0],
            
            // Alimentos
            ['code' => 'ALI001', 'name' => 'Pan Blanco Bimbo', 'description' => 'Pan de caja blanco', 'purchase_price' => 28.00, 'sale_price' => 38.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 1],
            ['code' => 'ALI002', 'name' => 'Leche Lala 1L', 'description' => 'Leche entera', 'purchase_price' => 18.00, 'sale_price' => 25.00, 'unit_id' => $unitLitro->id, 'product_type' => 'commercial', 'category_index' => 1],
            ['code' => 'ALI003', 'name' => 'Huevo Blanco', 'description' => 'Huevo blanco por kilo', 'purchase_price' => 35.00, 'sale_price' => 48.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 1],
            ['code' => 'ALI004', 'name' => 'Aceite Capullo 1L', 'description' => 'Aceite vegetal', 'purchase_price' => 32.00, 'sale_price' => 45.00, 'unit_id' => $unitLitro->id, 'product_type' => 'commercial', 'category_index' => 1],
            
            // Limpieza
            ['code' => 'LIM001', 'name' => 'Cloro Cloralex 1L', 'description' => 'Blanqueador', 'purchase_price' => 15.00, 'sale_price' => 22.00, 'unit_id' => $unitLitro->id, 'product_type' => 'commercial', 'category_index' => 2],
            ['code' => 'LIM002', 'name' => 'Detergente Ariel 1kg', 'description' => 'Detergente en polvo', 'purchase_price' => 45.00, 'sale_price' => 65.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 2],
            ['code' => 'LIM003', 'name' => 'Fabuloso 1L', 'description' => 'Limpiador multiusos', 'purchase_price' => 18.00, 'sale_price' => 28.00, 'unit_id' => $unitLitro->id, 'product_type' => 'commercial', 'category_index' => 2],
            
            // Higiene Personal
            ['code' => 'HIG001', 'name' => 'Shampoo Pantene 400ml', 'description' => 'Shampoo para cabello', 'purchase_price' => 52.00, 'sale_price' => 75.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 3],
            ['code' => 'HIG002', 'name' => 'Jabón Zest', 'description' => 'Jabón de tocador', 'purchase_price' => 8.00, 'sale_price' => 12.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 3],
            ['code' => 'HIG003', 'name' => 'Papel Higiénico Suave 4 rollos', 'description' => 'Papel higiénico', 'purchase_price' => 22.00, 'sale_price' => 32.00, 'unit_id' => $unitPieza->id, 'product_type' => 'commercial', 'category_index' => 3],
            
            // Abarrotes
            ['code' => 'ABA001', 'name' => 'Arroz Blanco 1kg', 'description' => 'Arroz grano largo', 'purchase_price' => 18.00, 'sale_price' => 25.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 4],
            ['code' => 'ABA002', 'name' => 'Frijol Negro 1kg', 'description' => 'Frijol negro', 'purchase_price' => 22.00, 'sale_price' => 32.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 4],
            ['code' => 'ABA003', 'name' => 'Azúcar 1kg', 'description' => 'Azúcar refinada', 'purchase_price' => 20.00, 'sale_price' => 28.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 4],
            ['code' => 'ABA004', 'name' => 'Sal 1kg', 'description' => 'Sal de mesa', 'purchase_price' => 8.00, 'sale_price' => 12.00, 'unit_id' => $unitKg->id, 'product_type' => 'commercial', 'category_index' => 4],
        ];

        foreach ($companies as $company) {
            $suppliers = DB::table('suppliers')->where('company_id', $company->id)->get();
            $locations = DB::table('locations')->where('company_id', $company->id)->get();

            foreach ($productsData as $productData) {
                $supplier = $suppliers->random();
                $categoryId = $categoryIds[$company->id][$productData['category_index']];

                // Crear producto
                $productId = DB::table('products')->insertGetId([
                    'company_id' => $company->id,
                    'supplier_id' => $supplier->id,
                    'category_id' => $categoryId,
                    'unit_id' => $productData['unit_id'],
                    'code' => $productData['code'] . '-' . $company->id,
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'purchase_price' => $productData['purchase_price'],
                    'sale_price' => $productData['sale_price'],
                    'product_type' => $productData['product_type'],
                    'for_sale' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Crear pivote para TODAS las locaciones
                foreach ($locations as $location) {
                    DB::table('product_location')->insert([
                        'product_id' => $productId,
                        'location_id' => $location->id,
                        'current_stock' => rand(10, 100), // Stock aleatorio entre 10 y 100
                        'minimum_stock' => 5,
                        'maximum_stock' => 200,
                        'active' => true, // Todos activos
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        $this->command->info('✅ Productos creados exitosamente con pivotes en todas las locaciones');
    }
}
