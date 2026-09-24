<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indica si el dinero de una compra o un gasto salió de la caja de la
 * sucursal. Solo esos generan egreso en caja; los que pagó el dueño o se
 * pagaron desde el banco no deben descuadrar el corte.
 *
 * Default true: es el comportamiento que ya tenía el sistema con las compras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->boolean('paid_from_cash')->default(true)->after('payment_method');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('paid_from_cash')->default(true)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('paid_from_cash');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('paid_from_cash');
        });
    }
};
