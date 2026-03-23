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
        Schema::create('inventory_adjustment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');

            $table->enum('direction', ['in', 'out'])->default('out');

            $table->decimal('quantity', 12, 3);
            $table->foreignId('unit_id')->constrained('units')->onDelete('restrict');

            $table->decimal('previous_stock', 12, 3)->default(0);
            $table->decimal('new_stock', 12, 3)->default(0);

            $table->enum('reason_code', ['loss', 'damage', 'count_diff', 'expiry', 'theft', 'found', 'other'])->default('other');

            $table->text('notes')->nullable();
            $table->dateTime('applied_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'location_id', 'created_at'], 'idx_adj_detail_company_location_date');
            $table->index('reason_code', 'idx_adj_detail_reason');
            $table->index(['product_id', 'location_id'], 'idx_adj_detail_product_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_details');
    }
};
