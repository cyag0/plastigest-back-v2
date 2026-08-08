<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versión aditiva de `2026_08_03_000002_replace_product_type_with_inventory_type_id`
 * de la rama feat/inventory-stock-batch-engine.
 *
 * Aquí sólo se AGREGA `products.inventory_type_id` y se copian los valores del
 * enum anterior. `products.product_type` se conserva: el código de esta rama
 * todavía lo lee en ProductController, Product, ProductResource, FormulaResource,
 * PurchaseResource y ProductionOrderService, así que borrarlo rompería la app.
 *
 * La columna existe para que `InventoryType::products()` (y por lo tanto el
 * `withCount` de `?with_counts=1`) tenga a qué apuntar. Las dos columnas quedan
 * en paralelo, sin sincronizarse: la fuente de verdad sigue siendo `product_type`
 * hasta que se integre la rama de inventario.
 *
 * Equivalencias del enum anterior:
 *   raw_material → raw_material
 *   processed    → finished_product
 *   commercial   → merchandise
 */
return new class extends Migration
{
    /** enum anterior → código en inventory_types */
    private const LEGACY_MAP = [
        'raw_material' => 'raw_material',
        'processed' => 'finished_product',
        'commercial' => 'merchandise',
    ];

    public function up(): void
    {
        // Idempotente: si se integra la rama, su migración 000002 ya pudo crearla.
        if (Schema::hasColumn('products', 'inventory_type_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('inventory_type_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('inventory_types')
                ->restrictOnDelete();
        });

        foreach (self::LEGACY_MAP as $legacyType => $code) {
            $typeId = $this->systemTypeId($code);

            if ($typeId) {
                DB::table('products')
                    ->where('product_type', $legacyType)
                    ->update(['inventory_type_id' => $typeId]);
            }
        }

        // Red de seguridad: cualquier producto sin tipo cae en el default del enum anterior.
        $fallbackId = $this->systemTypeId('merchandise');

        if ($fallbackId) {
            DB::table('products')
                ->whereNull('inventory_type_id')
                ->update(['inventory_type_id' => $fallbackId]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'inventory_type_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['inventory_type_id']);
            $table->dropColumn('inventory_type_id');
        });
    }

    private function systemTypeId(string $code): ?int
    {
        return DB::table('inventory_types')
            ->whereNull('company_id')
            ->where('code', $code)
            ->value('id');
    }
};
