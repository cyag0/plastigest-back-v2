<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Category;
use App\Models\InventoryType;
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
 *  - Códigos semánticos (no los 0001–0102 del CSV).
 *  - Proveedor "Produccion CF" → null (se produce en planta).
 *
 * Cómo se produce (revisado el 16-oct-2026 con el dueño):
 *  - Lo que se vende envasado se cuenta en PIEZAS: una "Agua de Coco 1/2
 *    Litro" es una botella, no medio litro (antes estaba en litros y el
 *    inventario decía "590 L" cuando eran 590 botellas). Lo que lleva dentro va
 *    en su receta de envasado: de qué granel sale, cuánto lleva y qué material
 *    consume (botella, tapa).
 *  - El granel es lo que sale de un proceso y todavía no se envasa: el agua y
 *    la pulpa al abrir cocos, la horchata, la tuba, el coco rallado y tostado.
 *    Se mide en litros o kilos y no se vende suelto.
 *  - Los vasos y las bolsas de rallado no llevan material: los vasos y las
 *    bolsas se compran como gasto de la sucursal, no se inventarían.
 *  - Lo que se compra hecho y se revende (aceites, polvorín, galletas, harina y
 *    azúcar de coco) es mercancía de reventa.
 *
 * Bases derivadas: algunos paquetes del CSV no tienen una pieza individual
 * (Cocada horneada, Polvorín, Galleta, Harina, Azúcar, Contenedor Glad). Como un
 * product_package requiere un producto base, esas piezas base se crean aquí con
 * `for_sale = false` (solo existen para anclar su paquete).
 *
 * Es idempotente: crea o actualiza cada producto por (empresa, código) y
 * conserva su id, su existencia y su historial.
 */
class ProductsSeeder extends Seeder
{
    /** Envasado de una botella de 1 L, de ½ L y de un galón de 4 L. */
    private const BOTTLE_1L = ['PET-BOT-1LT' => 1, 'PET-TAP-R28' => 1];
    private const BOTTLE_500 = ['PET-BOT-500T' => 1, 'PET-TAP-R28' => 1];
    private const GALLON = ['PET-GAL-4L' => 1, 'PET-TAP-GAL' => 1];

    public function run(): void
    {
        if (!InitialCatalogSeeder::allows($this)) {
            return;
        }

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

        $created = 0;
        $updated = 0;
        $ids = [];

        foreach ($this->catalog() as $data) {
            $category = $categoriesCollection->where('name', $data['category'])->first();

            $supplierLabel = $data['supplier'] ?? null;
            $supplierName = $supplierLabel !== null ? ($supplierMap[$supplierLabel] ?? $supplierLabel) : null;
            $supplierId = $supplierName !== null ? ($suppliers[$supplierName] ?? null) : null;

            $product = Product::updateOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                [
                    'name' => $data['name'],
                    'purchase_price' => ($data['purchase'] ?? 0) > 0 ? $data['purchase'] : null,
                    'sale_price' => ($data['sale'] ?? 0) > 0 ? $data['sale'] : null,
                    'description' => $data['description'] ?? null,
                    'category_id' => $category?->id,
                    'supplier_id' => $supplierId,
                    'unit_id' => $units[$data['unit']] ?? null,
                    'inventory_type_id' => InventoryType::idForCode($data['type'], $company->id),
                    'for_sale' => $data['for_sale'] ?? true,
                ]
            );

            $product->wasRecentlyCreated ? $created++ : $updated++;
            $ids[$data['code']] = $product->id;

            // Solo las sucursales que le falten: las existentes conservan su stock.
            // (product_location no tiene llave única por producto+sucursal, así
            // que se filtra aquí en vez de usar insertOrIgnore.)
            $existingLocationIds = DB::table('product_location')
                ->where('product_id', $product->id)
                ->pluck('location_id')
                ->all();

            $productLocationRecords = $locations
                ->reject(fn($location) => in_array($location->id, $existingLocationIds))
                ->map(fn($location) => [
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
                ])->values()->toArray();

            if (!empty($productLocationRecords)) {
                DB::table('product_location')->insert($productLocationRecords);
            }
        }

