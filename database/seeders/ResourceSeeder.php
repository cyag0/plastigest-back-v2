<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Enums\Resources;

class ResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Empezar limpio con delete() y no truncate(): en MySQL TRUNCATE
        // confirma la transacción abierta (en las pruebas, la de
        // RefreshDatabase, que entonces vuelve a migrar todo). Nada apunta a
        // resources.id, así que borrar y reinsertar es seguro.
        DB::table('resources')->delete();

        // Insertar todos los recursos del enum
        DB::table('resources')->insert(array_map(fn(Resources $resource) => [
            'key' => $resource->value,
            'name' => $resource->label(),
            'description' => $resource->description(),
            'icon' => $resource->icon(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], Resources::cases()));
    }
}
