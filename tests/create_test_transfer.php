#!/usr/bin/env php
<?php

/*
|--------------------------------------------------------------------------
| Test Script - Transferencias de Inventario
|--------------------------------------------------------------------------
| Este script crea datos de prueba y ejecuta un flujo completo de transferencia
*/

echo "🧪 INICIANDO PRUEBAS DE TRANSFERENCIAS\n";
echo str_repeat("=", 60) . "\n\n";

// Paso 1: Verificar ubicaciones
echo "📍 PASO 1: Verificando ubicaciones...\n";
$locations = DB::table('locations')->select('id', 'name', 'is_main')->get();

if ($locations->isEmpty()) {
    echo "❌ No hay ubicaciones. Creando datos de prueba...\n";
    
    $matrizId = DB::table('locations')->insertGetId([
        'company_id' => 1,
        'name' => 'Matriz',
        'is_main' => true,
        'address' => 'Av. Principal 123',
        'phone' => '555-0001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    $sucursalId = DB::table('locations')->insertGetId([
        'company_id' => 1,
        'name' => 'Sucursal Norte',
        'is_main' => false,
        'address' => 'Calle Norte 456',
        'phone' => '555-0002',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    echo "✅ Ubicaciones creadas: Matriz (ID: {$matrizId}), Sucursal Norte (ID: {$sucursalId})\n";
} else {
    echo "✅ Ubicaciones existentes:\n";
    foreach ($locations as $loc) {
        $main = $loc->is_main ? " [MATRIZ]" : "";
        echo "   - {$loc->name} (ID: {$loc->id}){$main}\n";
    }
}

$matriz = DB::table('locations')->where('is_main', true)->first();
$sucursal = DB::table('locations')->where('is_main', false)->first();

if (!$matriz || !$sucursal) {
    echo "❌ ERROR: Se necesitan al menos 2 ubicaciones (1 matriz y 1 sucursal)\n";
    exit(1);
}

echo "\n";

// Paso 2: Verificar productos
echo "📦 PASO 2: Verificando productos...\n";
$products = DB::table('products')->select('id', 'name', 'sku')->take(3)->get();

if ($products->isEmpty()) {
    echo "❌ No hay productos. Por favor, crea productos primero.\n";
    exit(1);
}

echo "✅ Productos disponibles:\n";
foreach ($products as $prod) {
    echo "   - {$prod->name} (ID: {$prod->id}, SKU: {$prod->sku})\n";
}

$producto = $products->first();
echo "\n";

// Paso 3: Agregar stock en la matriz
echo "📊 PASO 3: Verificando/agregando stock en matriz...\n";
$stockMatriz = DB::table('product_location')
    ->where('product_id', $producto->id)
    ->where('location_id', $matriz->id)
    ->first();

if (!$stockMatriz) {
    DB::table('product_location')->insert([
        'product_id' => $producto->id,
        'location_id' => $matriz->id,
        'quantity' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "✅ Stock inicial agregado: 1000 unidades de '{$producto->name}' en Matriz\n";
} else {
    echo "✅ Stock actual: {$stockMatriz->quantity} unidades de '{$producto->name}' en Matriz\n";
}

echo "\n";

// Paso 4: Verificar usuario
echo "👤 PASO 4: Verificando usuario de prueba...\n";
$user = DB::table('users')->first();

if (!$user) {
    echo "❌ No hay usuarios. Por favor, crea un usuario primero.\n";
    exit(1);
}

echo "✅ Usuario de prueba: {$user->name} (ID: {$user->id})\n";
echo "\n";

// Paso 5: Crear transferencia
echo "🔄 PASO 5: Creando transferencia de prueba...\n";

$transferNumber = 'TRANS-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

$transferId = DB::table('inventory_transfers')->insertGetId([
    'company_id' => 1,
    'from_location_id' => $matriz->id,
    'to_location_id' => $sucursal->id,
    'transfer_number' => $transferNumber,
    'status' => 'pending',
    'requested_by' => $user->id,
    'requested_at' => now(),
    'total_cost' => 0,
    'notes' => 'Transferencia de prueba automática',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "✅ Transferencia creada: {$transferNumber} (ID: {$transferId})\n";

// Paso 6: Crear detalle de transferencia
$detailId = DB::table('inventory_transfer_details')->insertGetId([
    'transfer_id' => $transferId,
    'product_id' => $producto->id,
    'quantity_requested' => 100,
    'quantity_shipped' => 0,
    'quantity_received' => 0,
    'unit_cost' => 150.50,
    'total_cost' => 15050.00,
    'notes' => 'Prueba de transferencia',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('inventory_transfers')
    ->where('id', $transferId)
    ->update(['total_cost' => 15050.00]);

echo "✅ Detalle agregado: 100 unidades de '{$producto->name}'\n";
echo "\n";

// Resumen
echo str_repeat("=", 60) . "\n";
echo "✅ DATOS DE PRUEBA CREADOS EXITOSAMENTE\n";
echo str_repeat("=", 60) . "\n\n";

echo "📋 INFORMACIÓN PARA PRUEBAS:\n\n";
echo "Transfer ID: {$transferId}\n";
echo "Transfer Number: {$transferNumber}\n";
echo "Detail ID: {$detailId}\n";
echo "Producto ID: {$producto->id}\n";
echo "Matriz ID: {$matriz->id}\n";
echo "Sucursal ID: {$sucursal->id}\n";
echo "Usuario ID: {$user->id}\n\n";

echo "🔗 PRUEBAS CON cURL:\n\n";

echo "1️⃣ Ver la transferencia:\n";
echo "GET http://localhost/api/auth/admin/inventory-transfers/{$transferId}\n\n";

echo "2️⃣ Aprobar:\n";
echo "POST http://localhost/api/auth/admin/inventory-transfers/{$transferId}/approve\n\n";

echo "3️⃣ Enviar:\n";
echo "POST http://localhost/api/auth/admin/inventory-transfers/{$transferId}/ship\n\n";

echo "4️⃣ Recibir:\n";
echo "POST http://localhost/api/auth/admin/inventory-transfers/{$transferId}/receive\n";
echo "Body: {\"received_quantities\": {\"{$detailId}\": 100}}\n\n";

echo "✅ Ahora puedes probar con Postman o cURL usando estos IDs\n";
