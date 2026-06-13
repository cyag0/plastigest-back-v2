<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PackageSeeder — Paquetes reales de Cocos Francisco.
 *
 * Fuente: "DATOS COCOS FCO - Hoja 1.csv". Se siembran como product_packages las
 * filas cuya UNIDAD DE MEDIDA es "Paquete/Paquetes" (un empaque que agrupa varias
 * unidades de un producto base). Las "Promo/Promoción" se ignoran.
 *
 * Cada paquete apunta a un producto base por su código semántico (ProductsSeeder).
 * `quantity_per_package` = cuántas unidades base contiene el empaque.
 *
 * Las cantidades de envases PET se derivaron del precio de compra del CSV
 * (precio paquete ÷ precio pieza), p. ej. $225.40 ÷ $2.30 = 98 botellas.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('name', 'Cocos Francisco')->first();

        if (!$company) {
            $this->command->error('No se encontró la compañía Cocos Francisco. Ejecuta CompaniesSeeder primero.');
            return;
        }

        $unitId = DB::table('units')->where('name', 'Paquete')->value('id');

        $products = Product::where('company_id', $company->id)->pluck('id', 'code');

        // [code_base, package_name, barcode(csv), qty, purchase, sale]
        $packages = [
            // ── Dulces de coco (piezas armadas en planta) ──
            ['DUR-DZ-1P',  'PAQ 8 DURAZNITOS',            '0020', 8,  0, 85],
            ['DUR-DR-1P',  'PAQ 8 DURAZNITOS DE ROMPOPE', '0021', 8,  0, 85],
            ['DUR-DL-1P',  'PAQ 8 DURAZNITOS DE LECHE',   '0022', 8,  0, 85],
            ['DUR-LM-1P',  'PAQ 8 PZ LIMONCITOS',         '0023', 8,  0, 95],
            ['PEL-1P',     'PAQ 6 PZ PELLIZCADA',         '0024', 6,  0, 100],
            ['CCD-NZ-1P',  'PAQ 9 PZ COCADA DE NUEZ',     '0025', 9,  0, 85],
            ['CCD-LM-1P',  'PAQ 9 PZ COCADA DE LIMON',    '0026', 9,  0, 85],
            ['CCD-GR-1P',  'PAQ 8 PZ COCADA GREÑUDA',     '0027', 8,  0, 100],
            ['CCD-MG-1P',  'PAQ 13 PZ COCADA MIXTA GDE',  '0028', 13, 0, 135],
            ['CCD-MC-1P',  'PAQ 13 PZ COCADA MIXTA CH',   '0029', 13, 0, 135],
            ['CCD-HOR-1P', 'PAQ 6 PZ COCADA HORNEADA',    '0030', 6,  0, 100],
            ['BAR-MX-1P',  'PQ 45 PZ BARRA MIXTA CHICA',  '0031', 45, 0, 175],

            // ── Postres / dulces tradicionales ──
            ['POS-POL-1P', 'PQ POLVORIN',          '0046', 1,  16, 30],
            ['DUL-GLL-1P', 'PAQ GALLETAS 16 PZA',  '0047', 16, 35, 100],
            ['DUL-GLL-1P', 'PAQ GALLETA 4 PZA',    '0048', 4,  20, 25],

            // ── Harina y azúcar de coco (bolsas) ──
            ['RAL-HAR-KG', 'PAQ HARINA DE COCO 1KG',     '0049', 1,   45,    175],
            ['RAL-HAR-KG', 'PAQ HARINA DE COCO 1/2 KG',  '0050', 0.5, 22.5,  80],
            ['RAL-AZU-KG', 'PAQ AZUCAR DE COCO 1KG',     '0051', 1,   163,   300],
            ['RAL-AZU-KG', 'PAQ AZUCAR DE COCO 1/2 KG',  '0052', 0.5, 81.5,  155],

            // ── Envases PET (cantidad = precio paquete ÷ precio pieza) ──
            ['PET-BOT-1LA',  'PAQ BOTELLAS 1LT AQUA',         '0084', 98,  225.40, 340],
            ['PET-BOT-1LT',  'PAQ BOTELLAS 1LT TRANSPARENTE', '0086', 98,  218.24, 329],
            ['PET-BOT-500T', 'PAQ BOTELLA 1/2 LT TRANSPARENTE','0088', 168, 268.80, 490],
            ['PET-GAL-4L',   'PQ GALONES 4 LT',               '0090', 40,  248,    330],
            ['PET-TAP-GAL',  'PAQ TAPA PARA GALONES',         '0093', 40,  10.80,  40],
            ['PET-TAP-R28',  'PAQ TAPA R-28 CORTA 98 PZ',     '0095', 98,  26.46,  60],

            // ── Limpieza / insumos ──
            ['LIM-CONT-GLAD', 'CONTENEDOR GLAD', '0102', 1, 377, 0],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($packages as $i => [$baseCode, $name, $barcode, $qty, $purchase, $sale]) {
            $productId = $products[$baseCode] ?? null;

            if (!$productId) {
                $this->command->warn("  ⚠ Producto base {$baseCode} no encontrado; se omite paquete \"{$name}\".");
                $skipped++;
                continue;
            }

            ProductPackage::create([
                'product_id' => $productId,
                'company_id' => $company->id,
                'unit_id' => $unitId,
                'package_name' => $name,
                'barcode' => $barcode,
                'quantity_per_package' => $qty,
                'purchase_price' => $purchase > 0 ? $purchase : null,
                'sale_price' => $sale > 0 ? $sale : null,
                'content' => ['csv_code' => $barcode],
                'is_active' => true,
                'is_default' => false,
                'sort_order' => $i,
            ]);

            $created++;
        }

        $this->command->info("✅ Creados {$created} paquetes de Cocos Francisco" . ($skipped ? " ({$skipped} omitidos)" : ''));
    }
}
