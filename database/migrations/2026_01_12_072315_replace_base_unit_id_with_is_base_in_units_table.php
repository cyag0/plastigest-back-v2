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
            // Eliminar la foreign key y el campo base_unit_id
            if (Schema::hasColumn('units', 'base_unit_id')) {
                $table->dropForeign(['base_unit_id']);
                $table->dropColumn('base_unit_id');
            }

            // Agregar el campo is_base
            $table->boolean('is_base')
                ->default(false)
                ->after('type')
                ->comment('Indica si es una unidad base del sistema');
            
            // Mantener factor_to_base (no eliminarlo)
            // Este campo indica cuántas unidades base equivalen a 1 de esta unidad
            // Ejemplo: 1 kg = 1000 g, entonces para kg, factor_to_base = 1
            // y para g, factor_to_base = 0.001
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Eliminar is_base
            $table->dropColumn('is_base');

            // Restaurar base_unit_id
            $table->foreignId('base_unit_id')
                ->nullable()
                ->after('company_id')
                ->constrained('units')
                ->onDelete('set null');
        });
    }
};
