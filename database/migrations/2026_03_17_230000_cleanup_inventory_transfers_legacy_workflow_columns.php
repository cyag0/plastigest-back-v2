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
        Schema::table('inventory_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transfers', 'approved_by')) {
                $table->dropForeign(['approved_by']);
            }
            if (Schema::hasColumn('inventory_transfers', 'shipped_by')) {
                $table->dropForeign(['shipped_by']);
            }
            if (Schema::hasColumn('inventory_transfers', 'received_by')) {
                $table->dropForeign(['received_by']);
            }

            if (Schema::hasColumn('inventory_transfers', 'current_step')) {
                $table->dropColumn('current_step');
            }

            $dropColumns = [
                'approved_by',
                'shipped_by',
                'received_by',
                'rejection_reason',
                'requested_at',
                'approved_at',
                'shipped_at',
                'received_at',
                'cancelled_at',
                'rejected_at',
                'package_number',
                'package_count',
                'shipping_evidence',
                'shipping_notes',
                'receiving_notes',
                'received_complete',
                'received_partial',
                'has_differences',
            ];

            foreach ($dropColumns as $column) {
                if (Schema::hasColumn('inventory_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('current_step')->default(1);
            $table->text('rejection_reason')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('package_number')->nullable();
            $table->integer('package_count')->nullable();
            $table->json('shipping_evidence')->nullable();
            $table->text('shipping_notes')->nullable();
            $table->text('receiving_notes')->nullable();
            $table->boolean('received_complete')->default(false);
            $table->boolean('received_partial')->default(false);
            $table->boolean('has_differences')->default(false);
        });
    }
};
