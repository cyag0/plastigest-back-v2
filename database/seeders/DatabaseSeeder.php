<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🧹 Limpiando la base de datos...');
        Artisan::call('migrate:fresh');
        $this->command->info('✅ Base de datos limpiada');

        $this->command->info('🌱 Iniciando seeders...');

        $this->call([
            ResourceSeeder::class,
            PermissionSeeder::class,
            UnitsSeeder::class,
            CompaniesSeeder::class,
            UserSeeder::class,
            SupplierSeeder::class,
            CategoriesSeeder::class,
            ProductsSeeder::class,
            PackageSeeder::class,
        ]);

        $this->command->info('✅ Seeders completados exitosamente');
    }
}
