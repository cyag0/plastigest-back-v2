<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La razón social de clientes y proveedores cabía en 20 caracteres: una real
 * como «Cocos del Pacífico SA de CV» no entraba y la app la cortaba. Pasa a
 * 255, como la de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('social_reason', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Volver a 20 cortaría razones sociales ya guardadas: se deja en 255.
    }
};
