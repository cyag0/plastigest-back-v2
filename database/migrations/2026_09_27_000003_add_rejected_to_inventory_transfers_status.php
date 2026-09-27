<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TransferStatus::REJECTED no estaba en el enum de la columna: rechazar una
 * transferencia fallaba con "Data truncated for column 'status'".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_transfers MODIFY status ENUM('pending','approved','rejected','in_transit','completed','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::table('inventory_transfers')->where('status', 'rejected')->update(['status' => 'cancelled']);
        DB::statement("ALTER TABLE inventory_transfers MODIFY status ENUM('pending','approved','in_transit','completed','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
