<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de inventario.
 *
 * Sustituye al enum `products.product_type` (raw_material | processed | commercial),
 * que no alcanzaba para distinguir el material de empaque del resto de la materia
 * prima, ni el granel (lo que sale de una fórmula) del producto ya empacado.
 *
 * Además de la etiqueta, cada tipo carga las reglas de comportamiento
 * (is_purchasable, is_sellable, is_producible, is_consumable). Así los filtros
 * de compras / ventas / producción dejan de depender de listas de códigos
 * escritas a mano en cada pantalla.
 *
 * `company_id = null` = tipo del sistema, visible para todas las empresas.
 * Una empresa puede crear los suyos propios con su `company_id`.
 *
 * Los tipos base se insertan aquí (no en un seeder) porque la columna
 * `products.inventory_type_id` es obligatoria y la migración siguiente los
 * necesita para migrar los productos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('color', 9)->nullable();

            // Reglas de comportamiento: qué se puede hacer con un producto de este tipo.
            $table->boolean('is_purchasable')->default(true);  // se puede comprar a un proveedor
            $table->boolean('is_sellable')->default(true);     // se puede vender / aparece en el POS
            $table->boolean('is_producible')->default(false);  // puede ser salida de una orden de producción
            $table->boolean('is_consumable')->default(false);  // puede ser insumo de una fórmula
            $table->boolean('is_stockable')->default(true);    // se inventaría y entra en los conteos

            $table->boolean('is_system')->default(false);      // los del sistema no se pueden eliminar
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'unique_inventory_type_company_code');
            $table->index(['company_id', 'is_active'], 'idx_inventory_type_company_active');
        });

        $now = now();

        DB::table('inventory_types')->insert(array_map(
            fn(array $type) => array_merge($type, [
                'company_id' => null,
                'is_stockable' => true,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            [
                [
                    'code' => 'raw_material',
                    'name' => 'Materia prima',
                    'description' => 'Se compra y se transforma en producción: coco, azúcar, leche. Algunas también se venden tal cual.',
                    'icon' => 'sprout',
                    'color' => '#2E7D32',
                    'is_purchasable' => true,
                    'is_sellable' => true,
                    'is_producible' => false,
                    'is_consumable' => true,
                    'sort_order' => 10,
                ],
                [
                    'code' => 'packaging_material',
                    'name' => 'Material de empaque',
                    'description' => 'Botellas, tapas, bolsas, etiquetas y cajas. Se consumen al empacar y no se venden solos.',
                    'icon' => 'package-variant-closed',
                    'color' => '#0288D1',
                    'is_purchasable' => true,
                    'is_sellable' => false,
                    'is_producible' => false,
                    'is_consumable' => true,
                    'sort_order' => 20,
                ],
                [
                    'code' => 'semi_finished',
                    'name' => 'Semiterminado (granel)',
                    'description' => 'Ya está elaborado pero todavía no empacado. Sale de una fórmula y entra en otra.',
                    'icon' => 'pot-steam',
                    'color' => '#F9A825',
                    'is_purchasable' => false,
                    'is_sellable' => false,
                    'is_producible' => true,
                    'is_consumable' => true,
                    'sort_order' => 30,
                ],
                [
                    'code' => 'finished_product',
                    'name' => 'Producto terminado',
                    'description' => 'Empacado y listo para vender. Es la salida de la fórmula de empaque.',
                    'icon' => 'package-check',
                    'color' => '#6A1B9A',
                    'is_purchasable' => false,
                    'is_sellable' => true,
                    'is_producible' => true,
                    'is_consumable' => true,
                    'sort_order' => 40,
                ],
                [
                    'code' => 'merchandise',
                    'name' => 'Mercancía de reventa',
                    'description' => 'Se compra terminado y se vende sin transformarlo.',
                    'icon' => 'cart-outline',
                    'color' => '#00796B',
                    'is_purchasable' => true,
                    'is_sellable' => true,
                    'is_producible' => false,
                    'is_consumable' => false,
                    'sort_order' => 50,
                ],
                [
                    'code' => 'supply',
                    'name' => 'Insumo / consumible',
                    'description' => 'No forma parte del producto: limpieza, guantes, gas, papelería.',
                    'icon' => 'spray-bottle',
                    'color' => '#607D8B',
                    'is_purchasable' => true,
                    'is_sellable' => false,
                    'is_producible' => false,
                    'is_consumable' => false,
                    'sort_order' => 60,
                ],
            ]
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_types');
    }
};
