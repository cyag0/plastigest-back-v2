<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('movements_details', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('product_id')->constrained('product_packages')->onDelete('set null');
            $table->foreignId('unit_id')->nullable()->after('package_id')->constrained('units')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements_details', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['package_id', 'unit_id']);
        });
    }
};
