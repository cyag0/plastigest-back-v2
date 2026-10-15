<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llave de idempotencia de los cobros (client_request_id): la app la genera
 * en el primer intento de cobro y la repite en cada reintento. Si el
 * servidor ya registró la venta, el pedido o el abono con esa llave,
 * devuelve el registro existente en lugar de crear otro: un reintento tras
 * un timeout o una red caída ya no cobra dos veces.
 *
 * El índice único es lo que la hace segura ante dos peticiones simultáneas
 * con la misma llave: la segunda falla al insertar y responde con lo que
 * guardó la primera. MySQL permite varios NULL en un índice único, así que
 * los registros sin llave (todos los anteriores) no chocan.
 *
 * - sales y sales_orders: única por empresa.
 * - sale_payments: única por venta (un abono es de una venta ya aislada por
 *   empresa y sucursal).
 *
 * La llave va primero en el índice: así MySQL no lo toma como el índice de
 * la llave foránea (company_id, sale_id) y el down() puede quitarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('sale_number');
            $table->unique(['client_request_id', 'company_id'], 'sales_company_client_request_unique');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('order_number');
            $table->unique(['client_request_id', 'company_id'], 'sales_orders_company_client_request_unique');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('sale_id');
            $table->unique(['client_request_id', 'sale_id'], 'sale_payments_sale_client_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_sale_client_request_unique');
            $table->dropColumn('client_request_id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_company_client_request_unique');
            $table->dropColumn('client_request_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_company_client_request_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
