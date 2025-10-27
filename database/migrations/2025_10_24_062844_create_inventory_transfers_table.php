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
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('from_location_id')->constrained('locations')->onDelete('restrict');
            $table->foreignId('to_location_id')->constrained('locations')->onDelete('restrict');
            $table->string('transfer_number', 50)->unique();

            $table->enum('status', ['pending', 'approved', 'in_transit', 'completed', 'cancelled'])->default('pending');

            // Usuarios involucrados en el proceso
            $table->foreignId('requested_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('shipped_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');

            $table->decimal('total_cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            // Fechas del proceso
            $table->datetime('requested_at');
            $table->datetime('approved_at')->nullable();
            $table->datetime('shipped_at')->nullable();
            $table->datetime('received_at')->nullable();
            $table->datetime('cancelled_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['status', 'requested_at'], 'idx_transfer_status_date');
            $table->index(['from_location_id', 'to_location_id'], 'idx_transfer_locations');
            $table->index('transfer_number', 'idx_transfer_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
