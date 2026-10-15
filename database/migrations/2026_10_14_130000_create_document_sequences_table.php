<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores de folios (ventas, compras, transferencias, pedidos). Cada serie
 * es una fila; el siguiente número se toma incrementándola con bloqueo de
 * fila, así dos documentos creados al mismo tiempo no repiten folio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            // Ej. "sale:3:20261014", "purchase". Llave primaria (sin id
            // autoincremental) para que el upsert bloquee solo esta fila.
            $table->string('scope', 100)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
