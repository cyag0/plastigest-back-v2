<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ProductsSeeder — Catálogo real de Cocos Francisco.
 *
 * Fuente: archivo "DATOS COCOS FCO - Hoja 1.csv" (sección de productos, filas 0001–0102).
 *
 * Reglas aplicadas (acordadas con el cliente):
 *  - Las filas marcadas como "Promo / Promoción" se IGNORAN (se arman en la app).
 *  - Las filas cuya UNIDAD DE MEDIDA es "Paquete/Paquetes" NO son productos: se
 *    siembran como product_packages en PackageSeeder, ligadas a un producto base.
 *  - El resto son productos. Si el nombre dice "PAQ"/"CAJA" pero la unidad NO es
 *    paquete (ej. "Caja de coco rallado 10KG" en Kg), se deja como producto normal.
 *  - Códigos semánticos (no los 0001–0102 del CSV).
 *  - Tipo: "Producto Procesado" → processed, "Materia Prima" → raw_material.
 *  - Proveedor "Produccion CF" → null (se produce en planta).
 *
 * Bases derivadas: algunos paquetes del CSV no tienen una pieza individual
 * (Cocada horneada, Polvorín, Galleta, Harina, Azúcar, Contenedor Glad). Como un
 * product_package requiere un producto base, esas piezas base se crean aquí con
 * `for_sale = false` (solo existen para anclar su paquete).
 */
