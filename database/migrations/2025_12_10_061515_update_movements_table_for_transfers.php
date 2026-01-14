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
        // Update status enum to add 'rejected' status for transfers
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('draft', 'ordered', 'in_transit', 'received', 'open', 'closed', 'rejected') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'rejected' status from enum
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('draft', 'ordered', 'in_transit', 'received', 'open', 'closed') DEFAULT 'draft'");
    }
};
