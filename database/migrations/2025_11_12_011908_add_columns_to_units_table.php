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
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'company_id')) {
                $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade');
            }
            if (!Schema::hasColumn('units', 'base_unit_id')) {
                $table->foreignId('base_unit_id')->nullable()->constrained('units')->onDelete('set null');
            }
            if (!Schema::hasColumn('units', 'factor_to_base')) {
                $table->decimal('factor_to_base', 15, 6)->default(1);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['base_unit_id']);
            $table->dropColumn(['company_id', 'base_unit_id', 'factor_to_base']);
        });
    }
};
