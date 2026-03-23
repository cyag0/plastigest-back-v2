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
        Schema::table('inventory_adjustment_details', function (Blueprint $table): void {
            if (!Schema::hasColumn('inventory_adjustment_details', 'content')) {
                $table->json('content')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_adjustment_details', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_adjustment_details', 'content')) {
                $table->dropColumn('content');
            }
        });
    }
};
