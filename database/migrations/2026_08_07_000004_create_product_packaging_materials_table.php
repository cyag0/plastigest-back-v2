<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material de empaque que lleva cada unidad de un producto terminado.
 *
 * Una unidad de "Dulce de coco 250 g" lleva 1 frasco, 1 tapa y 1 etiqueta.
 * Al empacar en producción se descuenta `quantity × unidades empacadas`.
 *
 * Es una tabla aparte de `product_ingredients` a propósito: los ingredientes se
 * consumen en la fórmula (producción) y el material de empaque en el empaque,
 * que es un paso posterior y opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packaging_materials', function (Blueprint $table) {
            $table->id();

            // Producto terminado que se empaca.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Material que se consume (bolsa, frasco, etiqueta, caja...).
            $table->foreignId('material_product_id')->constrained('products')->restrictOnDelete();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            // Cantidad de material por UNA unidad del producto empacado.
            $table->decimal('quantity', 12, 4)->default(0);

            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'material_product_id'], 'unique_packaging_material_per_product');
            $table->index('material_product_id', 'idx_packaging_material_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packaging_materials');
    }
};
