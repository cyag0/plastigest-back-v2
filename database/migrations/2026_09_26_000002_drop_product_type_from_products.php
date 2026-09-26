<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retira products.product_type (raw_material | processed | commercial).
 *
 * Desde 2026_08_03 el tipo de un producto es inventory_type_id y el enum solo
 * se mantenía sincronizado. El modelo Product lo sigue exponiendo como campo
 * calculado (getProductTypeAttribute) para versiones viejas de la app, y el
 * controlador traduce el product_type que manden a un tipo de inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Red de seguridad: ningún producto puede quedarse sin tipo al perder el enum.
        $fallback = DB::table('inventory_types')->whereNull('company_id')->where('code', 'merchandise')->value('id');
        foreach (['raw_material' => 'raw_material', 'processed' => 'finished_product', 'commercial' => 'merchandise'] as $legacy => $code) {
            $typeId = DB::table('inventory_types')->whereNull('company_id')->where('code', $code)->value('id');
            if ($typeId) {
                DB::table('products')->whereNull('inventory_type_id')->where('product_type', $legacy)
                    ->update(['inventory_type_id' => $typeId]);
            }
        }
        if ($fallback) {
            DB::table('products')->whereNull('inventory_type_id')->update(['inventory_type_id' => $fallback]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['raw_material', 'processed', 'commercial'])->default('commercial')->after('supplier_id');
        });

        // Reconstruye el enum desde el tipo de inventario.
        $map = [
            'raw_material' => 'raw_material', 'packaging_material' => 'raw_material',
            'semi_finished' => 'processed', 'finished_product' => 'processed',
            'merchandise' => 'commercial', 'supply' => 'commercial',
        ];
        foreach ($map as $code => $legacy) {
            DB::table('products')
                ->join('inventory_types', 'inventory_types.id', '=', 'products.inventory_type_id')
                ->where('inventory_types.code', $code)
                ->update(['products.product_type' => $legacy]);
        }
    }
};
