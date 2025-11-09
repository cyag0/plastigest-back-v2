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
        // Agregar 'production' al enum movement_type
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_type ENUM('entry', 'exit', 'transfer', 'adjustment', 'production') NOT NULL");

        // Agregar 'production' al enum movement_reason
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir movement_type a su estado anterior
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_type ENUM('entry', 'exit', 'transfer', 'adjustment') NOT NULL");

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
            'initial'
        )");
    }
};
