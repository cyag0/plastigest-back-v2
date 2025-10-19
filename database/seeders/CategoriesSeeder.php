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
        // Obtener las compañías existentes
        $jara = Company::where('name', 'Jara')->first();
        $cocosfrancisco = Company::where('name', 'Test Company')->first(); // Usaremos la segunda compañía existente

        if (!$jara) {
            $this->command->error('La compañía Jara no existe. Creando...');

            // Crear la compañía Cocos Francisco si no existe
            $cocosfrancisco = Company::firstOrCreate(
                ['name' => 'Cocos Francisco'],
                [
                    'business_name' => 'Cocos Francisco Distribuidora S.A. de C.V.',
                    'rfc' => 'CFD987654321',
                    'email' => 'ventas@cocosfrancisco.com',
                    'phone' => '+52 55 9876 5432',
                    'address' => 'Carretera Nacional Km 45, Zona Agrícola, Estado de México',
                    'is_active' => true,
                ]
            );
        }

        if (!$cocosfrancisco) {
            // Si no existe la segunda compañía, la creamos
            $cocosfrancisco = Company::firstOrCreate(
                ['name' => 'Cocos Francisco'],
                [
                    'business_name' => 'Cocos Francisco Distribuidora S.A. de C.V.',
                    'rfc' => 'CFD987654321',
                    'email' => 'ventas@cocosfrancisco.com',
                    'phone' => '+52 55 9876 5432',
                    'address' => 'Carretera Nacional Km 45, Zona Agrícola, Estado de México',
                    'is_active' => true,
                ]
            );
        }

        // Categorías para Jara (Productos Plásticos)
        $jaraCategories = [
            [
                'name' => 'Envases Plásticos',
                'description' => 'Envases y contenedores de plástico para diferentes usos industriales y domésticos',
                'company_id' => $jara->id,
                'is_active' => true,
            ],
            [
                'name' => 'Bolsas y Empaques',
                'description' => 'Bolsas plásticas, films y materiales de empaque',
                'company_id' => $jara->id,
                'is_active' => true,
            ],
            [
                'name' => 'Tuberías y Conexiones',
                'description' => 'Tuberías de PVC, conexiones y accesorios para plomería',
                'company_id' => $jara->id,
                'is_active' => true,
            ],
            [
                'name' => 'Artículos de Hogar',
                'description' => 'Productos plásticos para uso doméstico y decorativo',
                'company_id' => $jara->id,
                'is_active' => true,
            ],
            [
                'name' => 'Material Industrial',
                'description' => 'Componentes y materiales plásticos para uso industrial',
                'company_id' => $jara->id,
                'is_active' => true,
            ],
        ];

        // Categorías para Cocos Francisco (Productos de Coco)
        $cocosCategories = [
            [
                'name' => 'Coco Fresco',
                'description' => 'Cocos frescos directos del productor, enteros y sin procesar',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Agua de Coco',
                'description' => 'Agua natural de coco, embotellada y procesada',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Copra y Aceite',
                'description' => 'Copra seca y aceite de coco extraído naturalmente',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Fibra de Coco',
                'description' => 'Fibra natural extraída de la cáscara del coco para diversos usos',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Productos Derivados',
                'description' => 'Harina de coco, leche de coco y otros productos procesados',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
            [
                'name' => 'Artesanías de Coco',
                'description' => 'Productos artesanales elaborados con cáscara y materiales del coco',
                'company_id' => $cocosfrancisco->id,
                'is_active' => true,
            ],
        ];

        // Crear categorías de Jara
        foreach ($jaraCategories as $categoryData) {
            Category::firstOrCreate(
                [
                    'name' => $categoryData['name'],
                    'company_id' => $categoryData['company_id']
                ],
                $categoryData
            );
        }

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
        $this->command->info('- Jara: ' . count($jaraCategories) . ' categorías de productos plásticos');
        $this->command->info('- Cocos Francisco: ' . count($cocosCategories) . ' categorías de productos de coco');
    }
}
