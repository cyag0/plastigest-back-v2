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
 * FormulasSeeder — Crea las plantillas de fórmulas de la planta de cocos
 * organizadas en 3 niveles:
 *
 *  Nivel 1 (coco crudo → intermedios):
 *    - Coco Entero → Agua de Coco (a granel) + Pulpa de Coco (a granel)
 *
 *  Nivel 2 (intermedios → productos finales):
 *    - Agua de Coco (a granel) → Botella 1/2 LT, Botella 1 LT, Galón 4 LT,
 *                                Vaso mediano, Vaso grande, Horchata base, Tuba base
 *    - Pulpa de Coco (a granel) → Coco rallado 1 KG
 *
 *  Nivel 3 (intermedios / comerciales → dulces y barras):
 *    - Agua de Coco → Horchata 1 LT, Horchata 1/2 LT, Galón de horchata
 *    - Tuba base → Tuba 1/2 LT, Tuba 1 LT, Galón de tuba
 *    - Pulpa de Coco → Cocada de nuez, Barra de coco, Barra mixta
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

        // Resolver productos por código
        $products = Product::where('company_id', $company->id)
            ->get()
            ->keyBy('code');

        $units = Unit::all()->keyBy('name');

        if ($products->isEmpty()) {
            $this->command->error('No hay productos. Ejecuta ProductsSeeder primero.');
            return;
        }

        // Helper: encuentra producto por código
        $p = fn(string $code) => $products->get($code);
        $u = fn(string $name) => $units->get($name)?->id;

        $user = DB::table('users')->first();
        $userId = $user?->id;

        // Definición de fórmulas: [product_code, name, description, items[]]
        $formulas = [
            // ═══════════════ NIVEL 1 ═══════════════
            // Cada fórmula de Nivel 1 produce UN derivado a partir de coco entero.
            // En la UI, el usuario puede capturar UNA sola producción con varios
            // outputs seleccionando primero la fórmula de Agua y luego agregando
            // manualmente la línea de Pulpa, o usando "Duplicar producción".
            [
                'code' => 'INT-AGUA-001',
                'name' => 'Producción de Agua de Coco',
                'description' => 'Rinde aprox. 0.73 L de agua por coco entero. Usar junto con "Producción de Pulpa de Coco" para registrar el lote completo.',
                'is_active' => true,
                'items' => [
                    [
                        'ingredient_code' => 'MP-COCO-001',
                        'unit' => 'Pieza',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 0.73,
                        'notes' => 'Consumo: 1 coco entero. Rendimiento: ~0.73 L de agua.',
                    ],
                ],
            ],
            [
                'code' => 'INT-PULPA-001',
                'name' => 'Producción de Pulpa de Coco',
                'description' => 'Rinde aprox. 0.5 kg de pulpa por coco entero.',
                'is_active' => true,
                'items' => [
                    [
                        'ingredient_code' => 'MP-COCO-001',
                        'unit' => 'Pieza',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 0.5,
                        'notes' => 'Consumo: 1 coco entero. Rendimiento: ~0.5 kg de pulpa.',
                    ],
                ],
            ],

            // ═══════════════ NIVEL 2 - AGUA A GRANEL → BEBIDAS ENVASADAS ═══════════════
            [
                'code' => 'BEB-BC-500',
                'name' => 'Botella de agua de coco 1/2 LT',
                'description' => 'Llenado de botella 500 ml con agua de coco a granel.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.5,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-BC-1L',
                'name' => 'Botella de agua de coco 1 LT',
                'description' => 'Llenado de botella 1 L con agua de coco a granel.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-AC-GAL',
                'name' => 'Galón de agua de coco 4 LT',
                'description' => 'Galón de 4 L con agua de coco a granel.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 4,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-VM-001',
                'name' => 'Vaso de agua de coco mediano',
                'description' => 'Vaso mediano (12 oz) de agua de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.35,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-VG-001',
                'name' => 'Vaso de agua de coco grande',
                'description' => 'Vaso grande (16 oz) de agua de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.5,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-MR-C/A',
                'name' => 'Mariscoco con agua',
                'description' => 'Mariscoco preparado con agua de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-AGUA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.5,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],

            // ═══════════════ NIVEL 2 - PULPA A GRANEL → DERIVADOS ═══════════════
            [
                'code' => 'RAL-CR-1K',
                'name' => 'Coco rallado 1 KG',
                'description' => 'Rallado y empaquetado de 1 kg de pulpa de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-PULPA-001',
                        'unit' => 'Kilogramo',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],

            // ═══════════════ NIVEL 2 - BASES → ENVASADOS ═══════════════
            [
                'code' => 'BEB-HC-1L',
                'name' => 'Botella de horchata 1 LT',
                'description' => 'Botella 1 L de horchata de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-HORCH-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-HC-500',
                'name' => 'Botella de horchata 1/2 LT',
                'description' => 'Botella 500 ml de horchata de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-HORCH-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.5,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-HC-GAL',
                'name' => 'Galón de horchata',
                'description' => 'Galón de 4 L de horchata de coco.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-HORCH-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 4,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-TB-1L',
                'name' => 'Tuba 1 LT',
                'description' => 'Botella 1 L de tuba natural.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-TUBA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 1,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-TB-500',
                'name' => 'Tuba 1/2 LT',
                'description' => 'Botella 500 ml de tuba natural.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-TUBA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 0.5,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BEB-TB-GAL',
                'name' => 'Galón de tuba',
                'description' => 'Galón de 4 L de tuba natural.',
                'items' => [
                    [
                        'ingredient_code' => 'INT-TUBA-001',
                        'unit' => 'Litro',
                        'expected_quantity' => 4,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],

            // ═══════════════ NIVEL 3 - DULCES Y BARRAS ═══════════════
            [
                'code' => 'COC-CN-1P',
                'name' => 'Cocada de nuez 1 pza',
                'description' => 'Cocada de nuez individual (rinde ~10 cocadas por kg de pulpa).',
                'items' => [
                    [
                        'ingredient_code' => 'INT-PULPA-001',
                        'unit' => 'Kilogramo',
                        'expected_quantity' => 0.1,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BAR-BC-001',
                'name' => 'Barra de coco',
                'description' => 'Barra de coco natural (rinde ~20 barras por kg de pulpa).',
                'items' => [
                    [
                        'ingredient_code' => 'INT-PULPA-001',
                        'unit' => 'Kilogramo',
                        'expected_quantity' => 0.05,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
            [
                'code' => 'BAR-BMC-1P',
                'name' => 'Barra mixta chica',
                'description' => 'Barra mixta pequeña (rinde ~25 barras por kg de pulpa).',
                'items' => [
                    [
                        'ingredient_code' => 'INT-PULPA-001',
                        'unit' => 'Kilogramo',
                        'expected_quantity' => 0.04,
                        'expected_output_quantity' => 1,
                    ],
                ],
            ],
        ];

        $created = 0;
        foreach ($formulas as $f) {
            $product = $p($f['code']);
            if (!$product) {
                $this->command->warn("  ⚠ Producto {$f['code']} no encontrado, se omite fórmula {$f['name']}");
                continue;
            }

            $formula = Formula::create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'name' => $f['name'],
                'description' => $f['description'] ?? null,
                'version' => 1,
                'is_active' => $f['is_active'] ?? true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            foreach ($f['items'] as $i => $item) {
                $ingredient = $p($item['ingredient_code']);
                $unitId = $u($item['unit']);

                if (!$ingredient || !$unitId) {
                    $this->command->warn("  ⚠ Ingrediente/unidad faltante en fórmula {$f['name']}");
                    continue;
                }

                FormulaItem::create([
                    'formula_id' => $formula->id,
                    'product_id' => $ingredient->id,
                    'unit_id' => $unitId,
                    'expected_quantity' => $item['expected_quantity'],
                    'expected_output_quantity' => $item['expected_output_quantity'] ?? null,
                    'sort_order' => $i,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $created++;
        }

        $this->command->info("✅ Creadas {$created} fórmulas (Nivel 1: 2, Nivel 2: 9, Nivel 3: 7)");
    }
}
