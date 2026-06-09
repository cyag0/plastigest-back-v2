<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ampliamos la razón para soportar mermas de producción
        \DB::statement("ALTER TABLE inventory_adjustment_details MODIFY COLUMN reason_code ENUM('loss','damage','count_diff','expiry','theft','found','other','production_waste') NOT NULL DEFAULT 'other'");

        Schema::table('inventory_adjustment_details', function (Blueprint $table) {
            $table->unsignedBigInteger('reference_id')->nullable()->after('notes');
            $table->string('reference_type')->nullable()->after('reference_id');

            $table->index(['reference_type', 'reference_id'], 'idx_adj_detail_reference');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_details', function (Blueprint $table) {
            $table->dropIndex('idx_adj_detail_reference');
            $table->dropColumn(['reference_id', 'reference_type']);
        });

        \DB::statement("ALTER TABLE inventory_adjustment_details MODIFY COLUMN reason_code ENUM('loss','damage','count_diff','expiry','theft','found','other') NOT NULL DEFAULT 'other'");
    }
};
