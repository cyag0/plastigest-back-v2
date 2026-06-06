<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Categorías alineadas con los productos del archivo "Productos para DiDi.xlsx".
     */
    public function run(): void
    {
        // Obtener la compañía Cocos Francisco
        $cocosfrancisco = Company::where('name', 'Cocos Francisco')->first();

        if (!$cocosfrancisco) {
            $this->command->error('La compañía Cocos Francisco no existe. Ejecuta CompaniesSeeder primero.');
            return;
        }

        // Categorías para Cocos Francisco (Productos de Coco)
        $cocosCategories = [
            [
                'name' => 'Bebidas',
                'description' => 'Aguas, horchatas, tuba y demás bebidas a base de coco (botellas, vasos, galones)',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Promociones',
                'description' => 'Paquetes y promociones especiales (ej. 2 rompopes, 2 bolsas de coco, 3 horchatas)',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Coco natural',
                'description' => 'Coco en sus distintas presentaciones: entero, partido, seco, destopado, cacheteado, mayorista',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Derivados de coco',
                'description' => 'Aceite, harina, pulpa, copra, flor de coco, mariscoco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Postres y más',
                'description' => 'Rompope, dulce de leche, cuala, polvorín, manzanitas, tostadas',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Cocadas',
                'description' => 'Cocadas por pieza y por caja: nuez, limón, greñuda, mixta, horneada, bola, velita',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Barras',
                'description' => 'Barras y barritas: natural, fresa, pasas, arándano, nuez, leche, mixta, rompope, banderita',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Duraznitos y limoncitos',
                'description' => 'Duraznitos y limoncitos de coco por pieza y por caja',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Pellizcadas y pelizcadas',
                'description' => 'Pellizcadas de coco tradicionales por pieza y por caja',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Dulces tradicionales de coco',
                'description' => 'Galletas, cuala, cocada dominguera y dulces tradicionales de coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Coco rallado y derivados',
                'description' => 'Coco rallado natural, tostado, sin azúcar y azúcar de coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
        ];

        // Crear categorías de Cocos Francisco
        foreach ($cocosCategories as $categoryData) {
            Category::firstOrCreate(
                [
                    'name' => $categoryData['name'],
                    'company_id' => $categoryData['company_id']
                ],
                $categoryData
            );
        }

        $this->command->info('Categorías creadas exitosamente:');
        $this->command->info('- Cocos Francisco: ' . count($cocosCategories) . ' categorías de productos de coco');
    }
}
