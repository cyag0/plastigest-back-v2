<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CompaniesSeeder::class,  // Primero crear compañía y sucursales
            UserSeeder::class,       // Luego usuarios
            CategoriesSeeder::class, // Categorías antes de productos
            ProductsSeeder::class,   // Finalmente productos
        ]);
    }
}
