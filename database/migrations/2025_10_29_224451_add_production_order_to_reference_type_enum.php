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
        // Agregar 'production_order' al enum reference_type
        DB::statement("ALTER TABLE movements MODIFY COLUMN reference_type ENUM(
            'purchase_order',
            'sales_order',
            'transfer',
            'adjustment',
            'manual',
            'production_order'
        ) NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir reference_type a su estado anterior
        DB::statement("ALTER TABLE movements MODIFY COLUMN reference_type ENUM(
            'purchase_order',
            'sales_order',
            'transfer',
            'adjustment',
            'manual'
        ) NULL");
    }
};
