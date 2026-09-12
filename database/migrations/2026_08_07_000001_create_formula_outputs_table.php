<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salidas (resultados) de una fórmula.
 *
 * Antes una fórmula producía un solo producto: `formulas.product_id` con
 * `formulas.expected_output_quantity`. Eso no alcanza para un proceso real: de
 * un lote de cocos salen a la vez agua de coco, pulpa para dulce y fibra.
 *
 * Ahora la fórmula es el proceso (ingredientes → resultados) y cada resultado
 * vive aquí. La fila marcada `is_primary` es la que da nombre al proceso y la
 * que se usa como referencia de rendimiento.
 *
 * Las fórmulas existentes se migran aquí antes de que la migración siguiente
 * elimine las columnas viejas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formula_id')->constrained('formulas')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('expected_quantity', 12, 4)->default(0);

            // Resultado principal del proceso. Sólo una fila por fórmula.
            $table->boolean('is_primary')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['formula_id', 'product_id'], 'idx_formula_output_formula_product');
        });

        $this->migrateExistingFormulas();
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_outputs');
    }

    /**
     * Convierte el producto objetivo de cada fórmula existente en su primera
     * salida. La unidad sale del producto; si el producto no tiene unidad se
     * usa cualquier unidad base para no perder la fórmula.
     */
    private function migrateExistingFormulas(): void
    {
        if (!Schema::hasColumn('formulas', 'product_id')) {
            return;
        }

        $fallbackUnitId = DB::table('units')->orderBy('id')->value('id');
        $now = now();

        DB::table('formulas')
            ->join('products', 'products.id', '=', 'formulas.product_id')
            ->select(
                'formulas.id as formula_id',
                'formulas.product_id',
                'formulas.expected_output_quantity',
                'products.unit_id',
            )
            ->orderBy('formulas.id')
            ->chunk(200, function ($formulas) use ($fallbackUnitId, $now) {
                $rows = [];

                foreach ($formulas as $formula) {
                    $unitId = $formula->unit_id ?? $fallbackUnitId;
                    if (!$unitId) {
                        continue;
                    }

                    $rows[] = [
                        'formula_id' => $formula->formula_id,
                        'product_id' => $formula->product_id,
                        'unit_id' => $unitId,
                        // Sin rendimiento capturado asumimos 1: es el
                        // comportamiento que ya tenía el frontend.
                        'expected_quantity' => $formula->expected_output_quantity ?? 1,
                        'is_primary' => true,
                        'sort_order' => 0,
                        'notes' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table('formula_outputs')->insert($rows);
                }
            });
    }
};
