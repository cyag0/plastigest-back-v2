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
            // Agregar campos faltantes que existían en la versión original
            if (!Schema::hasColumn('units', 'description')) {
                $table->text('description')->nullable()->after('symbol');
            }

            if (!Schema::hasColumn('units', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('description');
            }

            if (!Schema::hasColumn('units', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('is_active')
                    ->constrained('companies')
                    ->onDelete('cascade');
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
            $table->dropColumn(['description', 'is_active', 'company_id']);
        });
    }
};
