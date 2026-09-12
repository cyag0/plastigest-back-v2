<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receta de empaque de un producto terminado.
 *
 * Un producto terminado ("Dulce de coco 250 g") no sale directo de la fórmula:
 * la fórmula entrega granel ("Dulce de coco granel") y el empaque lo convierte
 * consumiendo material (frasco, tapa, etiqueta).
 *
 * Aquí se guarda de qué granel viene y cuánto granel lleva cada unidad
 * empacada. El material de empaque va en `product_packaging_materials`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Activa el paso de empaque para este producto en producción.
            $table->boolean('requires_packaging')->default(false)->after('for_sale');

            // Granel del que se empaca (una salida de la fórmula).
            $table->foreignId('packaging_source_product_id')
                ->nullable()
                ->after('requires_packaging')
                ->constrained('products')
                ->nullOnDelete();

            // Cuánto granel consume una unidad empacada, en esta unidad.
            $table->decimal('packaging_source_quantity', 12, 4)->nullable()->after('packaging_source_product_id');
            $table->foreignId('packaging_source_unit_id')
                ->nullable()
                ->after('packaging_source_quantity')
                ->constrained('units')
                ->nullOnDelete();

            $table->index('packaging_source_product_id', 'idx_product_packaging_source');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Las FK van primero: MySQL no deja soltar un índice del que
            // todavía depende una llave foránea.
            $table->dropForeign(['packaging_source_product_id']);
            $table->dropForeign(['packaging_source_unit_id']);
            $table->dropIndex('idx_product_packaging_source');
            $table->dropColumn([
                'requires_packaging',
                'packaging_source_product_id',
                'packaging_source_quantity',
                'packaging_source_unit_id',
            ]);
        });
    }
};
