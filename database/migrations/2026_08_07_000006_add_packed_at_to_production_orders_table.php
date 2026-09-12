<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El empaque es un paso posterior a la producción, con sus propios estados
 * (`packaging` y `packed`). `status` ya es una columna de texto, así que sólo
 * hace falta la marca de tiempo del cierre del empaque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dateTime('packed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('packed_at');
        });
    }
};
