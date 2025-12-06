<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class InventoryTestSeeder extends Seeder
{
    /**
     * Seed test products with inventory stock for transfer testing.
     */
    public function run(): void
    {
        // Get the first company (assuming it exists)
        $company = Company::first();
        if (!$company) {
            $this->command->error('No company found. Please create a company first.');
            return;
        }

        // Get locations
        $locations = DB::table('locations')->where('company_id', $company->id)->get();
        if ($locations->count() < 2) {
            $this->command->error('Need at least 2 locations for transfer testing.');
            return;
        }

        // Get or create basic category and unit
        $category = Category::firstOrCreate(
            ['name' => 'Productos de Prueba', 'company_id' => $company->id],
            ['description' => 'Categoría para productos de prueba de transferencias']
        );

        // Usar la primera unidad disponible o crear una básica
        $unit = Unit::first();
        if (!$unit) {
            $unit = Unit::create(['name' => 'Unidad']);
        }

        // Test products to create
        $testProducts = [
            [
                'name' => 'Producto A - Prueba Transfer',
                'sku' => 'PROD-A-001',
                'description' => 'Producto para pruebas de transferencia con stock alto',
                'base_cost' => 10.00,
                'sale_price' => 15.00,
                'stock_quantities' => [50, 30, 25] // Stock por ubicación
            ],
            [
                'name' => 'Producto B - Prueba Transfer',
                'sku' => 'PROD-B-002', 
                'description' => 'Producto para pruebas de transferencia con stock medio',
                'base_cost' => 25.00,
                'sale_price' => 40.00,
                'stock_quantities' => [20, 15, 10]
            ],
            [
                'name' => 'Producto C - Prueba Transfer',
                'sku' => 'PROD-C-003',
                'description' => 'Producto para pruebas de transferencia con stock bajo',
                'base_cost' => 5.50,
                'sale_price' => 8.00,
                'stock_quantities' => [8, 5, 3]
            ],
            [
                'name' => 'Producto D - Sin Stock',
                'sku' => 'PROD-D-004',
                'description' => 'Producto sin stock para probar restricciones',
                'base_cost' => 12.00,
                'sale_price' => 18.00,
                'stock_quantities' => [0, 0, 0]
            ]
        ];

        $this->command->info('Creating test products with inventory...');

        foreach ($testProducts as $productData) {
            // Create or update product
            $product = Product::updateOrCreate(
                ['code' => $productData['sku'], 'company_id' => $company->id],
                [
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'purchase_price' => $productData['base_cost'],
                    'sale_price' => $productData['sale_price'],
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'is_active' => true
                ]
            );

            $this->command->info("Created/Updated: {$product->name}");

            // Create stock records for each location
            foreach ($locations as $index => $location) {
                $stockQuantity = $productData['stock_quantities'][$index] ?? 0;
                
                DB::table('product_location_stock')->updateOrInsert(
                    [
                        'company_id' => $company->id,
                        'location_id' => $location->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'current_stock' => $stockQuantity,
                        'reserved_stock' => 0,
                        'minimum_stock' => 5,
                        'maximum_stock' => 100,
                        'average_cost' => $productData['base_cost'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $this->command->info("  - {$location->name}: {$stockQuantity} units");
            }
        }

        $this->command->info('');
        $this->command->info('✅ Test inventory created successfully!');
        $this->command->info('');
        $this->command->info('Products available for transfer testing:');
        
        foreach ($testProducts as $productData) {
            $this->command->info("📦 {$productData['name']} ({$productData['sku']})");
            foreach ($locations as $index => $location) {
                $stock = $productData['stock_quantities'][$index] ?? 0;
                $status = $stock > 0 ? '✅' : '❌';
                $this->command->info("   {$status} {$location->name}: {$stock} units");
            }
        }
        
        $this->command->info('');
        $this->command->info('Now you can test transfers between locations!');
    }
}