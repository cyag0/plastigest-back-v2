<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * Registra los paquetes (N unidades del MISMO producto) reales del
     * catálogo de Cocos Francisco. Cada paquete se identifica por el `code`
     * del producto y se asocia con su quantity_per_package correspondiente
     * en la tabla `product_packages`.
     *
     * El seeder es idempotente: si se vuelve a ejecutar, no duplica. Usa
     * el `barcode` (que es único por diseño) como llave para hacer
     * updateOrCreate, de modo que la primera ejecución crea y las
     * siguientes actualizan los precios y la unidad de empaque.
     *
     * Nota: las quantity_per_package se calcularon a partir de los precios
     * de venta del archivo "Productos para DiDi.xlsx" (caja / pieza).
     * Si el dato real es distinto, ajustar aquí.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $companies = DB::table('companies')->get();

        // Mapa de unidades de empaque por abreviatura
        $unitsByAbbreviation = DB::table('units')
            ->whereIn('abbreviation', ['cj', 'bl', 'pq', 'pr'])
            ->get()
            ->keyBy('abbreviation');

        foreach ($companies as $company) {
            // Catálogo de paquetes por código de producto.
            // Estructura:
            //   product_code      => código del producto base en la tabla products
            //   package_name      => nombre del empaque
            //   barcode_suffix    => sufijo del código de barras
            //   quantity          => unidades base que contiene el empaque
            //   unit_abbreviation => abreviatura de la unidad de empaque (cj=Caja, bl=Bulto, pq=Paquete, pr=Promo)
            //   is_default        => si es el empaque por defecto del producto
            //   sort_order        => orden de aparición
            $packages = [
                // ============ BEBIDAS (galones) ============
                // "Paquete/Galones" — paquete de 2 galones (4 L c/u aprox.)
                'POS-PG-001' => [
                    ['package_name' => 'Paquete 2 galones', 'barcode_suffix' => 'PG2',  'quantity' => 2, 'unit_abbreviation' => 'pq', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ POSTRES Y MÁS ============
                // "Paquete/Botella 1 LTR" — paquete de 12 botellas vacías 1 L
                'POS-PB-1L' => [
                    ['package_name' => 'Paquete 12 botellas 1 L', 'barcode_suffix' => 'PB12-1L',  'quantity' => 12, 'unit_abbreviation' => 'pq', 'is_default' => true,  'sort_order' => 1],
                ],
                // "Paquete/Botella 500 ML" — paquete de 24 botellas vacías 500 ml
                'POS-PB-500' => [
                    ['package_name' => 'Paquete 24 botellas 500 ml', 'barcode_suffix' => 'PB24-500', 'quantity' => 24, 'unit_abbreviation' => 'pq', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ COCO NATURAL ============
                // "Coco mayoreo (100 pzs)" — bulto de 100 cocos
                'NAT-CM-100' => [
                    ['package_name' => 'Bulto 100 cocos', 'barcode_suffix' => 'BC100', 'quantity' => 100, 'unit_abbreviation' => 'bl', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ COCO RALLADO Y DERIVADOS ============
                // "Caja de coco rallado 10 KG" — caja con 10 kg de coco rallado
                'RAL-CCR-10K' => [
                    ['package_name' => 'Caja 10 kg coco rallado', 'barcode_suffix' => 'CC10', 'quantity' => 10, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ COCADAS (cajas) ============
                // Caja de cocadas de nuez — ~4 piezas ($100 / $25)
                'COC-CN-CJ' => [
                    ['package_name' => 'Caja 4 cocadas de nuez', 'barcode_suffix' => 'CN4',  'quantity' => 4, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de cocadas de limón — ~5 piezas ($85 / $18)
                'COC-CL-CJ' => [
                    ['package_name' => 'Caja 5 cocadas de limón', 'barcode_suffix' => 'CL5',  'quantity' => 5, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de cocadas greñudas — ~4 piezas ($100 / $25)
                'COC-CG-CJ' => [
                    ['package_name' => 'Caja 4 cocadas greñudas', 'barcode_suffix' => 'CG4',  'quantity' => 4, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de cocadas mixtas grandes — ~5 piezas ($120 / $25)
                'COC-CMG-CJ' => [
                    ['package_name' => 'Caja 5 cocadas mixtas grandes', 'barcode_suffix' => 'CMG5', 'quantity' => 5, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de cocadas mixtas chicas — ~7 piezas ($130 / $18)
                'COC-CMC-CJ' => [
                    ['package_name' => 'Caja 7 cocadas mixtas chicas', 'barcode_suffix' => 'CMC7', 'quantity' => 7, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de cocadas horneadas — 5 piezas (estimado)
                'COC-CH-CJ' => [
                    ['package_name' => 'Caja 5 cocadas horneadas', 'barcode_suffix' => 'CH5',  'quantity' => 5, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ BARRAS (cajas) ============
                // Caja de barras mixtas — ~18 piezas ($185 / $10)
                'BAR-BM-CJ' => [
                    ['package_name' => 'Caja 18 barras mixtas', 'barcode_suffix' => 'BM18', 'quantity' => 18, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ DURAZNITOS Y LIMONCITOS (cajas) ============
                // Caja de duraznitos — ~4 piezas ($75 / $18)
                'DUR-CJ' => [
                    ['package_name' => 'Caja 4 duraznitos', 'barcode_suffix' => 'DUR4', 'quantity' => 4, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Caja de limoncitos — ~4 piezas ($95 / $25)
                'LIM-CJ' => [
                    ['package_name' => 'Caja 4 limoncitos', 'barcode_suffix' => 'LIM4', 'quantity' => 4, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ PELLIZCADAS (cajas) ============
                // Caja de pellizcadas — ~7 piezas ($120 / $18)
                'PEL-CJ' => [
                    ['package_name' => 'Caja 7 pellizcadas', 'barcode_suffix' => 'PEL7', 'quantity' => 7, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ DULCES TRADICIONALES (cajas y paquetes) ============
                // Caja de galletas — 3 paquetes de 4 = 12 piezas ($75 / $25 = 3 paquetes)
                'DUL-GLL-CJ' => [
                    ['package_name' => 'Caja 12 galletas (3 paq. de 4)', 'barcode_suffix' => 'GLL12', 'quantity' => 12, 'unit_abbreviation' => 'cj', 'is_default' => true,  'sort_order' => 1],
                ],
                // Paquete de 4 galletas — 4 piezas
                'DUL-GLL-4P' => [
                    ['package_name' => 'Paquete 4 galletas', 'barcode_suffix' => 'GLL4', 'quantity' => 4, 'unit_abbreviation' => 'pq', 'is_default' => true,  'sort_order' => 1],
                ],

                // ============ PROMOCIONES (promos del mismo producto) ============
                // "Promo 2 rompopes" — 2 botellas
                'PROM-2RP' => [
                    ['package_name' => 'Promo 2 botellas de rompope', 'barcode_suffix' => 'PROM2RP', 'quantity' => 2, 'unit_abbreviation' => 'pr', 'is_default' => true,  'sort_order' => 1],
                ],
                // "Promo 2 bolsas de coco" — 2 bolsas
                'PROM-2BC' => [
                    ['package_name' => 'Promo 2 bolsas de coco partido', 'barcode_suffix' => 'PROM2BC', 'quantity' => 2, 'unit_abbreviation' => 'pr', 'is_default' => true,  'sort_order' => 1],
                ],
                // "Promo horchata 3 x" — 3 botellas
                'PROM-HX3' => [
                    ['package_name' => 'Promo 3 botellas de horchata', 'barcode_suffix' => 'PROMHX3', 'quantity' => 3, 'unit_abbreviation' => 'pr', 'is_default' => true,  'sort_order' => 1],
                ],
            ];

            foreach ($packages as $productCode => $packageVariants) {
                $product = DB::table('products')
                    ->where('company_id', $company->id)
                    ->where('code', $productCode)
                    ->first();

                if (!$product) {
                    $this->command->warn("⚠️  Producto con código '{$productCode}' no encontrado para la compañía '{$company->name}'. Se omite su paquete.");
                    continue;
                }

                foreach ($packageVariants as $pkg) {
                    // Resolver la unidad de empaque (Caja, Bulto, Paquete, Promo)
                    $unitId = null;
                    if (!empty($pkg['unit_abbreviation']) && isset($unitsByAbbreviation[$pkg['unit_abbreviation']])) {
                        $unitId = $unitsByAbbreviation[$pkg['unit_abbreviation']]->id;
                    }

                    $barcode = $product->code . '-' . $pkg['barcode_suffix'];

                    // updateOrCreate por barcode para mantener idempotencia
                    // (si ya existe, actualiza precios/unidad; si no, crea).
                    DB::table('product_packages')->updateOrInsert(
                        ['barcode' => $barcode],
                        [
                            'product_id' => $product->id,
                            'unit_id' => $unitId,
                            'package_name' => $pkg['package_name'],
                            'quantity_per_package' => $pkg['quantity'],
                            // Si el producto base tiene precios, calcularlos multiplicando
                            // (puede ajustarse luego con descuentos por volumen)
                            'purchase_price' => $product->purchase_price ? round($product->purchase_price * $pkg['quantity'], 2) : null,
                            'sale_price' => $product->sale_price ? round($product->sale_price * $pkg['quantity'], 2) : null,
                            'is_active' => true,
                            'is_default' => $pkg['is_default'],
                            'sort_order' => $pkg['sort_order'],
                            'company_id' => $company->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }

        $this->command->info('✅ Paquetes registrados con su quantity_per_package y unidad de empaque');
    }
}
