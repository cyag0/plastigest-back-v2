<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Limpiar toda la base de datos
        $this->command->info('🧹 Limpiando la base de datos...');
        Artisan::call('migrate:fresh');
        $this->command->info('✅ Base de datos limpiada');

        // Ejecutar seeders en orden
        $this->command->info('🌱 Iniciando seeders...');

        $this->call([
            ResourceSeeder::class,
            PermissionSeeder::class,
            CompanyLocationSeeder::class, // Nuevo seeder que crearemos
            UserSeeder::class,
            SupplierSeeder::class, // Nuevo seeder que crearemos
            ProductSeeder::class, // Nuevo seeder que crearemos
            PackageSeeder::class, // Nuevo seeder que crearemos
        ]);

        $this->command->info('✅ Seeders completados exitosamente');
    }
}
