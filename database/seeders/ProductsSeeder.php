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
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('product_location')->whereIn('product_id', Product::pluck('id'))->delete();
        Product::query()->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $company = Company::where('name', 'Cocos Francisco')->first();

        if (!$company) {
            $this->command->error('No se encontró la compañía Cocos Francisco. Ejecuta CompaniesSeeder primero.');
            return;
        }

        $categoriesCollection = Category::where('company_id', $company->id)->get();

        if ($categoriesCollection->isEmpty()) {
            $this->command->error('No se encontraron categorías. Ejecuta CategoriesSeeder primero.');
            return;
        }

        $locations = Location::where('company_id', $company->id)->get();

        if ($locations->isEmpty()) {
            $this->command->error('No se encontraron ubicaciones. Ejecuta CompaniesSeeder primero.');
            return;
        }

        $suppliers = DB::table('suppliers')
            ->where('company_id', $company->id)
            ->pluck('id', 'name');

        $units = DB::table('units')->pluck('id', 'name');

        /**
         * Catálogo de productos base de Cocos Francisco.
         *
         * Reglas aplicadas:
         *  - Cada entrada es la unidad mínima vendible (1 pieza, 1 botella, 1 kg a granel).
         *  - Empaques (cajas, bultos, promos) NO se siembran: el usuario los crea
         *    en la app como product_packages con la cantidad y precio reales.
         *  - Bolsa/envase de peso fijo → Pieza (el peso va en la descripción).
         *  - Solo lo que se pesa al momento → Kilogramo / Litro.
         *
         * Precios de venta: lista "Productos para DiDi.xlsx" (sucursal).
         * Precios de compra: estimados al 65% del precio de venta.
         */
        $products = [
            // ============ MATERIAS PRIMAS (raw_material) ============
            ['name' => 'Coco Entero', 'code' => 'MP-COCO-001', 'sale_price' => 0, 'purchase_price' => 18, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'raw_material', 'supplier_name' => 'PROVEEDOR DE COCOS', 'description' => 'Coco entero fresco sin procesar. Materia prima principal de la planta.'],

            // ============ PRODUCTOS INTERMEDIOS (processed) ============
            // Se miden a granel; no tienen proveedor porque se producen en planta.
            ['name' => 'Agua de Coco (a granel)', 'code' => 'INT-AGUA-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Derivados de coco', 'unit' => 'Litro', 'product_type' => 'processed', 'description' => 'Agua de coco natural a granel. Insumo para bebidas embotelladas.'],
            ['name' => 'Pulpa de Coco (a granel)', 'code' => 'INT-PULPA-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Derivados de coco', 'unit' => 'Kilogramo', 'product_type' => 'processed', 'description' => 'Pulpa de coco fresca a granel. Insumo para cocadas, barras y rallado.'],
            ['name' => 'Horchata base', 'code' => 'INT-HORCH-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'product_type' => 'processed', 'description' => 'Horchata de coco concentrada a granel. Se envasa en botellas/galones.'],
            ['name' => 'Tuba base', 'code' => 'INT-TUBA-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Bebidas', 'unit' => 'Litro', 'product_type' => 'processed', 'description' => 'Tuba natural a granel. Se envasa en botellas/galones.'],

            // ============ BEBIDAS (commercial) ============
            // Cada envase = 1 pieza. El volumen va en la descripción.
            ['name' => 'Botella de agua de coco 1/2 LT', 'code' => 'BEB-BC-500', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Botella de agua de coco natural 500 ml'],
            ['name' => 'Botella de agua de coco 1 LT', 'code' => 'BEB-BC-1L', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Botella de agua de coco natural 1 L'],
            ['name' => 'Botella de horchata sin azúcar 1 LT', 'code' => 'BEB-HSA-1L', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Horchata de coco sin azúcar 1 L'],
            ['name' => 'Botella de horchata 1 LT', 'code' => 'BEB-HC-1L', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Horchata de coco 1 L'],
            ['name' => 'Botella de horchata 1/2 LT', 'code' => 'BEB-HC-500', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Horchata de coco 500 ml'],
            ['name' => 'Galón de horchata', 'code' => 'BEB-HC-GAL', 'sale_price' => 150, 'purchase_price' => 97.50, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Galón de horchata de coco'],
            ['name' => 'Galón de agua de coco 4 LT', 'code' => 'BEB-AC-GAL', 'sale_price' => 210, 'purchase_price' => 136.50, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Galón de agua de coco natural 4 L'],
            ['name' => 'Vaso de agua de coco mediano', 'code' => 'BEB-VM-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Vaso mediano de agua de coco'],
            ['name' => 'Vaso de agua de coco grande', 'code' => 'BEB-VG-001', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Vaso grande de agua de coco'],
            ['name' => 'Tuba 1/2 LT', 'code' => 'BEB-TB-500', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Tuba natural 500 ml'],
            ['name' => 'Tuba 1 LT', 'code' => 'BEB-TB-1L', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Tuba natural 1 L'],
            ['name' => 'Galón de tuba', 'code' => 'BEB-TB-GAL', 'sale_price' => 150, 'purchase_price' => 97.50, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Galón de tuba natural'],
            ['name' => 'Mariscoco sin agua', 'code' => 'BEB-MR-S/A', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Mariscoco preparado sin agua'],
            ['name' => 'Mariscoco con agua', 'code' => 'BEB-MR-C/A', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Bebidas', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Mariscoco preparado con agua'],

            // ============ COCO NATURAL (commercial) ============
            // NAT-CPB-001: se vende por pieza (bolsa lista), es commercial no raw_material.
            ['name' => 'Coco partido en bolsa', 'code' => 'NAT-CPB-001', 'sale_price' => 20, 'purchase_price' => 13.00, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'supplier_name' => 'PROVEEDOR DE COCOS', 'description' => 'Bolsa de coco partido listo para extraer agua y pulpa'],
            ['name' => 'Coco tomado en el local', 'code' => 'NAT-CT-001', 'sale_price' => 70, 'purchase_price' => 45.50, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco fresco para tomar en el local'],
            ['name' => 'Coco socato pieza', 'code' => 'NAT-CSC-001', 'sale_price' => 23, 'purchase_price' => 14.95, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco socato por pieza'],
            ['name' => 'Coco destopado 3/4', 'code' => 'NAT-CD-3/4', 'sale_price' => 48, 'purchase_price' => 31.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco destopado al 3/4'],
            ['name' => 'Coco seco', 'code' => 'NAT-CS-001', 'sale_price' => 28, 'purchase_price' => 18.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco seco entero'],
            ['name' => 'Coco destopado seco', 'code' => 'NAT-CDS-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco seco destopado'],
            ['name' => 'Coco cacheteado', 'code' => 'NAT-CC-001', 'sale_price' => 48, 'purchase_price' => 31.20, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Coco cacheteado fresco'],
            ['name' => 'Racimo de coco', 'code' => 'NAT-RC-001', 'sale_price' => 0, 'purchase_price' => 0, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Racimo de coco (precio variable según tamaño)'],
            ['name' => 'Cazuela de coco', 'code' => 'NAT-CZ-001', 'sale_price' => 40, 'purchase_price' => 26.00, 'category_name' => 'Coco natural', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Cazuela preparada con coco'],

            // ============ DERIVADOS DE COCO (commercial) ============
            // Bolsas de peso fijo → Pieza. Pulpa → Kilogramo (se pesa al momento).
            ['name' => 'Aceite de coco', 'code' => 'DER-AC-001', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Derivados de coco', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Aceite de coco virgen'],
            ['name' => 'Copra 1 kilo', 'code' => 'DER-COP-1K', 'sale_price' => 50, 'purchase_price' => 32.50, 'category_name' => 'Derivados de coco', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Copra de coco en bolsa de 1 kg'],
            ['name' => 'Pulpa de coco por kilo', 'code' => 'DER-PC-1K', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Derivados de coco', 'unit' => 'Kilogramo', 'product_type' => 'commercial', 'description' => 'Pulpa de coco fresca vendida por kilo'],
            ['name' => 'Flor de coco', 'code' => 'DER-FC-001', 'sale_price' => 350, 'purchase_price' => 227.50, 'category_name' => 'Derivados de coco', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Flor de coco fresca'],
            ['name' => 'Harina de coco', 'code' => 'DER-HC-001', 'sale_price' => 85, 'purchase_price' => 55.25, 'category_name' => 'Derivados de coco', 'unit' => 'Pieza', 'product_type' => 'commercial', 'description' => 'Harina de coco en bolsa de 1 kg'],

            // ============ POSTRES Y MÁS ============
            ['name' => 'Rompope', 'code' => 'POS-RP-001', 'sale_price' => 180, 'purchase_price' => 117.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Rompope artesanal'],
            ['name' => 'Dulce de leche', 'code' => 'POS-DL-001', 'sale_price' => 15, 'purchase_price' => 9.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Dulce de leche artesanal'],
            ['name' => 'Cuala', 'code' => 'POS-CUA-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco chica'],
            ['name' => 'Cuala grande', 'code' => 'POS-CUAG-001', 'sale_price' => 85, 'purchase_price' => 55.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Cuala de coco grande'],
            ['name' => 'Polvorín', 'code' => 'POS-POL-001', 'sale_price' => 30, 'purchase_price' => 19.50, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Polvorín de coco'],
            ['name' => 'Tostadas', 'code' => 'POS-TOS-001', 'sale_price' => 35, 'purchase_price' => 22.75, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Tostadas de coco'],
            ['name' => 'Manzanitas', 'code' => 'POS-MAN-001', 'sale_price' => 60, 'purchase_price' => 39.00, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Manzanitas de coco (precio variable: $40, $60 u $80)'],
            ['name' => 'Botella de 1 LT individual', 'code' => 'POS-BI-1L', 'sale_price' => 5, 'purchase_price' => 3.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Botella vacía individual 1 L'],
            ['name' => 'Botella de 1/2 LT individual', 'code' => 'POS-BI-500', 'sale_price' => 5, 'purchase_price' => 3.25, 'category_name' => 'Postres y más', 'unit' => 'Pieza', 'description' => 'Botella vacía individual 1/2 L'],

            // ============ COCADAS ============
            // Las versiones "caja" son paquetes: el usuario los crea en la app.
            ['name' => 'Cocada de nuez 1 pza', 'code' => 'COC-CN-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de nuez por pieza'],
            ['name' => 'Cocada de limón 1 pza', 'code' => 'COC-CL-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de limón por pieza'],
            ['name' => 'Cocada greñuda 1 pza', 'code' => 'COC-CG-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada greñuda por pieza'],
            ['name' => 'Cocada mixta grande 1 pza', 'code' => 'COC-CMG-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada mixta grande por pieza'],
            ['name' => 'Cocada mixta chica 1 pza', 'code' => 'COC-CMC-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada mixta chica por pieza'],
            ['name' => 'Cocada horneada caja', 'code' => 'COC-CH-CJ', 'sale_price' => 100, 'purchase_price' => 65.00, 'category_name' => 'Cocadas', 'unit' => 'Caja', 'description' => 'Cocada horneada por caja (no existe versión pieza individual)'],
            ['name' => 'Cocada de bola 1 pza', 'code' => 'COC-CB-1P', 'sale_price' => 30, 'purchase_price' => 19.50, 'category_name' => 'Cocadas', 'unit' => 'Pieza', 'description' => 'Cocada de bola por pieza'],

            // ============ BARRAS ============
            // La versión "caja" es un paquete: el usuario la crea en la app.
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
            // Las versiones "caja" son paquetes: el usuario las crea en la app.
            ['name' => 'Duraznitos 1 pza', 'code' => 'DUR-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'description' => 'Duraznito de coco por pieza'],
            ['name' => 'Limoncitos 1 pza', 'code' => 'LIM-1P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'description' => 'Limoncito de coco por pieza'],

            // ============ PELLIZCADAS Y PELIZCADAS ============
            // La versión "caja" es un paquete: el usuario la crea en la app.
            ['name' => 'Pellizcada 1 pza', 'code' => 'PEL-1P', 'sale_price' => 18, 'purchase_price' => 11.70, 'category_name' => 'Pellizcadas y pelizcadas', 'unit' => 'Pieza', 'description' => 'Pellizcada de coco por pieza'],

            // ============ DULCES TRADICIONALES DE COCO ============
            // No existe "Galleta 1 pza" con precio; se dejan como productos sueltos.
            ['name' => 'Galletas caja', 'code' => 'DUL-GLL-CJ', 'sale_price' => 75, 'purchase_price' => 48.75, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Caja', 'description' => 'Caja de galletas de coco'],
            ['name' => 'Galletas 4 pzs', 'code' => 'DUL-GLL-4P', 'sale_price' => 25, 'purchase_price' => 16.25, 'category_name' => 'Dulces tradicionales de coco', 'unit' => 'Paquete', 'description' => 'Paquete de 4 galletas de coco'],

            // ============ COCO RALLADO Y DERIVADOS ============
            // Bolsas de peso fijo → Pieza (peso en descripción).
            ['name' => 'Coco rallado 1 KG', 'code' => 'RAL-CR-1K', 'sale_price' => 120, 'purchase_price' => 78.00, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'description' => 'Coco rallado natural en bolsa de 1 kg'],
            ['name' => 'Coco rallado 1/2 KG', 'code' => 'RAL-CR-500', 'sale_price' => 75, 'purchase_price' => 48.75, 'category_name' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'description' => 'Coco rallado natural en bolsa de 1/2 kg'],
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
                'supplier_id' => isset($productData['supplier_name']) ? ($suppliers[$productData['supplier_name']] ?? null) : null,
                'unit_id' => $units[$productData['unit']] ?? null,
                'product_type' => $productData['product_type'] ?? 'commercial',
            ]);

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

        $this->command->info('Creados ' . count($products) . ' productos de Cocos Francisco');
        $this->command->info('Asignados a ' . $locations->count() . ' ubicaciones (' . (count($products) * $locations->count()) . ' registros en product_location)');
    }
}
