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
        Schema::create('inventory_transfer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');

            $table->decimal('quantity_requested', 12, 3);
            $table->decimal('quantity_shipped', 12, 3)->default(0);
            $table->decimal('quantity_received', 12, 3)->default(0);

            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);

            // Control de lotes
            $table->string('batch_number', 50)->nullable();
            $table->date('expiry_date')->nullable();

            $table->text('notes')->nullable();
            $table->text('damage_report')->nullable(); // Para reportar daños en tránsito

            $table->timestamps();

            // Índices
            $table->index(['transfer_id', 'product_id'], 'idx_transfer_product');
            $table->index('batch_number', 'idx_batch_number');
            $table->index('expiry_date', 'idx_transfer_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_details');
    }
};
