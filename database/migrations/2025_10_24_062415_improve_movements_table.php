<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            // Agregar campos para mejor rastreabilidad
            $table->enum('movement_reason', [
                'purchase',
                'sale',
                'transfer_in',
                'transfer_out',
                'adjustment',
                'return',
                'damage',
                'loss',
                'initial'
            ])->after('movement_type');

            $table->enum('reference_type', [
                'purchase_order',
                'sales_order',
                'transfer',
                'adjustment',
                'manual'
            ])->nullable()->after('movement_reason');

            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->string('document_number', 50)->nullable()->after('reference_id');

            // Mejorar el enum de movement_type
            DB::statement("ALTER TABLE movements MODIFY COLUMN movement_type ENUM('entry', 'exit', 'transfer', 'adjustment') NOT NULL");

            // Renombrar warehouse a location para consistencia
            $table->renameColumn('warehouse_origin_id', 'location_origin_id');
            $table->renameColumn('warehouse_destination_id', 'location_destination_id');

            // Cambiar date a datetime para mejor precisión
            $table->datetime('movement_date')->after('user_id');
            $table->dropColumn('date');

            // Agregar índices
            $table->index(['movement_type', 'movement_date'], 'idx_movement_type_date');
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
            $table->index('document_number', 'idx_document_number');
            $table->index('movement_date', 'idx_movement_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            // Remover campos agregados
            $table->dropColumn([
                'movement_reason',
                'reference_type',
                'reference_id',
                'document_number',
                'movement_date'
            ]);

            // Revertir nombres de columnas
            $table->renameColumn('location_origin_id', 'warehouse_origin_id');
            $table->renameColumn('location_destination_id', 'warehouse_destination_id');

            // Restaurar campo date
            $table->date('date')->after('user_id');

            // Revertir enum de movement_type
            DB::statement("ALTER TABLE movements MODIFY COLUMN movement_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL");

            // Remover índices
            $table->dropIndex('idx_movement_type_date');
            $table->dropIndex('idx_reference');
            $table->dropIndex('idx_document_number');
            $table->dropIndex('idx_movement_date');
        });
    }
};
