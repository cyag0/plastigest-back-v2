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
            if (Schema::hasColumn('workers', 'employee_number')) {
                $table->dropUnique('workers_employee_number_unique');
                $table->dropIndex('workers_employee_number_index');
                $table->dropColumn('employee_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (!Schema::hasColumn('workers', 'employee_number')) {
                $table->string('employee_number', 50)->nullable()->after('user_id');
                $table->unique('employee_number');
                $table->index('employee_number');
            }
        });
    }
};
