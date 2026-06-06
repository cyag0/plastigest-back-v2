<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Historial append-only de cambios de estado.
            // Cada entrada: { from, to, by_user_id, by_user_name, at, reason }.
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
