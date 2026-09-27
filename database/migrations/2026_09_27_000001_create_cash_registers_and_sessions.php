<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas por compañía, turnos de cajero y pagos por venta.
 *
 * El turno reemplaza al cierre de caja diario por sucursal: el cajero abre
 * turno en una caja con un fondo inicial, cobra, y al cerrar cuenta el
 * efectivo. Las ventas y los movimientos de caja quedan ligados al turno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['location_id', 'is_active']);
        });

        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Un solo turno abierto por caja y por cajero. MySQL no tiene
            // índices parciales: estas columnas valen el id solo mientras el
            // turno está abierto (NULL no choca en un índice único), así que
            // ni un doble clic ni dos dispositivos pueden abrir dos turnos.
            $table->unsignedBigInteger('open_register_key')->nullable()
                ->storedAs("IF(status = 'open', cash_register_id, NULL)");
            $table->unsignedBigInteger('open_user_key')->nullable()
                ->storedAs("IF(status = 'open', user_id, NULL)");
            $table->unique('open_register_key', 'one_open_session_per_register');
            $table->unique('open_user_key', 'one_open_session_per_user');

            $table->index(['location_id', 'opened_at']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'transfer']);
            $table->decimal('amount', 12, 2);
            // Solo efectivo: lo que entregó el cliente y el cambio devuelto.
            $table->decimal('tendered', 12, 2)->nullable();
            $table->decimal('change', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            // Nullables: las ventas anteriores (y las de formv2) no tienen caja.
            $table->foreignId('cash_register_id')->nullable()->after('location_id')
                ->constrained('cash_registers')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->after('cash_register_id')
                ->constrained('cash_register_sessions')->nullOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()->after('location_id')
                ->constrained('cash_register_sessions')->nullOnDelete();
        });

        // Pago mixto: el detalle por método vive en sale_payments.
        DB::statement("ALTER TABLE sales MODIFY payment_method ENUM('cash','card','transfer','credit','mixed') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::table('sales')->where('payment_method', 'mixed')->update(['payment_method' => 'cash']);
        DB::statement("ALTER TABLE sales MODIFY payment_method ENUM('cash','card','transfer','credit') NOT NULL DEFAULT 'cash'");

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_session_id');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_session_id');
            $table->dropConstrainedForeignId('cash_register_id');
        });
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