        // Recetas de envasado: con todos los productos ya creados, porque
        // apuntan al granel y al material de empaque.
        $recipes = 0;
        foreach ($this->catalog() as $data) {
            if (empty($data['bulk'])) {
                continue;
            }

            $productId = $ids[$data['code']];
            Product::whereKey($productId)->update([
                'requires_packaging' => true,
                'packaging_source_product_id' => $ids[$data['bulk']],
                'packaging_source_quantity' => $data['content'],
                'packaging_source_unit_id' => $units[$data['content_unit']] ?? null,
            ]);

            DB::table('product_packaging_materials')->where('product_id', $productId)->delete();
            $order = 0;
            foreach ($data['materials'] ?? [] as $materialCode => $quantity) {
                DB::table('product_packaging_materials')->insert([
                    'product_id' => $productId,
                    'material_product_id' => $ids[$materialCode],
                    'unit_id' => $units['Pieza'],
                    'quantity' => $quantity,
                    'sort_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $recipes++;
        }

        $this->command->info("Productos de Cocos Francisco: {$created} creados, {$updated} actualizados, {$recipes} con receta de envasado");
        $this->command->info('Presentes en ' . $locations->count() . ' ubicaciones (sin tocar el stock existente)');
    }

    /**
     * Catálogo. Campos:
     *   name, code, purchase, sale, category, unit, type (código del tipo de
     *   inventario), supplier, for_sale, description
     *   y, en las presentaciones: bulk (código del granel), content (cuánto
     *   granel lleva una unidad), content_unit y materials (código => cantidad
     *   por unidad).
     */
    private function catalog(): array
    {
        $raw = InventoryType::RAW_MATERIAL;
        $bulk = InventoryType::SEMI_FINISHED;
        $finished = InventoryType::FINISHED_PRODUCT;
        $packaging = InventoryType::PACKAGING_MATERIAL;
        $resale = InventoryType::MERCHANDISE;
        $supply = InventoryType::SUPPLY;

        return [
            // ════════════════════ GRANEL (sale de un proceso, se envasa) ════════════════════
            ['name' => 'Agua de coco (granel)', 'code' => 'GRA-AGUA', 'category' => 'Granel', 'unit' => 'Litro', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false, 'description' => 'Sale al abrir los cocos. Se envasa en botellas, vasos y galones.'],
            ['name' => 'Pulpa de coco (granel)', 'code' => 'GRA-PULPA', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false, 'description' => 'Sale al abrir los cocos. Se usa en horchata, dulces y rallado.'],
            ['name' => 'Horchata de coco (granel)', 'code' => 'GRA-HORCH', 'category' => 'Granel', 'unit' => 'Litro', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Horchata sin azúcar (granel)', 'code' => 'GRA-HORCH-SA', 'category' => 'Granel', 'unit' => 'Litro', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Tuba (granel)', 'code' => 'GRA-TUBA', 'category' => 'Granel', 'unit' => 'Litro', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false, 'description' => 'Savia de la palma colada y reposada, lista para envasar.'],
            ['name' => 'Coco rallado (granel)', 'code' => 'GRA-RAL', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Coco rallado natural 100% (granel)', 'code' => 'GRA-RAL-NAT', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Coco tostado (granel)', 'code' => 'GRA-TOS', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Coco tostado natural 100% (granel)', 'code' => 'GRA-TOS-NAT', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],
            ['name' => 'Hojuela de coco (granel)', 'code' => 'GRA-HOJ', 'category' => 'Granel', 'unit' => 'Kilogramo', 'type' => $bulk, 'supplier' => 'Produccion CF', 'for_sale' => false],

            // ════════════════════ BEBIDAS (piezas que se envasan del granel) ════════════════════
            ['name' => 'Agua de Coco 1 Litro', 'code' => 'BEB-AC-1L', 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-AGUA', 'content' => 1, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_1L],
            ['name' => 'Agua de Coco 1/2 Litro', 'code' => 'BEB-AC-500', 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-AGUA', 'content' => 0.5, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_500],
            ['name' => 'Galón de Agua de Coco 4 Lt', 'code' => 'BEB-AC-GAL', 'sale' => 230, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-AGUA', 'content' => 4, 'content_unit' => 'Litro', 'materials' => self::GALLON],
            ['name' => 'Vaso de Agua de Coco 1 Lt', 'code' => 'BEB-VAC-1L', 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-AGUA', 'content' => 1, 'content_unit' => 'Litro'],
            ['name' => 'Vaso de Agua de Coco 1/2 Lt', 'code' => 'BEB-VAC-500', 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-AGUA', 'content' => 0.5, 'content_unit' => 'Litro'],
            ['name' => 'Tuba 1 Lt', 'code' => 'BEB-TB-1L', 'sale' => 40, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TUBA', 'content' => 1, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_1L],
            ['name' => 'Botella Tuba 1/2 Lt', 'code' => 'BEB-TB-500', 'sale' => 25, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TUBA', 'content' => 0.5, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_500],
            ['name' => 'Galón de Tuba 4 Lt', 'code' => 'BEB-TB-GAL', 'sale' => 130, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TUBA', 'content' => 4, 'content_unit' => 'Litro', 'materials' => self::GALLON],
            ['name' => 'Horchata de Coco 1 Lt', 'code' => 'BEB-HC-1L', 'sale' => 40, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH', 'content' => 1, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_1L],
            ['name' => 'Horchata de Coco 1/2 Lt', 'code' => 'BEB-HC-500', 'sale' => 25, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH', 'content' => 0.5, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_500],
            ['name' => 'Galón de Horchata de Coco 4 Lt', 'code' => 'BEB-HC-GAL', 'sale' => 130, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH', 'content' => 4, 'content_unit' => 'Litro', 'materials' => self::GALLON],
            ['name' => 'Horchata 1 Lt Sin Azúcar', 'code' => 'BEB-HSA-1L', 'sale' => 65, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH-SA', 'content' => 1, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_1L],
            ['name' => 'Horchata 1/2 Lt Sin Azúcar', 'code' => 'BEB-HSA-500', 'sale' => 37, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH-SA', 'content' => 0.5, 'content_unit' => 'Litro', 'materials' => self::BOTTLE_500],
            ['name' => 'Galón Horchata 4 Lt Sin Azúcar', 'code' => 'BEB-HSA-GAL', 'sale' => 230, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HORCH-SA', 'content' => 4, 'content_unit' => 'Litro', 'materials' => self::GALLON],
            // Sale del proceso "Coco para tomar": el coco entero, preparado.
            ['name' => 'Coco para tomar en sucursal', 'code' => 'BEB-CT-001', 'sale' => 85, 'category' => 'Bebidas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],

            // ════════════════════ DERIVADOS DE COCO ════════════════════
            // Contenido supuesto (250 g): ajústalo en el producto si la bolsa lleva otra cantidad.
            ['name' => 'Bolsa con Pulpa de Coco', 'code' => 'DER-PULPA-BOL', 'sale' => 20, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-PULPA', 'content' => 0.25, 'content_unit' => 'Kilogramo'],
            ['name' => 'Aceite de Coco Natural 180 ml', 'code' => 'DER-ACN-180', 'purchase' => 45, 'sale' => 100, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Natural 1 Lt', 'code' => 'DER-ACN-1L', 'purchase' => 180, 'sale' => 550, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Cocina 1/2 Lt', 'code' => 'DER-ACC-500', 'purchase' => 50, 'sale' => 150, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Colima Tropical'],
            ['name' => 'Aceite de Coco Cocina 1 Lt', 'code' => 'DER-ACC-1L', 'purchase' => 200, 'sale' => 600, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Colima Tropical'],
            // Se vende la botella y también se usa en los dulces de rompope.
            ['name' => 'Rompope de Coco', 'code' => 'DER-RP-001', 'purchase' => 70, 'sale' => 180, 'category' => 'Derivados de coco', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'Osmar Talpa'],

            // ════════════════════ COCO NATURAL (materia prima) ════════════════════
            ['name' => 'Coco Entero', 'code' => 'COC-ENT-001', 'purchase' => 15, 'sale' => 38, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'Huerteros'],
            ['name' => 'Pieza de Pulpa de Coco', 'code' => 'COC-PUL-1P', 'sale' => 23, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'Huerteros'],
            ['name' => 'Cubeta de Coco', 'code' => 'COC-CUB-001', 'sale' => 400, 'category' => 'Coco natural', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'Huerteros'],

            // ════════════════════ COCADAS ════════════════════
            ['name' => 'Cocada de Nuez 1 pza', 'code' => 'CCD-NZ-1P', 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada de Limón 1 pza', 'code' => 'CCD-LM-1P', 'sale' => 18, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Greñuda 1 pza', 'code' => 'CCD-GR-1P', 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Mixta Grande 1 pza', 'code' => 'CCD-MG-1P', 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada Mixta Chica 1 pza', 'code' => 'CCD-MC-1P', 'sale' => 18, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cocada de Bola 1 pza', 'code' => 'CCD-BL-1P', 'sale' => 25, 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            // Base derivada: la "Cocada Horneada" solo se vende en paquete de 6 (no hay pieza con precio en el CSV).
            ['name' => 'Cocada Horneada 1 pza', 'code' => 'CCD-HOR-1P', 'category' => 'Cocadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'for_sale' => false, 'description' => 'Pieza base; se vende en paquete de 6.'],

            // ════════════════════ BARRAS ════════════════════
            ['name' => 'Barra Mixta Chica 1 pza', 'code' => 'BAR-MX-1P', 'sale' => 10, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Nuez Chica', 'code' => 'BAR-NZ-CH', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Coco', 'code' => 'BAR-CC-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Coco con Fresa', 'code' => 'BAR-CF-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Rompope', 'code' => 'BAR-RP-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Banderita Chica', 'code' => 'BAR-BN-CH', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra Coco Nuez', 'code' => 'BAR-CN-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Leche de Coco', 'code' => 'BAR-LC-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Barra de Leche de Coco con Nuez', 'code' => 'BAR-LCN-001', 'sale' => 25, 'category' => 'Barras', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],

            // ════════════════════ DURAZNITOS Y LIMONCITOS ════════════════════
            ['name' => 'Duraznitos 1 pza', 'code' => 'DUR-DZ-1P', 'sale' => 20, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Limoncitos 1 pza', 'code' => 'DUR-LM-1P', 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Duraznito de Rompope 1 pza', 'code' => 'DUR-DR-1P', 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Duraznito de Leche 1 pza', 'code' => 'DUR-DL-1P', 'sale' => 25, 'category' => 'Duraznitos y limoncitos', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],

            // ════════════════════ PELLIZCADAS ════════════════════
            ['name' => 'Pellizcada 1 pza', 'code' => 'PEL-1P', 'sale' => 20, 'category' => 'Pellizcadas y pelizcadas', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],

            // ════════════════════ POSTRES Y MÁS ════════════════════
            ['name' => 'Dulce de Leche', 'code' => 'POS-DL-001', 'sale' => 15, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cuala', 'code' => 'POS-CU-001', 'sale' => 35, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            ['name' => 'Cuala Grande', 'code' => 'POS-CU-GDE', 'sale' => 85, 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF'],
            // Base derivada: el polvorín se compra hecho y se vende como paquete (PQ POLVORIN).
            ['name' => 'Polvorín 1 pza', 'code' => 'POS-POL-1P', 'category' => 'Postres y más', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Sofia Gomez', 'for_sale' => false, 'description' => 'Pieza base; se vende empaquetado.'],

            // ════════════════════ DULCES TRADICIONALES ════════════════════
            // Base derivada: la galleta se compra hecha y se vende en paquetes de 16 y de 4 piezas.
            ['name' => 'Galleta de Coco 1 pza', 'code' => 'DUL-GLL-1P', 'category' => 'Dulces tradicionales de coco', 'unit' => 'Pieza', 'type' => $resale, 'supplier' => 'Sofia Gomez', 'for_sale' => false, 'description' => 'Pieza base; se vende en paquetes de 16 y 4 piezas.'],

            // ════════════════════ COCO RALLADO Y DERIVADOS ════════════════════
            // Bolsas y cajas que se llenan del granel (la bolsa no se inventaría).
            ['name' => 'Bolsa de Coco Rallado 1 Kg', 'code' => 'RAL-CR-1K', 'sale' => 120, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL', 'content' => 1, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa de Coco Rallado 1/2 Kg', 'code' => 'RAL-CR-500', 'sale' => 65, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL', 'content' => 0.5, 'content_unit' => 'Kilogramo'],
            ['name' => 'Caja de Coco Rallado 10 Kg', 'code' => 'RAL-CR-10K', 'sale' => 1000, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL', 'content' => 10, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa de Coco Tostado 1 Kg', 'code' => 'RAL-CT-1K', 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TOS', 'content' => 1, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa de Coco Tostado 1/2 Kg', 'code' => 'RAL-CT-500', 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TOS', 'content' => 0.5, 'content_unit' => 'Kilogramo'],
            ['name' => 'Caja de Coco Tostado 10 Kg', 'code' => 'RAL-CT-10K', 'sale' => 1200, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TOS', 'content' => 10, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa Coco Rallado Natural 100% 1 Kg', 'code' => 'RAL-CRN-1K', 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL-NAT', 'content' => 1, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa Coco Rallado Natural 100% 1/2 Kg', 'code' => 'RAL-CRN-500', 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL-NAT', 'content' => 0.5, 'content_unit' => 'Kilogramo'],
            ['name' => 'Caja Coco Rallado Natural 100% 10 Kg', 'code' => 'RAL-CRN-10K', 'sale' => 1200, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-RAL-NAT', 'content' => 10, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa Coco Tostado Natural 100% 1 Kg', 'code' => 'RAL-CTN-1K', 'sale' => 145, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TOS-NAT', 'content' => 1, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa Coco Tostado Natural 100% 1/2 Kg', 'code' => 'RAL-CTN-500', 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-TOS-NAT', 'content' => 0.5, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa de Hojuela de Coco 1 Kg', 'code' => 'RAL-HJ-1K', 'sale' => 140, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HOJ', 'content' => 1, 'content_unit' => 'Kilogramo'],
            ['name' => 'Bolsa de Hojuela de Coco 1/2 Kg', 'code' => 'RAL-HJ-500', 'sale' => 85, 'category' => 'Coco rallado y derivados', 'unit' => 'Pieza', 'type' => $finished, 'supplier' => 'Produccion CF', 'bulk' => 'GRA-HOJ', 'content' => 0.5, 'content_unit' => 'Kilogramo'],
            // Bases derivadas: harina y azúcar de coco se compran a granel y se venden en bolsas (paquetes) de 1 y 1/2 Kg.
            ['name' => 'Harina de Coco (granel)', 'code' => 'RAL-HAR-KG', 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => $resale, 'supplier' => 'Colima Tropical', 'for_sale' => false, 'description' => 'Se compra a granel; se vende en bolsas de 1 Kg y 1/2 Kg.'],
            ['name' => 'Azúcar de Coco (granel)', 'code' => 'RAL-AZU-KG', 'category' => 'Coco rallado y derivados', 'unit' => 'Kilogramo', 'type' => $resale, 'supplier' => 'Colima Tropical', 'for_sale' => false, 'description' => 'Se compra a granel; se vende en bolsas de 1 Kg y 1/2 Kg.'],

            // ════════════════════ ENVASES Y PET (material de empaque) ════════════════════
            ['name' => 'Botella 1 Lt Aqua', 'code' => 'PET-BOT-1LA', 'purchase' => 2.30, 'sale' => 10, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'TESTUS'],
            ['name' => 'Botella 1 Lt Transparente', 'code' => 'PET-BOT-1LT', 'purchase' => 2.23, 'sale' => 6, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'TESTUS'],
            ['name' => 'Botella 1/2 Lt Transparente', 'code' => 'PET-BOT-500T', 'purchase' => 1.60, 'sale' => 6, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'TESTUS'],
            ['name' => 'Galón 4 Lt', 'code' => 'PET-GAL-4L', 'purchase' => 6.20, 'sale' => 10, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'MAZAPLASTICOS'],
            ['name' => 'Tapa para Galones', 'code' => 'PET-TAP-GAL', 'purchase' => 0.27, 'sale' => 1, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'MAZAPLASTICOS'],
            ['name' => 'Tapa R-28 Corta', 'code' => 'PET-TAP-R28', 'purchase' => 0.27, 'sale' => 1, 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'TESTUS'],
            ['name' => 'Tapa para Galones (TESTUS)', 'code' => 'PET-TAP-GAL-T', 'category' => 'Envases y PET', 'unit' => 'Pieza', 'type' => $packaging, 'supplier' => 'TESTUS'],

            // ════════════════════ INGREDIENTES (compra, no a la venta) ════════════════════
            ['name' => 'Savia de palma (tuba natural)', 'code' => 'ING-SAVIA-L', 'category' => 'Ingredientes', 'unit' => 'Litro', 'type' => $raw, 'supplier' => 'Huerteros', 'for_sale' => false, 'description' => 'Savia de la palma de coco; se cuela y se reposa para hacer la tuba.'],
            ['name' => 'Azúcar', 'code' => 'ING-AZU-KG', 'category' => 'Ingredientes', 'unit' => 'Kilogramo', 'type' => $raw, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Nuez', 'code' => 'ING-NUEZ-KG', 'category' => 'Ingredientes', 'unit' => 'Kilogramo', 'type' => $raw, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            // Se compran por paquete de 8 latas (PackageSeeder); aquí, el precio por lata.
            ['name' => 'Crema de coco Calahua (lata)', 'code' => 'ING-CAL-LATA', 'purchase' => 43.88, 'category' => 'Ingredientes', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Leche evaporada Clavel (lata)', 'code' => 'ING-LCL-LATA', 'purchase' => 41.88, 'category' => 'Ingredientes', 'unit' => 'Pieza', 'type' => $raw, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Sal', 'code' => 'ING-SAL-001', 'purchase' => 106.39, 'category' => 'Ingredientes', 'unit' => 'Kilogramo', 'type' => $raw, 'supplier' => 'SAMS CLUB', 'for_sale' => false],

            // ════════════════════ LIMPIEZA (compra, no a la venta) ════════════════════
            ['name' => 'Jabón Rosita', 'code' => 'LIM-JAB-001', 'purchase' => 400, 'category' => 'Limpieza', 'unit' => 'Kilogramo', 'type' => $supply, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            ['name' => 'Cloralex', 'code' => 'LIM-CLO-001', 'purchase' => 400, 'category' => 'Limpieza', 'unit' => 'Litro', 'type' => $supply, 'supplier' => 'SAMS CLUB', 'for_sale' => false],
            // Base derivada: el contenedor Glad se compra por paquete.
            ['name' => 'Contenedor Glad', 'code' => 'LIM-CONT-GLAD', 'category' => 'Limpieza', 'unit' => 'Pieza', 'type' => $supply, 'supplier' => 'SAMS CLUB', 'for_sale' => false, 'description' => 'Pieza base; se compra por paquete.'],
        ];
    }
}
