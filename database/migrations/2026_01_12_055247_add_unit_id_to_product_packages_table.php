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
        Schema::table('product_packages', function (Blueprint $table) {
            // Agregar unit_id después de quantity_per_package
            $table->foreignId('unit_id')
                ->nullable()
                ->after('quantity_per_package')
                ->constrained('units')
                ->onDelete('set null')
                ->comment('Unidad de medida en la que está expresada la cantidad del empaque');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
