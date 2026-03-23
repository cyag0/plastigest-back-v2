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
        // Agregar soporte de paquetes y unidades a inventory_transfer_details
        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->foreignId('package_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_packages')
                ->onDelete('restrict');
            
            $table->foreignId('unit_id')
                ->after('package_id')
                ->constrained('units')
                ->onDelete('restrict');
            
            // Índice compuesto para búsquedas
            $table->index(['product_id', 'package_id'], 'idx_transfer_detail_product_package');
        });

        // Agregar soporte de paquetes y unidades a inventory_transfer_shipments
        Schema::table('inventory_transfer_shipments', function (Blueprint $table) {
            $table->foreignId('package_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_packages')
                ->onDelete('restrict');
            
            $table->foreignId('unit_id')
                ->after('package_id')
                ->constrained('units')
                ->onDelete('restrict');
            
            // Índice compuesto para búsquedas
            $table->index(['product_id', 'package_id'], 'idx_transfer_shipment_product_package');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->dropIndex('idx_transfer_detail_product_package');
            $table->dropForeign(['package_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['package_id', 'unit_id']);
        });

        Schema::table('inventory_transfer_shipments', function (Blueprint $table) {
            $table->dropIndex('idx_transfer_shipment_product_package');
            $table->dropForeign(['package_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['package_id', 'unit_id']);
        });
    }
};
