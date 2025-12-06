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
        Schema::create('product_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            
            // Información básica del empaque
            $table->string('package_name'); // "Caja de 6", "Display de 24"
            $table->string('barcode')->unique(); // Código de barras del empaque
            $table->decimal('quantity_per_package', 10, 2); // Cuántas unidades base contiene
            
            // Precios específicos del empaque (opcional)
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            
            // Información adicional en JSON (peso, dimensiones, etc.)
            $table->json('content')->nullable(); // { "weight": "5kg", "dimensions": "30x20x15", "sku": "..." }
            
            // Control
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Empaque por defecto para ventas
            $table->integer('sort_order')->default(0); // Para ordenar en la UI
            
            $table->timestamps();
            
            // Índices
            $table->index('barcode');
            $table->index('product_id');
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_packages');
    }
};
