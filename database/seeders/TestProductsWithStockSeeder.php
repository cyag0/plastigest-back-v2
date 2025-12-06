<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Admin\Location;
use App\Models\Admin\Company;
use Illuminate\Support\Facades\DB;

class TestProductsWithStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener la compañía y ubicaciones
        $company = Company::first();
        
        if (!$company) {
            $this->command->error('No hay compañías en la base de datos. Por favor, ejecuta primero los seeders básicos.');
            return;
        }

        // Obtener ubicación principal y sucursales
        $mainLocation = Location::where('company_id', $company->id)
            ->where('is_main', true)
            ->first();
            
        $branchLocation = Location::where('company_id', $company->id)
            ->where('is_main', false)
            ->first();

        if (!$mainLocation) {
            $this->command->error('No hay ubicación principal. Crea al menos una ubicación antes de ejecutar este seeder.');
            return;
        }

        $this->command->info("Creando productos de prueba para la compañía: {$company->name}");
        $this->command->info("Ubicación principal: {$mainLocation->name}");
        if ($branchLocation) {
            $this->command->info("Sucursal: {$branchLocation->name}");
        }

        // Productos de prueba
        $testProducts = [
            [
                'name' => 'Bolsa de Plástico 40x50cm',
                'code' => 'BPL-4050',
                'description' => 'Bolsa de plástico resistente calibre 200',
                'purchase_price' => 5.50,
                'sale_price' => 8.00,
                'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
                'stock_main' => 1000,
                'stock_branch' => 150,
            ],
            [
                'name' => 'Rollo de Polietileno 1.5m',
                'code' => 'RPE-150',
                'description' => 'Rollo de polietileno alta densidad 1.5m ancho',
                'purchase_price' => 450.00,
                'sale_price' => 650.00,
                'product_type' => Product::PRODUCT_TYPE_RAW_MATERIAL,
                'stock_main' => 50,
                'stock_branch' => 5,
            ],
            [
                'name' => 'Película Stretch Industrial',
                'code' => 'FSI-500',
                'description' => 'Película stretch para empaque industrial 500mm',
                'purchase_price' => 180.00,
                'sale_price' => 250.00,
                'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
                'stock_main' => 200,
                'stock_branch' => 25,
            ],
            [
                'name' => 'Bolsa Biodegradable 30x40cm',
                'code' => 'BBD-3040',
                'description' => 'Bolsa biodegradable ecológica',
                'purchase_price' => 12.00,
                'sale_price' => 18.00,
                'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
                'stock_main' => 800,
                'stock_branch' => 100,
            ],
            [
                'name' => 'Lámina de PVC Transparente',
                'code' => 'LPVC-T200',
                'description' => 'Lámina de PVC transparente calibre 200',
                'purchase_price' => 85.00,
                'sale_price' => 120.00,
                'product_type' => Product::PRODUCT_TYPE_RAW_MATERIAL,
                'stock_main' => 300,
                'stock_branch' => 40,
            ],
            [
                'name' => 'Bolsa Ziplock 20x30cm',
                'code' => 'BZL-2030',
                'description' => 'Bolsa con cierre ziplock reutilizable',
                'purchase_price' => 3.50,
                'sale_price' => 6.00,
                'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
                'stock_main' => 2000,
                'stock_branch' => 300,
            ],
            [
                'name' => 'Saco de Rafia 50kg',
                'code' => 'SRF-50',
                'description' => 'Saco de rafia resistente para 50kg',
                'purchase_price' => 8.00,
                'sale_price' => 12.00,
                'product_type' => Product::PRODUCT_TYPE_COMMERCIAL,
                'stock_main' => 500,
                'stock_branch' => 75,
            ],
            [
                'name' => 'Manga de Plástico 60cm',
                'code' => 'MPL-60',
                'description' => 'Manga de plástico flexible 60cm diámetro',
                'purchase_price' => 120.00,
                'sale_price' => 175.00,
                'product_type' => Product::PRODUCT_TYPE_RAW_MATERIAL,
                'stock_main' => 100,
                'stock_branch' => 10,
            ],
        ];

        DB::beginTransaction();
        
        try {
            foreach ($testProducts as $productData) {
                // Extraer stock antes de crear el producto
                $stockMain = $productData['stock_main'];
                $stockBranch = $productData['stock_branch'];
                unset($productData['stock_main'], $productData['stock_branch']);

                // Crear producto
                $product = Product::create([
                    'company_id' => $company->id,
                    'name' => $productData['name'],
                    'code' => $productData['code'],
                    'description' => $productData['description'],
                    'purchase_price' => $productData['purchase_price'],
                    'sale_price' => $productData['sale_price'],
                    'product_type' => $productData['product_type'],
                    'is_active' => true,
                    'for_sale' => true,
                ]);

                // Crear stock en ubicación principal
                DB::table('product_location_stock')->insert([
                    'company_id' => $company->id,
                    'location_id' => $mainLocation->id,
                    'product_id' => $product->id,
                    'current_stock' => $stockMain,
                    'reserved_stock' => 0,
                    'minimum_stock' => $stockMain * 0.2, // 20% del stock como mínimo
                    'maximum_stock' => $stockMain * 2,
                    'average_cost' => $productData['purchase_price'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Crear stock en sucursal si existe
                if ($branchLocation) {
                    DB::table('product_location_stock')->insert([
                        'company_id' => $company->id,
                        'location_id' => $branchLocation->id,
                        'product_id' => $product->id,
                        'current_stock' => $stockBranch,
                        'reserved_stock' => 0,
                        'minimum_stock' => $stockBranch * 0.3,
                        'maximum_stock' => $stockBranch * 3,
                        'average_cost' => $productData['purchase_price'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->command->info("✓ Creado: {$product->name} (Main: {$stockMain}, Branch: {$stockBranch})");
            }

            DB::commit();
            $this->command->info("\n✅ Se crearon " . count($testProducts) . " productos de prueba con existencias");
            $this->command->info("📦 Stock total en {$mainLocation->name}: " . array_sum(array_column($testProducts, 'stock_main')));
            if ($branchLocation) {
                $this->command->info("📦 Stock total en {$branchLocation->name}: " . array_sum(array_column($testProducts, 'stock_branch')));
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("Error al crear productos: " . $e->getMessage());
            throw $e;
        }
    }
}
