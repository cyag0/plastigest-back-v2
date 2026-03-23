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
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_unique');
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->unique(['company_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_company_id_name_unique');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->unique('name');
        });
    }
};
