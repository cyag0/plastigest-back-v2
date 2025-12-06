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
        // Agregar unit_id a movements_details
        Schema::table('movements_details', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained('units')->onDelete('set null');
        });

        // Actualizar enum de movement_reason para incluir 'shrinkage' (merma)
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason ENUM('purchase','sale','transfer_in','transfer_out','adjustment','return','damage','loss','initial','production','shrinkage') NULL");
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
