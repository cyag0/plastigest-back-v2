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
            // Cambiar 'abbreviation' a 'symbol'
            $table->renameColumn('abbreviation', 'symbol');
            
            // Agregar nuevos campos
            $table->enum('type', ['quantity', 'length', 'weight', 'volume', 'other'])
                ->default('quantity')
                ->after('description');
            
            $table->boolean('is_base')
                ->default(false)
                ->after('type')
                ->comment('Indica si es una unidad base para conversiones');
            
            $table->decimal('conversion_rate', 15, 6)
                ->default(1.000000)
                ->after('is_base')
                ->comment('Relación respecto a la unidad base del mismo tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_base', 'conversion_rate']);
            $table->renameColumn('symbol', 'abbreviation');
        });
    }
};
