<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Agregar 'transfer' al enum movement_reason
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason ENUM(
            'purchase',
            'sale',
            'transfer_in',
            'transfer_out',
            'adjustment',
            'return',
            'damage',
            'loss',
            'initial',
            'production',
            'transfer'
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir movement_reason a su estado anterior
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason ENUM(
            'purchase',
            'sale',
            'transfer_in',
            'transfer_out',
            'adjustment',
            'return',
            'damage',
            'loss',
            'initial',
            'production'
        )");
    }
};
