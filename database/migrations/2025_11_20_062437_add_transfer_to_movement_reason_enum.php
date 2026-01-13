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
        // Paso 1: Cambiar temporalmente a VARCHAR para poder actualizar valores inválidos
        DB::statement("ALTER TABLE movements MODIFY COLUMN movement_reason VARCHAR(50)");

        // Paso 2: Actualizar cualquier valor inválido existente
        DB::statement("UPDATE movements SET movement_reason = 'transfer' WHERE movement_reason NOT IN (
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

        // Paso 3: Convertir de vuelta a ENUM con el nuevo valor incluido
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
