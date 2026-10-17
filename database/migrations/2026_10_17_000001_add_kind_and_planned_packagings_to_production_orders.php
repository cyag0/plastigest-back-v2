<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos tipos de orden y el envasado de los borradores.
 *
 * - `kind`: `process` es una producción (cocos → agua y pulpa); `packaging` es
 *   un envasado posterior de granel que ya estaba en inventario (el agua que
 *   quedó en la mañana se embotella en la tarde). Antes el envasado sólo podía
 *   colgarse de la orden que produjo el granel.
 * - `planned_packagings`: lo que se va a envasar al registrar un borrador
 *   (producto terminado y cantidad), como ya se hace con `wastes`. Al
 *   completarse se vuelve renglones de production_order_packagings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('kind', 20)->default('process')->after('formula_id');
            $table->json('planned_packagings')->nullable()->after('wastes');

            $table->index(['company_id', 'kind'], 'idx_po_company_kind');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex('idx_po_company_kind');
            $table->dropColumn(['kind', 'planned_packagings']);
        });
    }
};
