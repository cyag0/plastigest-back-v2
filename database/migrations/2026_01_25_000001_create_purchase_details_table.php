<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');

            // Producto base (siempre el producto real, no el paquete)
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');

            // Si es un paquete, guardamos el package_id
            $table->foreignId('package_id')->nullable()->constrained('product_packages')->onDelete('set null');

            // Cantidad y unidad en que se pidió
            $table->decimal('quantity', 12, 4);
            $table->foreignId('unit_id')->constrained('units');

            // Precios (en la unidad especificada)
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);

            // Al recibir: cantidad recibida (puede ser diferente a la pedida)
            $table->decimal('quantity_received', 12, 4)->nullable();
            $table->timestamp('received_at')->nullable();

            // Notas del producto
            $table->text('notes')->nullable();

            $table->timestamps();

            // Índices
            $table->index('purchase_id');
            $table->index('product_id');
            $table->index(['purchase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_details');
    }
};
