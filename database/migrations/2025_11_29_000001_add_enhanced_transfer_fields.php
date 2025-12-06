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
            // Información de empaque y envío
            $table->string('package_number')->nullable()->after('notes');
            $table->integer('package_count')->nullable()->after('package_number');
            $table->json('shipping_evidence')->nullable()->after('package_count');
            $table->text('shipping_notes')->nullable()->after('shipping_evidence');
            
            // Información de recepción
            $table->text('receiving_notes')->nullable()->after('shipping_notes');
            $table->boolean('received_complete')->default(false)->after('receiving_notes');
            $table->boolean('received_partial')->default(false)->after('received_complete');
            $table->boolean('has_differences')->default(false)->after('received_partial');
        });

        // Agregar campos adicionales a los detalles
        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->boolean('has_difference')->default(false)->after('damage_report');
            $table->decimal('difference', 10, 3)->default(0)->after('has_difference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropColumn([
                'package_number',
                'package_count', 
                'shipping_evidence',
                'shipping_notes',
                'receiving_notes',
                'received_complete',
                'received_partial',
                'has_differences'
            ]);
        });

        Schema::table('inventory_transfer_details', function (Blueprint $table) {
            $table->dropColumn(['has_difference', 'difference']);
        });
    }
};