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
        // Actualizar el enum de status para incluir los nuevos estados de compra
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('draft', 'ordered', 'in_transit', 'received', 'open', 'closed') DEFAULT 'draft'");

        // Actualizar registros existentes
        // 'open' se convierte en 'draft' para compras y permanece 'open' para otros
        DB::statement("
            UPDATE movements
            SET status = 'draft'
            WHERE status = 'open'
            AND movement_type = 'entry'
            AND movement_reason = 'purchase'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir los estados nuevos a los originales
        DB::statement("
            UPDATE movements
            SET status = 'open'
            WHERE status IN ('draft', 'ordered', 'in_transit', 'received')
        ");

        // Restaurar enum original
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('open', 'closed') DEFAULT 'open'");
    }
};
