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
            // Eliminar el índice primero
            $table->dropIndex(['employee_number']);
            // Eliminar la columna
            $table->dropColumn('employee_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            // Recrear la columna si necesitamos hacer rollback
            $table->string('employee_number', 50)->unique()->after('user_id');
            // Recrear el índice
            $table->index(['employee_number']);
        });
    }
};
