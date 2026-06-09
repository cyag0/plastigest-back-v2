<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 12, 4); // cantidad real consumida
            $table->decimal('expected_quantity', 12, 4)->nullable(); // cantidad esperada (de fórmula)
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'product_id'], 'idx_poc_order_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_consumptions');
    }
};
