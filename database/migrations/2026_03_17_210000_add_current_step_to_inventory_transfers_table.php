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
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_step')
                ->default(1)
                ->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropColumn('current_step');
        });
    }
};
