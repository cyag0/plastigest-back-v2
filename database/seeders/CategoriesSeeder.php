<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
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
                'description' => 'Bebidas naturales a base de coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Postres y más',
                'description' => 'Postres, dulces y productos especiales de coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Dulces tradicionales de coco',
                'description' => 'Barritas y dulces tradicionales elaborados con coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Cocadas y más',
                'description' => 'Cocadas y derivados tradicionales',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Coco rallado y derivados',
                'description' => 'Coco rallado, tostado y productos derivados',
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
