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
        Schema::create('product_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('reserved_stock', 12, 3)->default(0);
            $table->decimal('minimum_stock', 12, 3)->default(0);
            $table->decimal('maximum_stock', 12, 3)->default(0);
            $table->decimal('average_cost', 10, 2)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para mejor performance
            $table->index(['location_id', 'current_stock'], 'idx_location_stock');
            $table->index(['product_id', 'current_stock'], 'idx_product_stock');
            $table->index('last_movement_at', 'idx_last_movement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_location');
    }
};
