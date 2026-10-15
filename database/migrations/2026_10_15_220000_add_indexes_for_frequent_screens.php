<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las pantallas que se abren todo el día (Inicio, Caja, Ventas ›
 * Estadísticas, Qué pedir, movimientos de inventario) y los reportes.
 *
 * Sin ellos cada carga sumaba toda la historia de la sucursal: las filas de
 * las 8 sucursales están revueltas en las mismas páginas, así que leer las de
 * una era leer casi toda la tabla. Medido con EXPLAIN ANALYZE sobre un año de
 * datos (292 mil ventas, 1 millón de movimientos de inventario), p. ej.:
 * estadísticas de ventas 115 ms → 10 ms, saldo de caja 310 ms → 10 ms,
 * resumen de movimientos de inventario 2 s → 51 ms, primer movimiento por
 * producto (Qué pedir) 2.8 s → 0.3 s.
 *
 * - Los índices "cover" incluyen las columnas que esas consultas suman o
 *   filtran: MySQL contesta desde el índice sin ir a cada fila.
 * - Empiezan por location_id para que sirvan también a la llave foránea de
 *   location_id y reemplacen su índice de una columna. Con ambos, MySQL
 *   escogía a veces el de una columna y volvía a leer cada fila.
 * - Se quitan los índices que estos dejan repetidos y los que atraían malos
 *   planes: con (company_id, location_id, status) o (source_type, source_id),
 *   MySQL prefería recorrer toda la empresa (o todos los ingresos de ventas)
 *   antes que usar el rango de fechas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Reportes de todas las sucursales; también sostiene la llave foránea de company_id.
            $table->index(['company_id', 'sale_date'], 'sales_company_date_index');
            // Inicio, estadísticas, corte del día y reportes de una sucursal.
            $table->index(
                ['location_id', 'company_id', 'sale_date', 'status', 'payment_method', 'total', 'deleted_at', 'created_at'],
                'sales_location_company_date_cover_index'
            );
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_id_location_id_status_index');
            $table->dropIndex('sales_sale_date_index');
        });
        $this->dropIndexIfExists('sales', 'sales_location_id_foreign');

        Schema::table('sales_details', function (Blueprint $table) {
            // Productos más vendidos: los renglones de cada venta sin leer la fila.
            $table->index(
                ['sale_id', 'product_id', 'unit_id', 'package_id', 'quantity', 'total'],
                'sales_details_sale_product_cover_index'
            );
        });
        Schema::table('sales_details', function (Blueprint $table) {
            $table->dropIndex('sales_details_sale_id_product_id_index');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            // Saldo, totales del día y del mes y tendencia de Caja.
            $table->index(
                ['location_id', 'company_id', 'movement_date', 'type', 'payment_method', 'amount'],
                'cash_movements_location_company_date_cover_index'
            );
            // Abonos por la hora en que se registraron (reporte de ventas); también
            // sostiene la llave foránea de company_id.
            $table->index(['company_id', 'created_at'], 'cash_movements_company_created_index');
            // Movimientos de un documento (venta, gasto, compra): mismo uso que
            // (source_type, source_id), pero source_type solo ya no se usa como
            // índice para "todos los ingresos de ventas".
            $table->index(['source_id', 'source_type'], 'cash_movements_source_id_source_type_index');
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex('cash_movements_company_id_location_id_movement_date_index');
            $table->dropIndex('cash_movements_source_type_source_id_index');
        });
        $this->dropIndexIfExists('cash_movements', 'cash_movements_location_id_foreign');

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            // Qué pedir (primer movimiento por producto y sucursal), inventario a
            // una fecha y el resumen de movimientos de inventario.
            $table->index(
                ['company_id', 'location_id', 'product_id', 'created_at', 'direction'],
                'idx_ledger_company_location_product_date'
            );
            // Qué pedir: de dónde llega cada producto (traspasos, compras, producción).
            $table->index(['company_id', 'reason', 'created_at'], 'idx_ledger_company_reason_date');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('idx_ledger_company_location_product_date');
            $table->dropIndex('idx_ledger_company_reason_date');
        });

        // Primero los índices de antes: las llaves foráneas no pueden quedarse
        // sin índice mientras se quitan los nuevos.
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->index(['company_id', 'location_id', 'movement_date'], 'cash_movements_company_id_location_id_movement_date_index');
            $table->index(['source_type', 'source_id'], 'cash_movements_source_type_source_id_index');
        });
        $this->addIndexIfMissing('cash_movements', 'cash_movements_location_id_foreign', ['location_id']);
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex('cash_movements_location_company_date_cover_index');
            $table->dropIndex('cash_movements_company_created_index');
            $table->dropIndex('cash_movements_source_id_source_type_index');
        });

        Schema::table('sales_details', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id'], 'sales_details_sale_id_product_id_index');
        });
        Schema::table('sales_details', function (Blueprint $table) {
            $table->dropIndex('sales_details_sale_product_cover_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['company_id', 'location_id', 'status'], 'sales_company_id_location_id_status_index');
            $table->index(['sale_date'], 'sales_sale_date_index');
        });
        $this->addIndexIfMissing('sales', 'sales_location_id_foreign', ['location_id']);
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_location_company_date_cover_index');
            $table->dropIndex('sales_company_date_index');
        });
    }

    /**
     * El índice que MySQL crea solo para una llave foránea lo quita él mismo,
     * sin avisar, en cuanto otro índice puede sostenerla (los "cover" que
     * empiezan por location_id). Si se creó a mano sigue ahí: se quita aquí.
     */
    private function dropIndexIfExists(string $table, string $index): void
    {
        if (Schema::hasIndex($table, $index)) {
            Schema::table($table, fn(Blueprint $t) => $t->dropIndex($index));
        }
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (!Schema::hasIndex($table, $index)) {
            Schema::table($table, fn(Blueprint $t) => $t->index($columns, $index));
        }
    }
};
