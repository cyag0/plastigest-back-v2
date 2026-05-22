<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Desactivar restricciones de foreign keys temporalmente
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Limpiar productos anteriores y sus relaciones con ubicaciones
        DB::table('product_location')->whereIn('product_id', Product::pluck('id'))->delete();
        Product::query()->delete();
        
        // Reactivar restricciones de foreign keys
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $company = Company::where('name', 'Cocos Francisco')->first();

        if (!$company) {
            $this->command->error('No se encontró la compañía Cocos Francisco. Ejecuta CompaniesSeeder primero.');
            return;
        }

        // Obtener categorías existentes (creadas por CategoriesSeeder)
        $categoriesCollection = Category::where('company_id', $company->id)->get();

        if ($categoriesCollection->isEmpty()) {
            $this->command->error('No se encontraron categorías. Ejecuta CategoriesSeeder primero.');
            return;
        }

        // Obtener todas las ubicaciones de la compañía
        $locations = Location::where('company_id', $company->id)->get();

        if ($locations->isEmpty()) {
            $this->command->error('No se encontraron ubicaciones. Ejecuta CompaniesSeeder primero.');
            return;
        }

        // Obtener el proveedor principal de la compañía
        $supplier = DB::table('suppliers')->where('company_id', $company->id)->first();
        $supplierId = $supplier?->id;

        // Cargar unidades disponibles
        $units = DB::table('units')->pluck('id', 'name');

        // Productos de Cocos Francisco
        $products = [
            // Bebidas
            ['name' => 'Agua de coco 1 LT', 'code' => 'BEB-AC-1L', 'sale_price' => 35.00, 'purchase_price' => 25.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Agua de coco natural 1 litro'],
            ['name' => 'Agua de coco ½ LT', 'code' => 'BEB-AC-500', 'sale_price' => 20.00, 'purchase_price' => 14.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Agua de coco natural medio litro'],
            ['name' => 'Horchata de coco 1 LT', 'code' => 'BEB-HC-1L', 'sale_price' => 40.00, 'purchase_price' => 28.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Horchata de coco 1 litro'],
            ['name' => 'Horchata de coco ½ LT', 'code' => 'BEB-HC-500', 'sale_price' => 22.00, 'purchase_price' => 16.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Horchata de coco medio litro'],
            ['name' => 'Tuba ½ LT', 'code' => 'BEB-TB-500', 'sale_price' => 25.00, 'purchase_price' => 18.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Tuba natural medio litro'],
            ['name' => 'Galón de agua de coco', 'code' => 'BEB-AC-GAL', 'sale_price' => 120.00, 'purchase_price' => 85.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Galón de agua de coco natural'],
            ['name' => 'Galón de horchata de coco', 'code' => 'BEB-HC-GAL', 'sale_price' => 135.00, 'purchase_price' => 95.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Galón de horchata de coco'],

            // Postres y más
            ['name' => 'Aceite de coco', 'code' => 'POST-AC-001', 'sale_price' => 85.00, 'purchase_price' => 60.00, 'category_name' => 'Postres y más', 'unit' => 'Litro', 'description' => 'Aceite de coco virgen'],
            ['name' => 'Cuala chica', 'code' => 'POST-CC-001', 'sale_price' => 15.00, 'purchase_price' => 10.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco tamaño chico'],
            ['name' => 'Cuala grande', 'code' => 'POST-CG-001', 'sale_price' => 25.00, 'purchase_price' => 18.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco tamaño grande'],
            ['name' => 'Dulce de leche', 'code' => 'POST-DL-001', 'sale_price' => 30.00, 'purchase_price' => 22.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Dulce de leche artesanal'],
            ['name' => 'Paquete de galletas (4 piezas)', 'code' => 'POST-GLL-4', 'sale_price' => 20.00, 'purchase_price' => 14.00, 'category_name' => 'Postres y más', 'unit' => 'Paquete', 'description' => 'Galletas de coco paquete 4 piezas'],
            ['name' => 'Caja de galletas (16 piezas)', 'code' => 'POST-GLL-16', 'sale_price' => 70.00, 'purchase_price' => 50.00, 'category_name' => 'Postres y más', 'unit' => 'Caja', 'description' => 'Galletas de coco caja 16 piezas'],
            ['name' => 'Rompope', 'code' => 'POST-RP-001', 'sale_price' => 45.00, 'purchase_price' => 32.00, 'category_name' => 'Postres y más', 'unit' => 'Litro', 'description' => 'Rompope tradicional'],

            // Dulces tradicionales de coco
            ['name' => 'Barrita de coco con fresa', 'code' => 'DULC-BF-001', 'sale_price' => 12.00, 'purchase_price' => 8.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de coco sabor fresa'],
            ['name' => 'Barrita de coco con leche y nuez', 'code' => 'DULC-BLN-001', 'sale_price' => 13.00, 'purchase_price' => 9.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de coco con leche y nuez'],
            ['name' => 'Barrita de coco con pasas', 'code' => 'DULC-BP-001', 'sale_price' => 12.00, 'purchase_price' => 8.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de coco con pasas'],
            ['name' => 'Barrita de coco (natural)', 'code' => 'DULC-BN-001', 'sale_price' => 10.00, 'purchase_price' => 7.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de coco natural'],
            ['name' => 'Barrita de coco con arándano', 'code' => 'DULC-BA-001', 'sale_price' => 13.00, 'purchase_price' => 9.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de coco con arándano'],
            ['name' => 'Barrita de rompope', 'code' => 'DULC-BR-001', 'sale_price' => 13.00, 'purchase_price' => 9.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barrita de rompope'],
            ['name' => 'Barra de nuez chica', 'code' => 'DULC-BNC-001', 'sale_price' => 15.00, 'purchase_price' => 10.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Barra de nuez tamaño chico'],
            ['name' => 'Cocada dominguera (piña, naranja y anís)', 'code' => 'DULC-CD-001', 'sale_price' => 18.00, 'purchase_price' => 12.00, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'description' => 'Cocada dominguera sabores mixtos'],

            // Cocadas y más
            ['name' => 'Caja de barras mixtas chicas', 'code' => 'COC-BMC-001', 'sale_price' => 95.00, 'purchase_price' => 68.00, 'category_name' => 'Cocadas y más', 'unit' => 'Caja', 'description' => 'Caja de barras mixtas chicas'],
            ['name' => 'Cocada velita de nuez', 'code' => 'COC-VN-001', 'sale_price' => 20.00, 'purchase_price' => 14.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Cocada velita con nuez'],
            ['name' => 'Cocada velita de limón', 'code' => 'COC-VL-001', 'sale_price' => 18.00, 'purchase_price' => 13.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Cocada velita sabor limón'],
            ['name' => 'Cocada sabores mixtos', 'code' => 'COC-SM-001', 'sale_price' => 16.00, 'purchase_price' => 11.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Cocada sabores mixtos'],
            ['name' => 'Pelizcadas de coco', 'code' => 'COC-PEL-001', 'sale_price' => 14.00, 'purchase_price' => 10.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Pelizcadas de coco tradicionales'],
            ['name' => 'Cocada horneada', 'code' => 'COC-HOR-001', 'sale_price' => 15.00, 'purchase_price' => 10.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Cocada horneada tradicional'],
            ['name' => 'Cocada horneada greñuda', 'code' => 'COC-HG-001', 'sale_price' => 17.00, 'purchase_price' => 12.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Cocada horneada greñuda'],
            ['name' => 'Duraznitos mixtos', 'code' => 'COC-DM-001', 'sale_price' => 16.00, 'purchase_price' => 11.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Duraznitos sabores mixtos'],
            ['name' => 'Duraznitos de leche de coco', 'code' => 'COC-DLC-001', 'sale_price' => 18.00, 'purchase_price' => 13.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Duraznitos de leche de coco'],
            ['name' => 'Limoncitos', 'code' => 'COC-LIM-001', 'sale_price' => 14.00, 'purchase_price' => 10.00, 'category_name' => 'Cocadas y más', 'unit' => 'Pieza', 'description' => 'Limoncitos de coco'],

            // Coco rallado y derivados
            ['name' => 'Bolsa de coco rallado natural 1 kg', 'code' => 'COR-N-1K', 'sale_price' => 75.00, 'purchase_price' => 52.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado natural 1 kg'],
            ['name' => 'Bolsa de coco rallado natural ½ kg', 'code' => 'COR-N-500', 'sale_price' => 40.00, 'purchase_price' => 28.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado natural medio kilo'],
            ['name' => 'Bolsa de coco rallado tostado natural 1 kg', 'code' => 'COR-TN-1K', 'sale_price' => 80.00, 'purchase_price' => 56.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado tostado natural 1 kg'],
            ['name' => 'Bolsa de coco rallado tostado natural ½ kg', 'code' => 'COR-TN-500', 'sale_price' => 42.00, 'purchase_price' => 30.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado tostado natural medio kilo'],
            ['name' => 'Bolsa de coco rallado sin azúcar 1 kg', 'code' => 'COR-SA-1K', 'sale_price' => 72.00, 'purchase_price' => 50.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado sin azúcar 1 kg'],
            ['name' => 'Bolsa de coco rallado sin azúcar ½ kg', 'code' => 'COR-SA-500', 'sale_price' => 38.00, 'purchase_price' => 27.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado sin azúcar medio kilo'],
            ['name' => 'Azúcar de coco', 'code' => 'COR-AZ-001', 'sale_price' => 95.00, 'purchase_price' => 68.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Azúcar de coco natural'],
        ];

        foreach ($products as $productData) {
            $category = $categoriesCollection->where('name', $productData['category_name'])->first();

            $product = Product::create([
                'name' => $productData['name'],
                'code' => $productData['code'],
                'purchase_price' => $productData['purchase_price'],
                'sale_price' => $productData['sale_price'],
                'description' => $productData['description'],
                'company_id' => $company->id,
                'category_id' => $category ? $category->id : null,
                'supplier_id' => $supplierId,
                'unit_id' => $units[$productData['unit']] ?? null,
            ]);

            // Asignar el producto a todas las ubicaciones de la compañía como activo
            $productLocationRecords = $locations->map(fn($location) => [
                'product_id' => $product->id,
                'location_id' => $location->id,
                'current_stock' => 0,
                'reserved_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'average_cost' => 0,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            DB::table('product_location')->insert($productLocationRecords);
        }

        $this->command->info('Creados ' . count($products) . ' productos de Cocos Francisco exitosamente');
        $this->command->info('Asignados a ' . $locations->count() . ' ubicaciones (' . (count($products) * $locations->count()) . ' registros en product_location)');
    }
}
