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
        Schema::create('product_location_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');

            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('reserved_stock', 12, 3)->default(0);
            $table->decimal('available_stock', 12, 3)->storedAs('current_stock - reserved_stock');

            $table->decimal('minimum_stock', 12, 3)->default(0);
            $table->decimal('maximum_stock', 12, 3)->nullable();
            $table->decimal('average_cost', 10, 2)->default(0);

            $table->timestamps();

            // Unique constraint to prevent duplicate records
            $table->unique(['company_id', 'location_id', 'product_id'], 'unique_product_location_stock');

            // Índices para consultas eficientes
            $table->index(['location_id', 'current_stock'], 'idx_location_stock');
            $table->index(['product_id', 'current_stock'], 'idx_product_stock');
            $table->index(['company_id', 'location_id'], 'idx_company_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_location_stock');
    }
};
