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
        Schema::table('workers', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique('workers_user_id_company_id_unique');
            $table->index(['user_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropIndex('workers_user_id_company_id_index');
            $table->dropIndex('workers_user_id_index');
            $table->unique(['user_id', 'company_id']);
        });
    }
};
