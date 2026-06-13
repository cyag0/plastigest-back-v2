<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FormulasSeeder — Una sola fórmula de ejemplo: Agua de Coco.
 *
 * Produce "Agua de Coco 1 Litro" a partir de "Coco Entero". Sirve como plantilla
 * para que el cliente capture el resto de sus fórmulas desde la app.
 *
 * El rendimiento (cantidad producida al ejecutar la fórmula una vez) vive en la
 * FÓRMULA (`expected_output_quantity`); su unidad la resuelve el frontend desde
 * la unidad del producto objetivo.
 */
class FormulasSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('name', 'Cocos Francisco')->first();
        if (!$company) {
            $this->command->error('No se encontró la compañía Cocos Francisco.');
            return;
        }

        // Limpiar fórmulas previas de esta compañía
        DB::table('formulas')->where('company_id', $company->id)->delete();

        $products = Product::where('company_id', $company->id)->get()->keyBy('code');
        $pieza = Unit::where('name', 'Pieza')->first();

        $aguaDeCoco = $products->get('BEB-AC-1L');     // producto objetivo
        $cocoEntero = $products->get('COC-ENT-001');   // ingrediente

        if (!$aguaDeCoco || !$cocoEntero || !$pieza) {
            $this->command->error('Faltan productos base (Agua de Coco / Coco Entero) o la unidad Pieza. Ejecuta ProductsSeeder y UnitsSeeder primero.');
            return;
        }

        $userId = DB::table('users')->value('id');

        $formula = Formula::create([
            'company_id' => $company->id,
            'product_id' => $aguaDeCoco->id,
            'name' => 'Producción de Agua de Coco 1 Litro',
            'description' => 'Fórmula de ejemplo: rinde ~1 L de agua de coco a partir de coco entero (≈0.73 L por coco).',
            'version' => 1,
            'is_active' => true,
            'expected_output_quantity' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        FormulaItem::create([
            'formula_id' => $formula->id,
            'product_id' => $cocoEntero->id,
            'unit_id' => $pieza->id,
            'expected_quantity' => 1.4,
            'sort_order' => 0,
            'notes' => 'Se requieren ~1.4 cocos enteros para obtener 1 L de agua.',
        ]);

        $this->command->info('✅ Creada 1 fórmula de ejemplo: Producción de Agua de Coco 1 Litro');
    }
}
