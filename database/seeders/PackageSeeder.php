<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Los paquetes (cajas, bultos, promos) los crean los usuarios en la app
     * con su precio y cantidad reales. Este seeder no inserta nada.
     */
    public function run(): void
    {
        $this->command->info('PackageSeeder: sin datos predefinidos. Los paquetes se crean desde la app.');
    }
}
