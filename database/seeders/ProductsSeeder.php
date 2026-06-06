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
     *
     * Productos y precios extraídos del archivo "Productos para DiDi.xlsx"
     * (lista de precios para entrega DiDi). El purchase_price se estima
     * al 65% del precio de venta cuando no se conoce el costo real.
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

        // Obtener el proveedor principal de la compañía (TESTUS PET SOLUTIONS)
        $supplier = DB::table('suppliers')->where('company_id', $company->id)->first();
        $supplierId = $supplier?->id;

        // Cargar unidades disponibles
        $units = DB::table('units')->pluck('id', 'name');

        /**
         * Catálogo de productos reales.
         * Estructura: [name, code, sale_price, purchase_price, category_name, unit, description?]
         * - Precios de venta: archivo "Productos para DiDi.xlsx".
         * - Precios de compra: estimados al 65% del precio de venta (margen bruto ~35%).
         * - Unidades: 'Litro', 'Kilogramo', 'Pieza', 'Caja', 'Paquete', 'Galón' (si existe).
         */
        $products = [
            // ============ BEBIDAS ============
            ['name' => 'Botella de agua de coco 1/2 LT', 'code' => 'BEB-BC-500', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Botella de agua de coco natural 500 ml'],
            ['name' => 'Botella de agua de coco 1 LT', 'code' => 'BEB-BC-1L', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Botella de agua de coco natural 1 L'],
            ['name' => 'Botella de horchata sin azúcar 1 LT', 'code' => 'BEB-HSA-1L', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Horchata de coco sin azúcar 1 L'],
            ['name' => 'Botella de horchata 1 LT', 'code' => 'BEB-HC-1L', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Horchata de coco 1 L'],
            ['name' => 'Botella de horchata 1/2 LT', 'code' => 'BEB-HC-500', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Horchata de coco 500 ml'],
            ['name' => 'Galón de horchata', 'code' => 'BEB-HC-GAL', 'sale_price' => 150, 'purchase_price' => 97.50, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Galón de horchata de coco'],
            ['name' => 'Galón de agua de coco 4 LT', 'code' => 'BEB-AC-GAL', 'sale_price' => 210, 'purchase_price' => 136.50, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Galón de agua de coco natural 4 L'],
            ['name' => 'Vaso de agua de coco mediano', 'code' => 'BEB-VM-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Vaso mediano de agua de coco'],
            ['name' => 'Vaso de agua de coco grande', 'code' => 'BEB-VG-001', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Vaso grande de agua de coco'],
            ['name' => 'Tuba 1/2 LT', 'code' => 'BEB-TB-500', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Tuba natural 500 ml'],
            ['name' => 'Tuba 1 LT', 'code' => 'BEB-TB-1L', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Tuba natural 1 L'],
            ['name' => 'Galón de tuba', 'code' => 'BEB-TB-GAL', 'sale_price' => 150, 'purchase_price' => 97.50, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Galón de tuba natural'],
            ['name' => 'Mariscoco sin agua', 'code' => 'BEB-MR-S/A', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Mariscoco preparado sin agua'],
            ['name' => 'Mariscoco con agua', 'code' => 'BEB-MR-C/A', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'description' => 'Mariscoco preparado con agua'],

            // ============ COCO NATURAL ============
            ['name' => 'Coco partido en bolsa', 'code' => 'NAT-CPB-001', 'sale_price' => 20, 'purchase_price' => 13.00, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Bolsa de coco partido'],
            ['name' => 'Coco tomado en el local', 'code' => 'NAT-CT-001', 'sale_price' => 70, 'purchase_price' => 45.50, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco fresco para tomar en el local'],
            ['name' => 'Coco socato pieza', 'code' => 'NAT-CSC-001', 'sale_price' => 23, 'purchase_price' => 14.95, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco socato por pieza'],
            ['name' => 'Coco mayoreo (100 pzs)', 'code' => 'NAT-CM-100', 'sale_price' => 38, 'purchase_price' => 24.70, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco por mayoreo, paquete de 100 piezas'],
            ['name' => 'Coco destopado 3/4', 'code' => 'NAT-CD-3/4', 'sale_price' => 48, 'purchase_price' => 31.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco destopado al 3/4'],
            ['name' => 'Coco seco', 'code' => 'NAT-CS-001', 'sale_price' => 28, 'purchase_price' => 18.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco seco entero'],
            ['name' => 'Coco destopado seco', 'code' => 'NAT-CDS-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco seco destopado'],
            ['name' => 'Coco cacheteado', 'code' => 'NAT-CC-001', 'sale_price' => 48, 'purchase_price' => 31.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Coco cacheteado fresco'],
            ['name' => 'Racimo de coco', 'code' => 'NAT-RC-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Racimo de coco (precio variable según tamaño)'],
            ['name' => 'Cazuela de coco', 'code' => 'NAT-CZ-001', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'description' => 'Cazuela preparada con coco'],

            // ============ DERIVADOS DE COCO ============
            ['name' => 'Aceite de coco', 'code' => 'DER-AC-001', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Derivados de coco', 'unit' => 'Litro', 'description' => 'Aceite de coco virgen'],
            ['name' => 'Copra 1 kilo', 'code' => 'DER-COP-1K', 'sale_price' => 50, 'purchase_price' => 32.50, 'category_name' => 'Derivados de coco', 'unit' => 'Kilogramo', 'description' => 'Copra de coco 1 kg'],
            ['name' => 'Pulpa de coco por kilo', 'code' => 'DER-PC-1K', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Derivados de coco', 'unit' => 'Kilogramo', 'description' => 'Pulpa de coco fresca 1 kg'],
            ['name' => 'Flor de coco', 'code' => 'DER-FC-001', 'sale_price' => 350, 'purchase_price' => 227.50, 'category_name' => 'Derivados de coco', 'unit' => 'Pieza', 'description' => 'Flor de coco fresca'],
            ['name' => 'Harina de coco', 'code' => 'DER-HC-001', 'sale_price' => 85, 'purchase_price' => 55.25, 'category_name' => 'Derivados de coco', 'unit' => 'Kilogramo', 'description' => 'Harina de coco'],

            // ============ POSTRES Y MÁS ============
            ['name' => 'Rompope', 'code' => 'POS-RP-001', 'sale_price' => 180, 'purchase_price' => 117.00, 'category_name' => 'Postres y más', 'unit' => 'Litro', 'description' => 'Rompope artesanal'],
            ['name' => 'Dulce de leche', 'code' => 'POS-DL-001', 'sale_price' => 15, 'purchase_price' => 9.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Dulce de leche artesanal'],
            ['name' => 'Cuala', 'code' => 'POS-CUA-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco chica'],
            ['name' => 'Cuala grande', 'code' => 'POS-CUAG-001', 'sale_price' => 85, 'purchase_price' => 55.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco grande'],
            ['name' => 'Polvorín', 'code' => 'POS-POL-001', 'sale_price' => 30, 'purchase_price' => 19.50, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Polvorín de coco'],
            ['name' => 'Tostadas', 'code' => 'POS-TOS-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Tostadas de coco'],
            ['name' => 'Manzanitas', 'code' => 'POS-MAN-001', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Manzanitas de coco (precio variable: $40, $60 u $80)'],
            ['name' => 'Paquete/Botella 1 LTR', 'code' => 'POS-PB-1L', 'sale_price' => 320, 'purchase_price' => 208.00, 'category_name' => 'Postres y más', 'unit' => 'Paquete', 'description' => 'Paquete de botellas 1 L'],
            ['name' => 'Paquete/Botella 500 ML', 'code' => 'POS-PB-500', 'sale_price' => 490, 'purchase_price' => 318.50, 'category_name' => 'Postres y más', 'unit' => 'Paquete', 'description' => 'Paquete de botellas 500 ml'],
            ['name' => 'Paquete/Galones', 'code' => 'POS-PG-001', 'sale_price' => 320, 'purchase_price' => 208.00, 'category_name' => 'Postres y más', 'unit' => 'Paquete', 'description' => 'Paquete de galones'],
            ['name' => 'Botella de 1 LT individual', 'code' => 'POS-BI-1L', 'sale_price' => 5, 'purchase_price' => 3.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Botella individual 1 L'],
            ['name' => 'Botella de 1/2 LT individual', 'code' => 'POS-BI-500', 'sale_price' => 5, 'purchase_price' => 3.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Botella individual 1/2 L'],

            // ============ COCADAS ============
            ['name' => 'Cocada de nuez 1 pza', 'code' => 'COC-CN-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de nuez por pieza'],
            ['name' => 'Cocada de nuez caja', 'code' => 'COC-CN-CJ', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada de nuez por caja'],
            ['name' => 'Cocada de limón 1 pza', 'code' => 'COC-CL-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de limón por pieza'],
            ['name' => 'Cocada de limón caja', 'code' => 'COC-CL-CJ', 'sale_price' => 85, 'purchase_price' => 55.25, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada de limón por caja'],
            ['name' => 'Cocada greñuda 1 pza', 'code' => 'COC-CG-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada greñuda por pieza'],
            ['name' => 'Cocada greñuda caja', 'code' => 'COC-CG-CJ', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada greñuda por caja'],
            ['name' => 'Cocada mixta grande 1 pza', 'code' => 'COC-CMG-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada mixta grande por pieza'],
            ['name' => 'Cocada mixta grande caja', 'code' => 'COC-CMG-CJ', 'sale_price' => 120, 'purchase_price' => 78.00, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada mixta grande por caja'],
            ['name' => 'Cocada mixta chica 1 pza', 'code' => 'COC-CMC-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada mixta chica por pieza'],
            ['name' => 'Cocada mixta chica caja', 'code' => 'COC-CMC-CJ', 'sale_price' => 130, 'purchase_price' => 84.50, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada mixta chica por caja'],
            ['name' => 'Cocada horneada caja', 'code' => 'COC-CH-CJ', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada horneada por caja'],
            ['name' => 'Cocada de bola 1 pza', 'code' => 'COC-CB-1P', 'sale_price' => 30, 'purchase_price' => 19.50, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de bola por pieza'],

            // ============ BARRAS ============
            ['name' => 'Barra mixta caja', 'code' => 'BAR-BM-CJ', 'sale_price' => 185, 'purchase_price' => 120.25, 'category_name' => 'Barras', 'unit' => 'Caja', 'description' => 'Caja de barras mixtas'],
            ['name' => 'Barra mixta chica 1 pza', 'code' => 'BAR-BMC-1P', 'sale_price' => 10, 'purchase_price' => 6.50, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra mixta chica por pieza'],
            ['name' => 'Barra de nuez grande', 'code' => 'BAR-BNG-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de nuez grande'],
            ['name' => 'Barra de nuez chica', 'code' => 'BAR-BNC-001', 'sale_price' => 20, 'purchase_price' => 13.00, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de nuez chica'],
            ['name' => 'Barra de coco', 'code' => 'BAR-BC-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de coco natural'],
            ['name' => 'Barra de coco con fresa', 'code' => 'BAR-BCF-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de coco con fresa'],
            ['name' => 'Barra de rompope', 'code' => 'BAR-BR-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de rompope'],
            ['name' => 'Barra de leche de coco', 'code' => 'BAR-BLC-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de leche de coco'],
            ['name' => 'Barra de leche de coco con nuez', 'code' => 'BAR-BLCN-001', 'sale_price' => 45, 'purchase_price' => 29.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de leche de coco con nuez'],
            ['name' => 'Banderita grande', 'code' => 'BAR-BAG-001', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Banderita grande de coco'],
            ['name' => 'Banderita chica', 'code' => 'BAR-BAC-001', 'sale_price' => 20, 'purchase_price' => 13.00, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Banderita chica de coco'],
            ['name' => 'Barra coco nuez entera chica', 'code' => 'BAR-BNEC-001', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Barras', 'unit' => 'Pieza', 'description' => 'Barra de coco con nuez entera chica'],

            // ============ DURAZNITOS Y LIMONCITOS ============
            ['name' => 'Duraznitos 1 pza', 'code' => 'DUR-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'description' => 'Duraznito de coco por pieza'],
            ['name' => 'Duraznitos caja', 'code' => 'DUR-CJ', 'sale_price' => 75, 'purchase_price' => 48.75, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Caja', 'description' => 'Caja de duraznitos de coco'],
            ['name' => 'Limoncitos 1 pza', 'code' => 'LIM-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'description' => 'Limoncito de coco por pieza'],
            ['name' => 'Limoncitos caja', 'code' => 'LIM-CJ', 'sale_price' => 95, 'purchase_price' => 61.75, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Caja', 'description' => 'Caja de limoncitos de coco'],

            // ============ PELLIZCADAS Y PELIZCADAS ============
            ['name' => 'Pellizcada 1 pza', 'code' => 'PEL-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Pellizcadas y pelizcadas', 'unit' => 'Pieza', 'description' => 'Pellizcada de coco por pieza'],
            ['name' => 'Pellizcada caja', 'code' => 'PEL-CJ', 'sale_price' => 120, 'purchase_price' => 78.00, 'category_name' => 'Pellizcadas y pelizcadas', 'unit' => 'Caja', 'description' => 'Caja de pellizcadas de coco'],

            // ============ DULCES TRADICIONALES DE COCO ============
            ['name' => 'Galletas caja', 'code' => 'DUL-GLL-CJ', 'sale_price' => 75, 'purchase_price' => 48.75, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Caja', 'description' => 'Caja de galletas de coco'],
            ['name' => 'Galletas 4 pzs', 'code' => 'DUL-GLL-4P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Paquete', 'description' => 'Paquete de 4 galletas de coco'],

            // ============ COCO RALLADO Y DERIVADOS ============
            ['name' => 'Coco rallado 1 KG', 'code' => 'RAL-CR-1K', 'sale_price' => 120, 'purchase_price' => 78.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado natural 1 kg'],
            ['name' => 'Coco rallado 1/2 KG', 'code' => 'RAL-CR-500', 'sale_price' => 75, 'purchase_price' => 48.75, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Coco rallado natural 1/2 kg'],
            ['name' => 'Bolsa de coco rallado 1 KG', 'code' => 'RAL-BCR-1K', 'sale_price' => 120, 'purchase_price' => 78.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'description' => 'Bolsa de coco rallado 1 kg'],
            ['name' => 'Caja de coco rallado 10 KG', 'code' => 'RAL-CCR-10K', 'sale_price' => 1000, 'purchase_price' => 650.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Caja', 'description' => 'Caja de coco rallado 10 kg (mayoreo)'],

            // ============ PROMOCIONES ============
            ['name' => 'Promo 2 rompopes', 'code' => 'PROM-2RP', 'sale_price' => 300, 'purchase_price' => 195.00, 'category_name' => 'Promociones', 'unit' => 'Paquete', 'description' => 'Promoción 2 botellas de rompope'],
            ['name' => 'Promo 2 bolsas de coco', 'code' => 'PROM-2BC', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Promociones', 'unit' => 'Paquete', 'description' => 'Promoción 2 bolsas de coco partido'],
            ['name' => 'Promo horchata 3 x', 'code' => 'PROM-HX3', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Promociones', 'unit' => 'Paquete', 'description' => 'Promoción 3 horchatas'],
        ];

        foreach ($products as $productData) {
            $category = $categoriesCollection->where('name', $productData['category_name'])->first();

            $product = Product::create([
                'name' => $productData['name'],
                'code' => $productData['code'],
                'purchase_price' => $productData['purchase_price'] > 0 ? $productData['purchase_price'] : null,
                'sale_price' => $productData['sale_price'] > 0 ? $productData['sale_price'] : null,
                'description' => $productData['description'] ?? null,
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

        $this->command->info('Creados ' . count($products) . ' productos reales de Cocos Francisco');
        $this->command->info('Asignados a ' . $locations->count() . ' ubicaciones (' . (count($products) * $locations->count()) . ' registros en product_location)');
    }
}
