<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sales_order_details');
        Schema::dropIfExists('sales_orders');

        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('order_number', 50)->unique();
            $table->date('order_date');
            $table->enum('channel', ['kiosk', 'phone', 'admin'])->default('admin');
            $table->enum('service_mode', ['counter', 'delivery'])->default('counter');
            $table->enum('status', ['pending', 'preparing', 'in_transit', 'delivered', 'cancelled'])->default('pending');
            $table->string('customer_name_snapshot')->nullable();
            $table->string('customer_phone_snapshot', 25)->nullable();
            $table->string('customer_email_snapshot')->nullable();
            $table->dateTime('promised_at')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('reserved_at')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'location_id', 'status'], 'idx_sales_orders_company_location_status');
            $table->index(['channel', 'service_mode'], 'idx_sales_orders_channel_service_mode');
            $table->index(['customer_phone_snapshot'], 'idx_sales_orders_customer_phone');
        });

        Schema::create('sales_order_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('product_packages')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('requested_quantity', 12, 3);
            $table->decimal('prepared_quantity', 12, 3)->default(0);
            $table->decimal('delivered_quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity_base', 12, 3)->default(0);
            $table->decimal('delivered_quantity_base', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_subtotal', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->json('content')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'product_id'], 'idx_sales_order_details_order_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_details');
        Schema::dropIfExists('sales_orders');
    }
};