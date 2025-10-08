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
        Schema::create('inventory_count_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->decimal('system_quantity', 12, 4); // cantidad según el sistema
            $table->decimal('counted_quantity', 12, 4)->nullable(); // cantidad contada físicamente
            $table->decimal('difference', 12, 4)->nullable(); // diferencia (counted - system)
            $table->text('notes')->nullable(); // "productos dañados", "mal ubicados", etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_count_details');
    }
};
