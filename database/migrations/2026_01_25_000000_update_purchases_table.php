<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old table if exists
        Schema::dropIfExists('purchases');

        // Create new purchases table
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');

            // Información básica
            $table->string('purchase_number', 50)->unique();
            $table->date('purchase_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('delivery_date')->nullable();

            // Estados del flujo: draft → ordered → in_transit → received | cancelled
            $table->enum('status', [
                'draft',
                'ordered',
                'in_transit',
                'received',
                'cancelled'
            ])->default('draft');

            // Total
            $table->decimal('total', 12, 2)->default(0);

            // Notas y documentos
            $table->text('notes')->nullable();
            $table->string('document_number')->nullable();

            // Control de usuarios
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users');

            $table->timestamps();

            // Índices
            $table->index(['company_id', 'status']);
            $table->index(['location_id', 'purchase_date']);
            $table->index(['supplier_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');

        // Restore old table structure
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');

            $table->string('purchase_number', 50)->unique();
            $table->date('purchase_date');
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'received', 'cancelled'])->default('pending');

            $table->string('supplier_name');
            $table->string('supplier_phone')->nullable();
            $table->string('supplier_email')->nullable();
            $table->text('supplier_address')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['location_id', 'purchase_date']);
            $table->index('purchase_date');
        });
    }
};