class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('product_packages')->delete();
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

        // Traducción del nombre de proveedor del CSV → nombre real en `suppliers`.
        // null = se produce en planta (sin proveedor).
        $supplierMap = [
            'Produccion CF'   => null,
            'Huerteros'       => 'Huerteros',
            'Colima Tropical' => 'Colima Tropical',
            'Sofia Gomez'     => 'Sofía Gómez',
            'Osmar Talpa'     => 'OSMAR ROMPOPE TALPA',
            'TESTUS'          => 'TESTUS PET SOLUTIONS',
            'MAZAPLASTICOS'   => 'MAZAPLASTICOS',
            'SAMS CLUB'       => "Sam's Club",
        ];

        $units = DB::table('units')->pluck('id', 'name');

        /**
         * Catálogo. Campos:
         *   name, code, purchase, sale, category, unit, type, supplier, for_sale, description
         * (purchase/sale en 0 se guardan como null).
         */
        $products = [
            // ════════════════════ BEBIDAS (procesadas en planta) ════════════════════
            ['name' => 'Agua de Coco 1 Litro', 'code' => 'BEB-AC-1L', 'purchase' => 0, 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Agua de Coco 1/2 Litro', 'code' => 'BEB-AC-500', 'purchase' => 0, 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Galón de Agua de Coco 4 Lt', 'code' => 'BEB-AC-GAL', 'purchase' => 0, 'sale' => 230, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Vaso de Agua de Coco 1 Lt', 'code' => 'BEB-VAC-1L', 'purchase' => 0, 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Vaso de Agua de Coco 1/2 Lt', 'code' => 'BEB-VAC-500', 'purchase' => 0, 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Tuba 1 Lt', 'code' => 'BEB-TB-1L', 'purchase' => 0, 'sale' => 40, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Botella Tuba 1/2 Lt', 'code' => 'BEB-TB-500', 'purchase' => 0, 'sale' => 25, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Galón de Tuba 4 Lt', 'code' => 'BEB-TB-GAL', 'purchase' => 0, 'sale' => 130, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Horchata de Coco 1 Lt', 'code' => 'BEB-HC-1L', 'purchase' => 0, 'sale' => 40, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Horchata de Coco 1/2 Lt', 'code' => 'BEB-HC-500', 'purchase' => 0, 'sale' => 25, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Galón de Horchata de Coco 4 Lt', 'code' => 'BEB-HC-GAL', 'purchase' => 0, 'sale' => 130, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Horchata 1 Lt Sin Azúcar', 'code' => 'BEB-HSA-1L', 'purchase' => 0, 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Horchata 1/2 Lt Sin Azúcar', 'code' => 'BEB-HSA-500', 'purchase' => 0, 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Galón Horchata 4 Lt Sin Azúcar', 'code' => 'BEB-HSA-GAL', 'purchase' => 0, 'sale' => 230, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Coco para tomar en sucursal', 'code' => 'BEB-CT-001', 'purchase' => 0, 'sale' => 85, 'category' => 'Bebidas', 'unit' => 'Litro', 'type' => 'processed', 'supplier' => 'Produccion CF'],

            // ════════════════════ DERIVADOS DE COCO ════════════════════
            ['name' => 'Bolsa con Pulpa de Coco', 'code' => 'DER-PULPA-BOL', 'purchase' => 0, 'sale' => 20, 'category' => 'Derivados de coco', 'unit' => 'Gramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Aceite de Coco Natural 180 ml', 'code' => 'DER-ACN-180', 'purchase' => 45, 'sale' => 100, 'category' => 'Derivados de coco', 'unit' => 'Mililitro', 'type' => 'raw_material', 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Natural 1 Lt', 'code' => 'DER-ACN-1L', 'purchase' => 180, 'sale' => 550, 'category' => 'Derivados de coco', 'unit' => 'Litro', 'type' => 'raw_material', 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Cocina 1/2 Lt', 'code' => 'DER-ACC-500', 'purchase' => 50, 'sale' => 150, 'category' => 'Derivados de coco', 'unit' => 'Mililitro', 'type' => 'raw_material', 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Cocina 1 Lt', 'code' => 'DER-ACC-1L', 'purchase' => 200, 'sale' => 600, 'category' => 'Derivados de coco', 'unit' => 'Litro', 'type' => 'raw_material', 'supplier' => 'Colima Tropical'],
            ['name' => 'Rompope de Coco', 'code' => 'DER-RP-001', 'purchase' => 70, 'sale' => 180, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'Osmar Talpa'],

            // ════════════════════ COCO NATURAL (materia prima) ════════════════════
            ['name' => 'Coco Entero', 'code' => 'COC-ENT-001', 'purchase' => 15, 'sale' => 38, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'Huerteros'],
            ['name' => 'Pieza de Pulpa de Coco', 'code' => 'COC-PUL-1P', 'purchase' => 0, 'sale' => 23, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'Huerteros'],
            ['name' => 'Cubeta de Coco', 'code' => 'COC-CUB-001', 'purchase' => 0, 'sale' => 400, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'Huerteros'],

            // ════════════════════ COCADAS ════════════════════
            ['name' => 'Cocada de Nuez 1 pza', 'code' => 'CCD-NZ-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada de Limón 1 pza', 'code' => 'CCD-LM-1P', 'purchase' => 0, 'sale' => 18, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Greñuda 1 pza', 'code' => 'CCD-GR-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Mixta Grande 1 pza', 'code' => 'CCD-MG-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Mixta Chica 1 pza', 'code' => 'CCD-MC-1P', 'purchase' => 0, 'sale' => 18, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada de Bola 1 pza', 'code' => 'CCD-BL-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            // Base derivada: la "Cocada Horneada" solo se vende en paquete de 6 (no hay pieza con precio en el CSV).
            ['name' => 'Cocada Horneada 1 pza', 'code' => 'CCD-HOR-1P', 'purchase' => 0, 'sale' => 0, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF', 'for_sale' => false, 'description' => 'Pieza base; se vende en paquete de 6.'],

            // ════════════════════ BARRAS ════════════════════
            ['name' => 'Barra Mixta Chica 1 pza', 'code' => 'BAR-MX-1P', 'purchase' => 0, 'sale' => 10, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Nuez Chica', 'code' => 'BAR-NZ-CH', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Coco', 'code' => 'BAR-CC-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Coco con Fresa', 'code' => 'BAR-CF-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Rompope', 'code' => 'BAR-RP-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Banderita Chica', 'code' => 'BAR-BN-CH', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra Coco Nuez', 'code' => 'BAR-CN-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Leche de Coco', 'code' => 'BAR-LC-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Leche de Coco con Nuez', 'code' => 'BAR-LCN-001', 'purchase' => 0, 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],

            // ════════════════════ DURAZNITOS Y LIMONCITOS ════════════════════
            ['name' => 'Duraznitos 1 pza', 'code' => 'DUR-DZ-1P', 'purchase' => 0, 'sale' => 20, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Limoncitos 1 pza', 'code' => 'DUR-LM-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Duraznito de Rompope 1 pza', 'code' => 'DUR-DR-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Duraznito de Leche 1 pza', 'code' => 'DUR-DL-1P', 'purchase' => 0, 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],

            // ════════════════════ PELLIZCADAS ════════════════════
            ['name' => 'Pellizcada 1 pza', 'code' => 'PEL-1P', 'purchase' => 0, 'sale' => 20, 'category' => 'Pellizcadas y pelizcadas', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],

            // ════════════════════ POSTRES Y MÁS ════════════════════
            ['name' => 'Dulce de Leche', 'code' => 'POS-DL-001', 'purchase' => 0, 'sale' => 15, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cuala', 'code' => 'POS-CU-001', 'purchase' => 0, 'sale' => 35, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Cuala Grande', 'code' => 'POS-CU-GDE', 'purchase' => 0, 'sale' => 85, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            // Base derivada: el polvorín se vende como paquete (PQ POLVORIN).
            ['name' => 'Polvorín 1 pza', 'code' => 'POS-POL-1P', 'purchase' => 0, 'sale' => 0, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Sofia Gomez', 'for_sale' => false, 'description' => 'Pieza base; se vende empaquetado.'],

            // ════════════════════ DULCES TRADICIONALES ════════════════════
            // Base derivada: la galleta se vende en paquetes de 16 y de 4 piezas.
            ['name' => 'Galleta de Coco 1 pza', 'code' => 'DUL-GLL-1P', 'purchase' => 0, 'sale' => 0, 'category' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'type' => 'processed', 'supplier' => 'Sofia Gomez', 'for_sale' => false, 'description' => 'Pieza base; se vende en paquetes de 16 y 4 piezas.'],

            // ════════════════════ COCO RALLADO Y DERIVADOS ════════════════════
            ['name' => 'Bolsa de Coco Rallado 1 Kg', 'code' => 'RAL-CR-1K', 'purchase' => 0, 'sale' => 120, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa de Coco Rallado 1/2 Kg', 'code' => 'RAL-CR-500', 'purchase' => 0, 'sale' => 65, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Caja de Coco Rallado 10 Kg', 'code' => 'RAL-CR-10K', 'purchase' => 0, 'sale' => 1000, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa de Coco Tostado 1 Kg', 'code' => 'RAL-CT-1K', 'purchase' => 0, 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa de Coco Tostado 1/2 Kg', 'code' => 'RAL-CT-500', 'purchase' => 0, 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Caja de Coco Tostado 10 Kg', 'code' => 'RAL-CT-10K', 'purchase' => 0, 'sale' => 1200, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa Coco Rallado Natural 100% 1 Kg', 'code' => 'RAL-CRN-1K', 'purchase' => 0, 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa Coco Rallado Natural 100% 1/2 Kg', 'code' => 'RAL-CRN-500', 'purchase' => 0, 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Caja Coco Rallado Natural 100% 10 Kg', 'code' => 'RAL-CRN-10K', 'purchase' => 0, 'sale' => 1200, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa Coco Tostado Natural 100% 1 Kg', 'code' => 'RAL-CTN-1K', 'purchase' => 0, 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa Coco Tostado Natural 100% 1/2 Kg', 'code' => 'RAL-CTN-500', 'purchase' => 0, 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa de Hojuela de Coco 1 Kg', 'code' => 'RAL-HJ-1K', 'purchase' => 0, 'sale' => 140, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            ['name' => 'Bolsa de Hojuela de Coco 1/2 Kg', 'code' => 'RAL-HJ-500', 'purchase' => 0, 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'processed', 'supplier' => 'Produccion CF'],
            // Bases derivadas: harina y azúcar se venden en bolsas (paquetes) de 1 y 1/2 Kg.
            ['name' => 'Harina de Coco (granel)', 'code' => 'RAL-HAR-KG', 'purchase' => 0, 'sale' => 0, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'raw_material', 'supplier' => 'Colima Tropical', 'for_sale' => false, 'description' => 'Granel base; se vende en bolsas de 1 Kg y 1/2 Kg.'],
            ['name' => 'Azúcar de Coco (granel)', 'code' => 'RAL-AZU-KG', 'purchase' => 0, 'sale' => 0, 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => 'raw_material', 'supplier' => 'Colima Tropical', 'for_sale' => false, 'description' => 'Granel base; se vende en bolsas de 1 Kg y 1/2 Kg.'],

            // ════════════════════ ENVASES Y PET (materia prima) ════════════════════
            ['name' => 'Botella 1 Lt Aqua', 'code' => 'PET-BOT-1LA', 'purchase' => 2.30, 'sale' => 10, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'TESTUS'],
            ['name' => 'Botella 1 Lt Transparente', 'code' => 'PET-BOT-1LT', 'purchase' => 2.23, 'sale' => 6, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'TESTUS'],
            ['name' => 'Botella 1/2 Lt Transparente', 'code' => 'PET-BOT-500T', 'purchase' => 1.60, 'sale' => 6, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'TESTUS'],
            ['name' => 'Galón 4 Lt', 'code' => 'PET-GAL-4L', 'purchase' => 6.20, 'sale' => 10, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'MAZAPLASTICOS'],
            ['name' => 'Tapa para Galones', 'code' => 'PET-TAP-GAL', 'purchase' => 0.27, 'sale' => 1, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'MAZAPLASTICOS'],
            ['name' => 'Tapa R-28 Corta', 'code' => 'PET-TAP-R28', 'purchase' => 0.27, 'sale' => 1, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'TESTUS'],
            ['name' => 'Tapa para Galones (TESTUS)', 'code' => 'PET-TAP-GAL-T', 'purchase' => 0, 'sale' => 0, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'TESTUS'],

            // ════════════════════ INGREDIENTES (compra, no a la venta) ════════════════════
            ['name' => 'Calahua de Coco (paq. 8 pzs)', 'code' => 'ING-CAL-8P', 'purchase' => 351, 'sale' => 0, 'category' => 'Ingredientes', 'unit' => 'Litro', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Leche Clavel (paq. 8 pzs)', 'code' => 'ING-LCL-8P', 'purchase' => 335, 'sale' => 0, 'category' => 'Ingredientes', 'unit' => 'Mililitro', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Sal', 'code' => 'ING-SAL-001', 'purchase' => 106.39, 'sale' => 0, 'category' => 'Ingredientes', 'unit' => 'Kilogramo', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false],

            // ════════════════════ LIMPIEZA (compra, no a la venta) ════════════════════
            ['name' => 'Jabón Rosita', 'code' => 'LIM-JAB-001', 'purchase' => 400, 'sale' => 0, 'category' => 'Limpieza', 'unit' => 'Kilogramo', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Cloralex', 'code' => 'LIM-CLO-001', 'purchase' => 400, 'sale' => 0, 'category' => 'Limpieza', 'unit' => 'Litro', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            // Base derivada: el contenedor Glad se compra por paquete.
            ['name' => 'Contenedor Glad', 'code' => 'LIM-CONT-GLAD', 'purchase' => 0, 'sale' => 0, 'category' => 'Limpieza', 'unit' => 'Pieza', 'type' => 'raw_material', 'supplier' => 'SAMS CLUB', 'for_sale' => false, 'description' => 'Pieza base; se compra por paquete.'],
        ];

        foreach ($products as $data) {
            $category = $categoriesCollection->where('name', $data['category'])->first();

            $supplierLabel = $data['supplier'] ?? null;
            $supplierName = $supplierLabel !== null ? ($supplierMap[$supplierLabel] ?? $supplierLabel) : null;
            $supplierId = $supplierName !== null ? ($suppliers[$supplierName] ?? null) : null;

            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'purchase_price' => $data['purchase'] > 0 ? $data['purchase'] : null,
                'sale_price' => $data['sale'] > 0 ? $data['sale'] : null,
                'description' => $data['description'] ?? null,
                'company_id' => $company->id,
                'category_id' => $category?->id,
                'supplier_id' => $supplierId,
                'unit_id' => $units[$data['unit']] ?? null,
                'product_type' => $data['type'] ?? 'commercial',
                'for_sale' => $data['for_sale'] ?? true,
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
