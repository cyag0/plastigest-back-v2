<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Actualizar todos los usuarios a activos
$updated = DB::table('users')->update(['is_active' => true]);

echo "✅ Actualizados {$updated} usuarios a estado activo\n";
