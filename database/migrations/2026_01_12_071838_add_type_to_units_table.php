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
            $table->enum('type', ['volume', 'mass', 'quantity', 'length', 'area', 'other'])
                ->default('quantity')
                ->after('abbreviation')
                ->comment('Tipo de unidad: volume=Volumen, mass=Masa, quantity=Cantidad, length=Longitud, area=Área, other=Otro');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
