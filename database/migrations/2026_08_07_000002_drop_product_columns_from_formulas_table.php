<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La fórmula deja de apuntar a un solo producto: sus resultados viven ahora en
 * `formula_outputs` (migración anterior).
 *
 * Se cae también el índice único (company_id, product_id, version), que ya no
 * tiene sentido sin producto y que además impedía tener dos procesos distintos
 * que compartieran producto principal.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'unique_formula_company_product_version';
    private const NAME_INDEX = 'idx_formula_company_name';

    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            // El único no se puede reponer al revertir (dos fórmulas pueden
            // compartir producto principal), así que el rollback deja un índice
            // normal. Se comprueba antes de soltarlo para que volver a migrar
            // funcione en ambos casos.
            if ($this->hasIndex(self::OLD_UNIQUE)) {
                $table->dropUnique(self::OLD_UNIQUE);
            }

            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'expected_output_quantity']);

            // Las fórmulas se buscan y se listan por nombre.
            $table->index(['company_id', 'name'], self::NAME_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropIndex(self::NAME_INDEX);
            $table->foreignId('product_id')->nullable()->after('company_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('expected_output_quantity', 12, 4)->nullable()->after('notes');
        });

        // Devolvemos a la fórmula su salida principal.
        DB::table('formulas')->orderBy('id')->chunk(200, function ($formulas) {
            foreach ($formulas as $formula) {
                $primary = DB::table('formula_outputs')
                    ->where('formula_id', $formula->id)
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->first();

                if (!$primary) {
                    continue;
                }

                DB::table('formulas')->where('id', $formula->id)->update([
                    'product_id' => $primary->product_id,
                    'expected_output_quantity' => $primary->expected_quantity,
                ]);
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('formulas') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
