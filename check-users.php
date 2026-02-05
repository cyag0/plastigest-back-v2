<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Ver todos los usuarios
$users = DB::table('users')->select('id', 'name', 'email', 'avatar', 'is_active')->get();

echo "Total usuarios: " . $users->count() . "\n\n";

foreach ($users as $user) {
    $status = $user->is_active ? '✅ ACTIVO' : '❌ INACTIVO';
    $avatar = $user->avatar ?? 'sin avatar';
    echo "ID: {$user->id} | {$user->name} | {$status} | Avatar: {$avatar}\n";
}
