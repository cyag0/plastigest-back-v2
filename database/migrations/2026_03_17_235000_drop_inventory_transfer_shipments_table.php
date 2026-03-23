<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('inventory_transfer_shipments')) {
            Schema::drop('inventory_transfer_shipments');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('inventory_transfer_shipments')) {
            Schema::create('inventory_transfer_shipments', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_detail_id')->constrained('inventory_transfer_details')->onDelete('cascade');
                $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
                $table->foreignId('package_id')->nullable()->constrained('product_packages')->onDelete('restrict');
                $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('restrict');
                $table->decimal('quantity_shipped', 10, 3);
                $table->decimal('unit_cost', 10, 2)->default(0);
                $table->string('batch_number')->nullable();
                $table->date('expiry_date')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }
};
