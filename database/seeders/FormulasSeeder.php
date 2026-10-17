<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Operations\FormulaOutput;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FormulasSeeder — Los procesos de producción de Cocos Francisco.
 *
 * Un proceso (fórmula) dice qué entra y qué sale cada vez que se corre. Lo
 * que sale suele ser granel (agua, pulpa, horchata, tuba, rallado) que después
 * se envasa en sus presentaciones; la receta de cada presentación vive en el
 * producto (ProductsSeeder). Así el material de empaque no se mezcla con los
 * ingredientes.
 *
 * Las cantidades esperadas son por tanda y sirven para medir el rendimiento
 * (litros de agua por coco). Un resultado en 0 es solo de plantilla: aparece
 * en la lista de lo que puede salir, sin rendimiento esperado (los dulces).
 * Son un punto de partida: el dueño las ajusta desde la app.
 *
 * Idempotente: actualiza cada proceso por nombre y reescribe sus renglones.
 */
class FormulasSeeder extends Seeder
{
    public function run(): void
    {
        if (!InitialCatalogSeeder::allows($this)) {
            return;
        }

        $company = Company::where('name', 'Cocos Francisco')->first();
        if (!$company) {
            $this->command->error('No se encontró la compañía Cocos Francisco.');
            return;
        }

        $products = Product::where('company_id', $company->id)->get()->keyBy('code');
        $userId = DB::table('users')->value('id');

        $saved = 0;
        foreach ($this->processes() as $process) {
            $missing = array_diff(
                array_merge(array_keys($process['items']), array_keys($process['outputs'])),
                $products->keys()->all()
            );
            if ($missing) {
                $this->command->warn("  ⚠ {$process['name']}: faltan productos (" . implode(', ', $missing) . '). Ejecuta ProductsSeeder primero.');
                continue;
            }

            $formula = Formula::updateOrCreate(
                ['company_id' => $company->id, 'name' => $process['name']],
                [
                    'description' => $process['description'],
                    'version' => 1,
                    'is_active' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            $formula->items()->delete();
            $order = 0;
            foreach ($process['items'] as $code => $quantity) {
                FormulaItem::create([
                    'formula_id' => $formula->id,
                    'product_id' => $products[$code]->id,
                    'unit_id' => $products[$code]->unit_id,
                    'expected_quantity' => $quantity,
                    'sort_order' => $order++,
                ]);
            }

            $formula->outputs()->delete();
            $order = 0;
            foreach ($process['outputs'] as $code => $quantity) {
                FormulaOutput::create([
                    'formula_id' => $formula->id,
                    'product_id' => $products[$code]->id,
                    'unit_id' => $products[$code]->unit_id,
                    'expected_quantity' => $quantity,
                    'is_primary' => $order === 0,
                    'sort_order' => $order++,
                ]);
            }

            $saved++;
        }

        $this->command->info("✅ {$saved} procesos de producción de Cocos Francisco");
    }

    /**
     * Procesos: el primer ingrediente es el insumo principal (contra él se
     * mide el rendimiento) y el primer resultado, el principal.
     */
    private function processes(): array
    {
        return [
            [
                'name' => 'Abrir cocos',
                'description' => 'Se abren los cocos: sale el agua y la pulpa. El agua se envasa en botellas, vasos y galones; la pulpa se usa en horchata, dulces y rallado.',
                // Por coco: unos 600 ml de agua y 150 g de pulpa.
                'items' => ['COC-ENT-001' => 1],
                'outputs' => ['GRA-AGUA' => 0.6, 'GRA-PULPA' => 0.15],
            ],
            [
                'name' => 'Coco para tomar',
                'description' => 'Cocos que se preparan enteros para tomarse en la sucursal.',
                'items' => ['COC-ENT-001' => 1],
                'outputs' => ['BEB-CT-001' => 1],
            ],
            [
                'name' => 'Horchata',
                'description' => 'Pulpa de coco con azúcar (o sin azúcar) y agua: sale horchata a granel para envasar.',
                // Por kilo de pulpa: unos 4 litros de horchata.
                'items' => ['GRA-PULPA' => 1, 'ING-AZU-KG' => 0.4],
                'outputs' => ['GRA-HORCH' => 4, 'GRA-HORCH-SA' => 0],
            ],
            [
                'name' => 'Dulces',
                'description' => 'Cocadas, barras, duraznitos y demás dulces de coco. Captura solo lo que se hizo.',
                'items' => ['GRA-PULPA' => 1, 'ING-AZU-KG' => 0.5, 'ING-LCL-LATA' => 0, 'ING-NUEZ-KG' => 0, 'DER-RP-001' => 0],
                'outputs' => [
                    'CCD-NZ-1P' => 0, 'CCD-LM-1P' => 0, 'CCD-GR-1P' => 0, 'CCD-MG-1P' => 0, 'CCD-MC-1P' => 0, 'CCD-BL-1P' => 0, 'CCD-HOR-1P' => 0,
                    'BAR-MX-1P' => 0, 'BAR-NZ-CH' => 0, 'BAR-CC-001' => 0, 'BAR-CF-001' => 0, 'BAR-RP-001' => 0, 'BAR-BN-CH' => 0,
                    'BAR-CN-001' => 0, 'BAR-LC-001' => 0, 'BAR-LCN-001' => 0,
                    'DUR-DZ-1P' => 0, 'DUR-LM-1P' => 0, 'DUR-DR-1P' => 0, 'DUR-DL-1P' => 0,
                    'PEL-1P' => 0, 'POS-DL-001' => 0, 'POS-CU-001' => 0, 'POS-CU-GDE' => 0,
                ],
            ],
            [
                'name' => 'Rallado y tostado',
                'description' => 'La pulpa se seca y se ralla o se tuesta; sale a granel para envasar en bolsas y cajas.',
                // Al secarse la pulpa pierde la mitad del peso.
                'items' => ['GRA-PULPA' => 1],
                'outputs' => ['GRA-RAL' => 0.5, 'GRA-TOS' => 0, 'GRA-RAL-NAT' => 0, 'GRA-TOS-NAT' => 0, 'GRA-HOJ' => 0],
            ],
            [
                'name' => 'Tuba',
                'description' => 'La savia de la palma se cuela y se reposa: sale tuba a granel para envasar.',
                'items' => ['ING-SAVIA-L' => 1],
                'outputs' => ['GRA-TUBA' => 0.95],
            ],
        ];
    }
}
