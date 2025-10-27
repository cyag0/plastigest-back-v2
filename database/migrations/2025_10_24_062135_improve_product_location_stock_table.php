<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_location', function (Blueprint $table) {
            // Agregar campos adicionales para mejor control de inventario
            $table->decimal('reserved_stock', 12, 3)->default(0)->after('current_stock');
            $table->decimal('maximum_stock', 12, 3)->default(0)->after('minimum_stock');
            $table->decimal('average_cost', 10, 2)->default(0)->after('maximum_stock');
            $table->timestamp('last_movement_at')->nullable()->after('average_cost');

            // Cambiar precision de stocks existentes para ser más exactos
            $table->decimal('current_stock', 12, 3)->default(0)->change();
            $table->decimal('minimum_stock', 12, 3)->default(0)->change();

            // Agregar índices para mejor performance
            $table->index(['location_id', 'current_stock'], 'idx_location_stock');
            $table->index(['product_id', 'current_stock'], 'idx_product_stock');
            $table->index('last_movement_at', 'idx_last_movement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_location', function (Blueprint $table) {
            // Remover campos agregados
            $table->dropColumn([
                'reserved_stock',
                'maximum_stock',
                'average_cost',
                'last_movement_at'
            ]);

            // Remover índices
            $table->dropIndex('idx_location_stock');
            $table->dropIndex('idx_product_stock');
            $table->dropIndex('idx_last_movement');

            // Revertir precision de decimales
            $table->decimal('current_stock', 12, 2)->default(0)->change();
            $table->decimal('minimum_stock', 12, 2)->default(0)->change();
        });
    }
};
