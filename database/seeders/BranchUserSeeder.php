<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BranchUserSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario para Sucursal Sur
        DB::table('users')->insert([
            'name' => 'Encargado Sucursal Sur',
            'email' => 'sucursal.sur@plastigest.com',
            'password' => Hash::make('password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Usuario para Sucursal Norte
        DB::table('users')->insert([
            'name' => 'Encargado Sucursal Norte',
            'email' => 'sucursal.norte@plastigest.com',
            'password' => Hash::make('password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo "✅ Usuarios de sucursales creados:\n";
        echo "   - sucursal.sur@plastigest.com (password: password123)\n";
        echo "   - sucursal.norte@plastigest.com (password: password123)\n";
    }
}
