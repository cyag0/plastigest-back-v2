<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Admin\Company;
use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $jara = Company::where('name', 'Jara')->first();
        $cocosFrancisco = Company::where('name', 'Cocos Francisco')->first();

        // Obtener categorías
        $plasticCategories = Category::where('company_id', $jara->id)->get();
        $coconutCategories = Category::where('company_id', $cocosFrancisco->id)->get();

        // Productos para Jara (Plásticos)
        $jaraProducts = [
            // Empaques
            ['name' => 'Bolsa Plástica 20x30cm', 'code' => 'BP-2030', 'sale_price' => 0.15, 'purchase_price' => 0.10, 'category_name' => 'Empaques', 'description' => 'Bolsa plástica transparente de polietileno de alta densidad'],
            ['name' => 'Bolsa Plástica 30x40cm', 'code' => 'BP-3040', 'sale_price' => 0.25, 'purchase_price' => 0.18, 'category_name' => 'Empaques', 'description' => 'Bolsa plástica transparente de polietileno de alta densidad'],
            ['name' => 'Bolsa Zip-Lock 15x20cm', 'code' => 'BZ-1520', 'sale_price' => 0.35, 'purchase_price' => 0.25, 'category_name' => 'Empaques', 'description' => 'Bolsa con cierre hermético reutilizable'],

            // Contenedores
            ['name' => 'Contenedor Hermético 1L', 'code' => 'CH-1000', 'sale_price' => 12.50, 'purchase_price' => 8.75, 'category_name' => 'Contenedores', 'description' => 'Contenedor de polipropileno con tapa hermética'],
            ['name' => 'Contenedor Hermético 2L', 'code' => 'CH-2000', 'sale_price' => 18.75, 'purchase_price' => 13.25, 'category_name' => 'Contenedores', 'description' => 'Contenedor de polipropileno con tapa hermética'],
            ['name' => 'Contenedor Apilable 5L', 'code' => 'CA-5000', 'sale_price' => 35.00, 'purchase_price' => 25.00, 'category_name' => 'Contenedores', 'description' => 'Contenedor apilable para almacenamiento'],

            // Bolsas
            ['name' => 'Bolsa Biodegradable 25x35cm', 'code' => 'BB-2535', 'sale_price' => 0.45, 'purchase_price' => 0.32, 'category_name' => 'Bolsas', 'description' => 'Bolsa biodegradable compostable'],
            ['name' => 'Bolsa de Basura 60x80cm', 'code' => 'BB-6080', 'sale_price' => 0.85, 'purchase_price' => 0.60, 'category_name' => 'Bolsas', 'description' => 'Bolsa resistente para residuos domésticos'],

            // Juguetes
            ['name' => 'Bloques de Construcción Set 50pcs', 'code' => 'BC-050', 'sale_price' => 25.99, 'purchase_price' => 18.50, 'category_name' => 'Juguetes', 'description' => 'Set de bloques de construcción educativos'],
            ['name' => 'Pelota Inflable Multicolor', 'code' => 'PI-MC01', 'sale_price' => 8.50, 'purchase_price' => 5.75, 'category_name' => 'Juguetes', 'description' => 'Pelota inflable de PVC no tóxico'],

            // Utensilios
            ['name' => 'Set Cubiertos Reutilizables', 'code' => 'SCR-001', 'sale_price' => 15.25, 'purchase_price' => 10.50, 'category_name' => 'Utensilios', 'description' => 'Set de cubiertos de plástico reutilizable libre de BPA'],
            ['name' => 'Vaso Térmico 500ml', 'code' => 'VT-500', 'sale_price' => 22.00, 'purchase_price' => 15.00, 'category_name' => 'Utensilios', 'description' => 'Vaso térmico con doble pared'],
        ];

        foreach ($jaraProducts as $productData) {
            $category = $plasticCategories->where('name', $productData['category_name'])->first();

            Product::create([
                'name' => $productData['name'],
                'code' => $productData['code'],
                'purchase_price' => $productData['purchase_price'],
                'sale_price' => $productData['sale_price'],
                'description' => $productData['description'],
                'company_id' => $jara->id,
                'category_id' => $category ? $category->id : null,
                'is_active' => true,
            ]);
        }

        // Productos para Cocos Francisco (Productos de coco)
        $cocosProducts = [
            // Fibra de coco
            ['name' => 'Fibra de Coco Premium 1kg', 'code' => 'FC-P1K', 'sale_price' => 8.50, 'purchase_price' => 5.50, 'category_name' => 'Fibra de coco', 'description' => 'Fibra de coco de alta calidad para jardinería'],
            ['name' => 'Fibra de Coco Compacta 500g', 'code' => 'FC-C500', 'sale_price' => 4.75, 'purchase_price' => 3.25, 'category_name' => 'Fibra de coco', 'description' => 'Fibra de coco compactada en bloque'],
            ['name' => 'Sustrato de Fibra de Coco 10L', 'code' => 'SFC-10L', 'sale_price' => 15.25, 'purchase_price' => 10.50, 'category_name' => 'Fibra de coco', 'description' => 'Sustrato orgánico para plantas'],

            // Aceite de coco
            ['name' => 'Aceite de Coco Virgen 500ml', 'code' => 'ACV-500', 'sale_price' => 18.99, 'purchase_price' => 12.75, 'category_name' => 'Aceite de coco', 'description' => 'Aceite de coco virgen prensado en frío'],
            ['name' => 'Aceite de Coco Orgánico 250ml', 'code' => 'ACO-250', 'sale_price' => 12.50, 'purchase_price' => 8.25, 'category_name' => 'Aceite de coco', 'description' => 'Aceite de coco orgánico certificado'],
            ['name' => 'Aceite de Coco Refinado 1L', 'code' => 'ACR-1L', 'sale_price' => 28.75, 'purchase_price' => 20.00, 'category_name' => 'Aceite de coco', 'description' => 'Aceite de coco refinado para cocina'],

            // Agua de coco
            ['name' => 'Agua de Coco Natural 330ml', 'code' => 'ACN-330', 'sale_price' => 2.25, 'purchase_price' => 1.50, 'category_name' => 'Agua de coco', 'description' => 'Agua de coco 100% natural sin conservantes'],
            ['name' => 'Agua de Coco con Pulpa 500ml', 'code' => 'ACP-500', 'sale_price' => 3.50, 'purchase_price' => 2.25, 'category_name' => 'Agua de coco', 'description' => 'Agua de coco con trozos de pulpa fresca'],

            // Productos artesanales
            ['name' => 'Cepillo de Fibra de Coco', 'code' => 'CFC-001', 'sale_price' => 6.25, 'purchase_price' => 4.00, 'category_name' => 'Productos artesanales', 'description' => 'Cepillo ecológico de fibra de coco natural'],
            ['name' => 'Maceta de Fibra de Coco 15cm', 'code' => 'MFC-15', 'sale_price' => 4.50, 'purchase_price' => 3.00, 'category_name' => 'Productos artesanales', 'description' => 'Maceta biodegradable de fibra de coco'],
            ['name' => 'Alfombra de Fibra de Coco 60x40cm', 'code' => 'AFC-6040', 'sale_price' => 25.00, 'purchase_price' => 18.00, 'category_name' => 'Productos artesanales', 'description' => 'Alfombra natural resistente al agua'],

            // Carbón de coco
            ['name' => 'Carbón Activado de Coco 1kg', 'code' => 'CAC-1K', 'sale_price' => 22.00, 'purchase_price' => 15.00, 'category_name' => 'Carbón de coco', 'description' => 'Carbón activado de cáscara de coco para filtración'],
            ['name' => 'Carbón de Coco para BBQ 3kg', 'code' => 'CCB-3K', 'sale_price' => 18.75, 'purchase_price' => 12.50, 'category_name' => 'Carbón de coco', 'description' => 'Carbón ecológico de coco para parrillas'],
        ];

        foreach ($cocosProducts as $productData) {
            $category = $coconutCategories->where('name', $productData['category_name'])->first();

            Product::create([
                'name' => $productData['name'],
                'code' => $productData['code'],
                'purchase_price' => $productData['purchase_price'],
                'sale_price' => $productData['sale_price'],
                'description' => $productData['description'],
                'company_id' => $cocosFrancisco->id,
                'category_id' => $category ? $category->id : null,
                'is_active' => true,
            ]);
        }
    }
}
