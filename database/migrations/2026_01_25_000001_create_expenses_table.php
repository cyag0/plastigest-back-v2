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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('category'); // suministros, servicios, transporte, nomina, otros
            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // efectivo, tarjeta, transferencia
            $table->text('description');
            $table->date('expense_date');
            $table->string('receipt_image')->nullable();
            
            $table->timestamps();
            
            // Índices para búsqueda rápida
            $table->index(['company_id', 'location_id', 'expense_date']);
            $table->index('category');
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
