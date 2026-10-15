<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca cuándo se avisó que la tarea vence pronto y cuándo que ya venció, para
 * que el aviso programado (cada hora) salga una sola vez por tarea. Antes el
 * job diario cambiaba el status a "overdue" (la tarea desaparecía de las listas
 * de pendientes y en proceso) y no avisaba a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('due_soon_notified_at')->nullable()->after('due_date');
            $table->timestamp('overdue_notified_at')->nullable()->after('due_soon_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['due_soon_notified_at', 'overdue_notified_at']);
        });
    }
};
