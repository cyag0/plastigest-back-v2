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
        Schema::create('inventory_transfer_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_detail_id')
                ->constrained('inventory_transfer_details')
                ->onDelete('cascade');
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');
            
            // Cantidad enviada (puede diferir de la solicitada)
            $table->decimal('quantity_shipped', 10, 2);
            
            // Costo unitario al momento del envío
            $table->decimal('unit_cost', 10, 2);
            
            // Información del lote (opcional)
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            
            // Notas sobre el producto enviado
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_shipments');
    }
};
