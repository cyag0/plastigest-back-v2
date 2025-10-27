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
        Schema::create('product_kardex', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');

            // Referencia al movimiento que generó este registro
            $table->foreignId('movement_id')->constrained('movements')->onDelete('cascade');
            $table->foreignId('movement_detail_id')->constrained('movements_details')->onDelete('cascade');

            $table->enum('operation_type', ['entry', 'exit', 'adjustment']);
            $table->string('operation_reason', 100); // purchase, sale, transfer_in, etc.

            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('total_cost', 12, 2);

            // Stock antes y después del movimiento
            $table->decimal('previous_stock', 12, 3);
            $table->decimal('new_stock', 12, 3);
            $table->decimal('running_average_cost', 10, 2); // Costo promedio hasta ese momento

            $table->string('document_number', 50)->nullable();
            $table->string('batch_number', 50)->nullable();
            $table->date('expiry_date')->nullable();

            $table->foreignId('user_id')->constrained('users');
            $table->datetime('operation_date');

            $table->timestamps();

            // Índices para consultas eficientes
            $table->index(['product_id', 'location_id', 'operation_date'], 'idx_product_location_date');
            $table->index(['location_id', 'operation_date'], 'idx_location_date');
            $table->index(['operation_date', 'operation_type'], 'idx_date_type');
            $table->index('document_number', 'idx_kardex_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_kardex');
    }
};
