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
        // Truncar tabla para empezar limpio
        DB::table('resources')->truncate();

        // Insertar todos los recursos del enum
        foreach (Resources::cases() as $resource) {
            DB::table('resources')->insert([
                'key' => $resource->value,
                'name' => $resource->label(),
                'description' => $resource->description(),
                'icon' => $resource->icon(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
