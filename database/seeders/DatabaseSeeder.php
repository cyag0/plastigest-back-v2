<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeders de referencia: idempotentes y seguros en cualquier entorno. Es
     * lo mínimo para que una instalación nueva funcione: recursos, permisos,
     * unidades (el motor de inventario convierte con units.factor_to_base) y
     * el rol Super Admin con su usuario (UserSeeder; fuera de local/testing
     * exige ADMIN_PASSWORD). Los tipos de inventario del sistema los crean
     * las migraciones.
     */
    private const REFERENCE_SEEDERS = [
        ResourceSeeder::class,
        PermissionSeeder::class,
        UnitsSeeder::class,
        UserSeeder::class,
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Fuera de local/testing (producción, staging) solo se corren los de
        // referencia. NUNCA CompaniesSeeder (borra empresas y sucursales) ni
        // catálogos o datos demo.
        if (!app()->environment('local', 'testing')) {
            $this->command->info('🌱 Entorno ' . app()->environment() . ': solo seeders de referencia...');

            $this->call(self::REFERENCE_SEEDERS);

            $this->command->info('✅ Seeders de referencia completados');

            return;
        }

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
            FormulasSeeder::class,
        ]);

        $this->command->info('✅ Seeders completados exitosamente');
    }
}
