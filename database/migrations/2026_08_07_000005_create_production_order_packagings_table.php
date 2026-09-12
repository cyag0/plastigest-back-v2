<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empaques registrados en una orden de producción.
 *
 * Cada fila es una operación de empaque: se toma granel de una salida de la
 * orden, se consume material de empaque y se genera producto terminado.
 * Sólo se puede registrar cuando la orden ya está completada, porque hasta
 * entonces el granel no existe en inventario.
 *
 * Se guardan las cantidades reales (no las de la receta) para que el empaque
 * sea auditable y reversible aunque después se edite el producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_packagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();

            // Producto terminado que se obtiene.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 12, 4); // unidades empacadas

            // Granel consumido. Nulo si el producto se empaca sin granel de origen.
            $table->foreignId('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('source_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('source_quantity', 12, 4)->nullable();

            $table->dateTime('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'product_id'], 'idx_pop_order_product');
        });

        Schema::create('production_order_packaging_materials', function (Blueprint $table) {
            $table->id();
            // La FK se nombra a mano: el nombre que genera Laravel para esta
            // columna pasa de los 64 caracteres que admite MySQL.
            $table->unsignedBigInteger('production_order_packaging_id');
            $table->foreign('production_order_packaging_id', 'fk_popm_packaging')
                ->references('id')
                ->on('production_order_packagings')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->timestamps();

            $table->index('production_order_packaging_id', 'idx_popm_packaging');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_packaging_materials');
        Schema::dropIfExists('production_order_packagings');
    }
};
