<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El código de producto era único en toda la base: una empresa no podía usar
 * un código (o código de barras de proveedor) que ya tuviera otra empresa.
 * Pasa a ser único por empresa, que es lo que importa al vender y buscar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_code_unique');
            $table->unique(['company_id', 'code'], 'products_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_company_code_unique');
            $table->unique('code', 'products_code_unique');
        });
    }
};
