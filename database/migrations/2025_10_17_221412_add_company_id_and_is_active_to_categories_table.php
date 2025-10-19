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
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->boolean('is_active')->default(true);

            // Índice para performance
            $table->index(['company_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'is_active']);
            $table->dropColumn(['company_id', 'is_active']);
        });
    }
};
