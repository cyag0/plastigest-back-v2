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
        Schema::table('movements_details', function (Blueprint $table) {
            // Agregar campos para mejor rastreabilidad del stock
            $table->decimal('previous_stock', 12, 3)->default(0)->after('quantity');
            $table->decimal('new_stock', 12, 3)->default(0)->after('previous_stock');

            // Agregar campos para control de lotes y vencimientos
            $table->string('batch_number', 50)->nullable()->after('total_cost');
            $table->date('expiry_date')->nullable()->after('batch_number');

            // Mejorar precision de quantity
            $table->decimal('quantity', 12, 3)->change();

            // Agregar índices para mejores consultas
            $table->index(['movement_id', 'product_id'], 'idx_movement_product');
            $table->index(['product_id', 'batch_number'], 'idx_product_batch');
            $table->index('expiry_date', 'idx_expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements_details', function (Blueprint $table) {
            // Remover campos agregados
            $table->dropColumn([
                'previous_stock',
                'new_stock',
                'batch_number',
                'expiry_date'
            ]);

            // Revertir precision de quantity
            $table->decimal('quantity', 12, 4)->change();

            // Remover índices
            $table->dropIndex('idx_movement_product');
            $table->dropIndex('idx_product_batch');
            $table->dropIndex('idx_expiry_date');
        });
    }
};
