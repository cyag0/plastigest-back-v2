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
        Schema::table('movements', function (Blueprint $table) {
            // Eliminar índices primero
            $table->dropIndex('idx_reference');
            $table->dropIndex('idx_document_number');

            // Eliminar columnas
            $table->dropColumn([
                'reference_id',
                'document_number',
                'comments',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            // Restaurar columnas
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('document_number', 50)->nullable();

            // Restaurar índices
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
            $table->index('document_number', 'idx_document_number');
        });
    }
};
