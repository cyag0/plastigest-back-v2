<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número corto de los pedidos del kiosko ("Tu número es 7"): consecutivo por
 * sucursal y por día, empieza en 1 (serie kiosk_ticket:{sucursal}:{Ymd} de
 * document_sequences). Los demás pedidos no lo llevan (null).
 *
 * El índice (status, channel, created_at) sostiene la búsqueda de los
 * pedidos pendientes del kiosko que ya vencieron (kiosk:expire-orders, cada
 * 10 minutos y de todas las empresas): sin él se recorrían todos los pedidos
 * del kiosko de la historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('ticket_number')->nullable()->after('order_number');
            $table->index(['status', 'channel', 'created_at'], 'idx_sales_orders_status_channel_created');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('idx_sales_orders_status_channel_created');
            $table->dropColumn('ticket_number');
        });
    }
};
