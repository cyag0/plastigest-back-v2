<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro mayor de existencias: un renglón por cada cambio de stock.
 *
 * Hasta ahora el rastro estaba partido en dos tablas con formas distintas
 * (`movements`, que es un documento con varias líneas, e
 * `inventory_adjustment_details`), y varios flujos —ventas, compras, empaque—
 * movían stock sin registrar nada. Esta tabla no sustituye a esos documentos:
 * guarda el delta atómico que cada uno provocó, que es lo que hace falta para
 * responder "qué pasó con este producto".
 *
 * Se escribe desde MovementService, el único punto por el que pasan todos los
 * cambios, para que ningún flujo pueda saltárselo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // 'in' | 'out'. La cantidad se guarda siempre positiva.
            $table->string('direction', 3);
            $table->string('reason', 40);

            // Todo en la unidad base del producto: es lo que permite sumar el
            // historial sin arrastrar conversiones.
            $table->decimal('quantity', 16, 4);
            $table->decimal('previous_stock', 16, 4);
            $table->decimal('new_stock', 16, 4);

            $table->decimal('unit_cost', 16, 4)->nullable();

            // Documento de origen, polimórfico: una venta, una compra, una orden…
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // En traspasos, la sucursal del otro lado.
            $table->foreignId('counterpart_location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            // La consulta que importa: el historial de un producto en una
            // sucursal, del más reciente al más viejo.
            $table->index(['product_id', 'location_id', 'id'], 'idx_ledger_product_location');
            $table->index(['company_id', 'created_at'], 'idx_ledger_company_date');
            $table->index(['reference_type', 'reference_id'], 'idx_ledger_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_entries');
    }
};
