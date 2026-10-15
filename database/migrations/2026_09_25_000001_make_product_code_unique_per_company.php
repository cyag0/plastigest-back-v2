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
        // En una base instalada desde cero la FK de company_id usa este
        // índice compuesto (MySQL no conserva otro que empiece por
        // company_id) y no deja borrarlo: primero se le da su propio índice.
        // Ojo: volver al código único global falla si dos empresas ya
        // comparten un código.
        $hasCompanyIndex = collect(Schema::getIndexes('products'))->contains(
            fn(array $index) => $index['name'] !== 'products_company_code_unique'
                && ($index['columns'][0] ?? null) === 'company_id'
        );

        Schema::table('products', function (Blueprint $table) use ($hasCompanyIndex) {
            if (!$hasCompanyIndex) {
                $table->index('company_id', 'products_company_id_foreign');
            }
            $table->dropUnique('products_company_code_unique');
            $table->unique('code', 'products_code_unique');
        });
    }
};
