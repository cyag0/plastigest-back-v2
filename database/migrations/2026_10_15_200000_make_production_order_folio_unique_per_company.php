<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los folios de producción (PROD-YYYY-NNNN) se numeran por empresa: el
 * índice único pasa de `folio` a (company_id, folio). Con el índice anterior
 * la primera orden de una segunda empresa chocaba con la de la primera.
 *
 * Los datos existentes no cambian: si el folio era único en toda la tabla,
 * también lo es por empresa. idx_po_folio (no único) sigue para buscar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unique(['company_id', 'folio'], 'production_orders_company_folio_unique');
            $table->dropUnique('production_orders_folio_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unique('folio', 'production_orders_folio_unique');
            $table->dropUnique('production_orders_company_folio_unique');
        });
    }
};
