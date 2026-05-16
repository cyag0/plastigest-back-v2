<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            $table->enum('type', ['income', 'expense', 'adjustment']);
            $table->decimal('amount', 10, 2);
            $table->string('concept', 255);
            $table->enum('payment_method', ['cash', 'card', 'transfer', 'other'])->default('cash');

            // Origen del movimiento (para integración futura con ventas/compras)
            $table->string('source_type', 50)->nullable(); // 'sale', 'purchase', 'manual'
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_url', 500)->nullable(); // URL del frontend para navegar al origen

            $table->text('notes')->nullable();
            $table->date('movement_date');
            $table->timestamps();

            // Índices
            $table->index(['company_id', 'location_id', 'movement_date']);
            $table->index(['source_type', 'source_id']);
            $table->index('type');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
