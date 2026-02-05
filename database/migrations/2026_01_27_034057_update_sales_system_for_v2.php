<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Actualizar enum de status para ventas en movements
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('draft', 'ordered', 'in_transit', 'received', 'open', 'closed', 'rejected', 'pending', 'processing', 'completed', 'cancelled') DEFAULT 'draft'");

        // Agregar campos adicionales para ventas si no existen
        Schema::table('movements', function (Blueprint $table) {
            // Agregar sale_number si no existe (similar a purchase_number)
            if (!Schema::hasColumn('movements', 'sale_number')) {
                $table->string('sale_number')->nullable();
            }

            // Agregar payment_method si no existe
            if (!Schema::hasColumn('movements', 'payment_method')) {
                $table->string('payment_method')->nullable();
            }

            // Agregar payment_status si no existe
            if (!Schema::hasColumn('movements', 'payment_status')) {
                $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending');
            }

            // Agregar paid_amount si no existe
            if (!Schema::hasColumn('movements', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0);
            }
        });

        // Actualizar movements_details para manejar precios de venta
        Schema::table('movements_details', function (Blueprint $table) {
            // Agregar sale_price si no existe (para diferenciar de unit_cost)
            if (!Schema::hasColumn('movements_details', 'sale_price')) {
                $table->decimal('sale_price', 12, 2)->nullable();
            }

            // Agregar discount si no existe
            if (!Schema::hasColumn('movements_details', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0);
            }

            // Agregar tax si no existe
            if (!Schema::hasColumn('movements_details', 'tax')) {
                $table->decimal('tax', 12, 2)->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir cambios en movements
        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'sale_number')) {
                $table->dropColumn('sale_number');
            }
            if (Schema::hasColumn('movements', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('movements', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
            if (Schema::hasColumn('movements', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });

        // Revertir cambios en movements_details
        Schema::table('movements_details', function (Blueprint $table) {
            if (Schema::hasColumn('movements_details', 'sale_price')) {
                $table->dropColumn('sale_price');
            }
            if (Schema::hasColumn('movements_details', 'discount')) {
                $table->dropColumn('discount');
            }
            if (Schema::hasColumn('movements_details', 'tax')) {
                $table->dropColumn('tax');
            }
        });

        // Revertir enum de status
        DB::statement("ALTER TABLE movements MODIFY COLUMN status ENUM('draft', 'ordered', 'in_transit', 'received', 'open', 'closed', 'rejected') DEFAULT 'draft'");
    }
};
