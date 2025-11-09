<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No se necesita hacer nada, el campo content ya existe
        // Esta migración es solo para mantener el orden correcto en futuras instalaciones
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No es necesario revertir nada
    }
};
