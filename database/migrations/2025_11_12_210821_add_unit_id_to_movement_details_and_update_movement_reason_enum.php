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
        // Agregar unit_id a movements_details solo si no existe
        if (!Schema::hasColumn('movements_details', 'unit_id')) {
            Schema::table('movements_details', function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->after('product_id')->constrained('units')->onDelete('set null');
            });
        }

        // Cambiar temporalmente movement_reason a VARCHAR para actualizar valores
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason VARCHAR(50) NULL");
        
        // Actualizar valores NULL o inválidos a 'adjustment'
        DB::statement("UPDATE movements SET movement_reason = 'adjustment' WHERE movement_reason IS NULL OR movement_reason NOT IN ('purchase','sale','transfer_in','transfer_out','adjustment','return','damage','loss','initial','production')");
        
        // Ahora cambiar a ENUM con el nuevo valor incluido
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason ENUM('purchase','sale','transfer_in','transfer_out','adjustment','return','damage','loss','initial','production','shrinkage') NOT NULL DEFAULT 'adjustment'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar unit_id de movements_details
        Schema::table('movements_details', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });

        // Revertir enum de movement_reason
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason ENUM('purchase','sale','transfer_in','transfer_out','adjustment','return','damage','loss','initial','production') NULL");
    }
};
