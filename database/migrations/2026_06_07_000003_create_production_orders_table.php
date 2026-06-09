<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('formula_id')->nullable()->constrained('formulas')->nullOnDelete();
            $table->string('folio')->unique();
            $table->date('production_date');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft'); // draft | completed | cancelled
            $table->text('notes')->nullable();
            $table->decimal('total_consumed_quantity', 14, 3)->nullable();
            $table->decimal('total_produced_quantity', 14, 3)->nullable();
            $table->decimal('waste_percentage', 5, 2)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'location_id', 'production_date'], 'idx_po_company_location_date');
            $table->index('status', 'idx_po_status');
            $table->index('folio', 'idx_po_folio');
            $table->index('responsible_user_id', 'idx_po_responsible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
