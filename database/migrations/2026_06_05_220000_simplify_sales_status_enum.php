<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrar ventas existentes en draft/processed a closed
        DB::statement("UPDATE sales SET status='closed' WHERE status IN ('draft','processed')");

        // Cambiar enum a solo (closed, cancelled). Usar SQL raw porque Doctrine no soporta ENUM modify.
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('closed','cancelled') NOT NULL DEFAULT 'closed'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('draft','processed','closed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
