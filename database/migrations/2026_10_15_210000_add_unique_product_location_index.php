<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una sola fila de existencia por producto y sucursal.
 *
 * product_location no tenía llave única: el alta de la primera existencia
 * (MovementService::incrementStock) preguntaba si existía la fila y luego la
 * insertaba, así que dos movimientos simultáneos podían crear dos filas, y
 * desde ahí cada consulta leía o descontaba una distinta. Además, sin índice
 * por (producto, sucursal) el bloqueo de la fila al mover stock cubría ese
 * producto en todas las sucursales.
 *
 * Si ya hay filas repetidas la migración se detiene y dice cuáles: no se
 * fusionan solas porque no hay forma segura de saber cuál existencia es la
 * buena (sumarlas duplicaría stock; quedarse con una puede perderlo).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('product_location')
            ->select('product_id', 'location_id', DB::raw('COUNT(*) as total'))
            ->groupBy('product_id', 'location_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('product_id')
            ->orderBy('location_id')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->take(20)
                ->map(fn($row) => "producto {$row->product_id} en sucursal {$row->location_id} ({$row->total} filas)")
                ->implode('; ');
            $more = $duplicates->count() > 20 ? ' y ' . ($duplicates->count() - 20) . ' más' : '';

            throw new RuntimeException(
                "product_location tiene {$duplicates->count()} producto(s) con filas repetidas en la misma sucursal: {$list}{$more}. "
                . 'Revisa cuál es la existencia correcta de cada uno, deja una sola fila por producto y sucursal '
                . 'y vuelve a correr las migraciones. No se fusionan solas para no duplicar ni perder stock.'
            );
        }

        Schema::table('product_location', function (Blueprint $table) {
            $table->unique(['product_id', 'location_id'], 'product_location_product_location_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_location', function (Blueprint $table) {
            $table->dropUnique('product_location_product_location_unique');
        });
    }
};
