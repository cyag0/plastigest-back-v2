<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agrega la unidad de empaque (Caja, Bulto, Paquete, Promo, etc.) al paquete.
     * Es distinta de la unidad del producto base: el producto base se mide en
     * "Pieza" o "kg", pero el paquete (empaque) tiene su propia unidad.
     */
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->nullable()
                ->after('quantity_per_package')
                ->constrained('units')
                ->nullOnDelete();

            $table->index('unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
