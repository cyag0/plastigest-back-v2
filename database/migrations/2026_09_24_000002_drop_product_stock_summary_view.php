<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimina la vista huérfana product_stock_summary.
 *
 * Se creó fuera de las migraciones y depende de stock_batches y
 * stock_reservations, tablas que ya no existen. Ningún código la consulta,
 * pero al estar rota hace fallar mysqldump a media exportación
 * ("View ... references invalid table(s)"), así que los respaldos salían
 * incompletos sin avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS product_stock_summary');
    }

    public function down(): void
    {
        // No se recrea: dependía de tablas que ya no existen.
    }
};
