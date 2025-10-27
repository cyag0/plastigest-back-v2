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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');

            // Información básica de la compra
            $table->string('purchase_number', 50)->unique();
            $table->date('purchase_date');
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();

            // Estado de la compra
            $table->enum('status', ['pending', 'confirmed', 'received', 'cancelled'])->default('pending');

            // Información del proveedor (simple sin tabla separada)
            $table->string('supplier_name');
            $table->string('supplier_phone')->nullable();
            $table->string('supplier_email')->nullable();
            $table->text('supplier_address')->nullable();

            // Totales
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // Referencias y notas
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            // Usuario que creó la compra
            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();

            // Índices
            $table->index(['company_id', 'status']);
            $table->index(['location_id', 'purchase_date']);
            $table->index('purchase_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
