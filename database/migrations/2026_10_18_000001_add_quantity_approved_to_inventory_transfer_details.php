<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo aprobado va aparte de lo pedido. Antes aprobar menos reescribía
 * quantity_requested y ya no se sabía cuánto se había pedido.
 *
 * Las transferencias que ya pasaron por la aprobación quedan con lo aprobado
 * igual a lo pedido: lo que se pidió originalmente ya no se puede recuperar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->decimal('quantity_approved', 12, 3)->nullable()->after('quantity_requested');
        });

        DB::table('inventory_transfer_details as d')
            ->join('inventory_transfers as t', 't.id', '=', 'd.transfer_id')
            ->whereIn('t.status', ['approved', 'in_transit', 'completed'])
            ->update(['d.quantity_approved' => DB::raw('d.quantity_requested')]);
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->dropColumn('quantity_approved');
        });
    }
};
