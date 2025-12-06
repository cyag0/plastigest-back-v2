<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryTransfer;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

class TestTransferCommand extends Command
{
    protected $signature = 'test:transfer';
    protected $description = 'Crear datos de prueba para transferencias de inventario';

    public function handle()
    {
        $this->info('🧪 INICIANDO PRUEBAS DE TRANSFERENCIAS');
        $this->line(str_repeat('=', 60));
        $this->newLine();

        // Paso 1: Verificar ubicaciones
        $this->info('📍 PASO 1: Verificando ubicaciones...');
        
        $matriz = Location::where('is_main', true)->first();
        $sucursal = Location::where('is_main', false)->first();

        if (!$matriz) {
            $this->warn('No hay matriz. Creando...');
            $matriz = Location::create([
                'company_id' => 1,
                'name' => 'Matriz',
                'is_main' => true,
                'address' => 'Av. Principal 123',
                'phone' => '555-0001',
            ]);
            $this->info("✅ Matriz creada (ID: {$matriz->id})");
        } else {
            $this->info("✅ Matriz: {$matriz->name} (ID: {$matriz->id})");
        }

        if (!$sucursal) {
            $this->warn('No hay sucursal. Creando...');
            $sucursal = Location::create([
                'company_id' => 1,
                'name' => 'Sucursal Norte',
                'is_main' => false,
                'address' => 'Calle Norte 456',
                'phone' => '555-0002',
            ]);
            $this->info("✅ Sucursal creada (ID: {$sucursal->id})");
        } else {
            $this->info("✅ Sucursal: {$sucursal->name} (ID: {$sucursal->id})");
        }

        $this->newLine();

        // Paso 2: Verificar productos
        $this->info('📦 PASO 2: Verificando productos...');
        
        $producto = Product::first();
        
        if (!$producto) {
            $this->error('❌ No hay productos. Por favor, crea productos primero.');
            return 1;
        }

        $this->info("✅ Producto: {$producto->name} (ID: {$producto->id})");
        $this->newLine();

        // Paso 3: Agregar stock en la matriz
        $this->info('📊 PASO 3: Verificando/agregando stock en matriz...');
        
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
            $this->info('✅ Stock inicial: 1000 unidades agregadas');
        } else {
            $this->info("✅ Stock actual: {$stockMatriz->quantity} unidades");
            
            // Asegurar que haya stock suficiente
            if ($stockMatriz->quantity < 100) {
                DB::table('product_location')
                    ->where('product_id', $producto->id)
                    ->where('location_id', $matriz->id)
                    ->update(['quantity' => 1000]);
                $this->warn('⚠️  Stock bajo. Actualizado a 1000 unidades');
            }
        }

        $this->newLine();

        // Paso 4: Verificar usuario
        $this->info('👤 PASO 4: Verificando usuario de prueba...');
        
        $user = User::first();
        
        if (!$user) {
            $this->error('❌ No hay usuarios. Por favor, crea un usuario primero.');
            return 1;
        }

        $this->info("✅ Usuario: {$user->name} (ID: {$user->id})");
        $this->newLine();

        // Paso 5: Crear transferencia
        $this->info('🔄 PASO 5: Creando transferencia de prueba...');

        $transfer = InventoryTransfer::create([
            'company_id' => 1,
            'from_location_id' => $matriz->id,
            'to_location_id' => $sucursal->id,
            'requested_by' => $user->id,
            'notes' => 'Transferencia de prueba automática',
        ]);

        $this->info("✅ Transferencia creada: {$transfer->transfer_number} (ID: {$transfer->id})");

        // Paso 6: Crear detalle
        $detail = DB::table('inventory_transfer_details')->insertGetId([
            'transfer_id' => $transfer->id,
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

        $transfer->update(['total_cost' => 15050.00]);

        $this->info("✅ Detalle agregado: 100 unidades de '{$producto->name}'");
        $this->newLine();

        // Resumen
        $this->line(str_repeat('=', 60));
        $this->info('✅ DATOS DE PRUEBA CREADOS EXITOSAMENTE');
        $this->line(str_repeat('=', 60));
        $this->newLine();

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Transfer ID', $transfer->id],
                ['Transfer Number', $transfer->transfer_number],
                ['Detail ID', $detail],
                ['Producto', "{$producto->name} (ID: {$producto->id})"],
                ['Matriz', "{$matriz->name} (ID: {$matriz->id})"],
                ['Sucursal', "{$sucursal->name} (ID: {$sucursal->id})"],
                ['Usuario', "{$user->name} (ID: {$user->id})"],
            ]
        );

        $this->newLine();
        $this->info('🔗 PRÓXIMOS PASOS:');
        $this->newLine();
        
        $this->line("1️⃣  Aprobar:");
        $this->comment("   php artisan tinker");
        $this->comment("   \$t = App\\Models\\InventoryTransfer::find({$transfer->id});");
        $this->comment("   \$t->approve({$user->id});");
        $this->comment("   echo \$t->status;  // Debe mostrar: approved");
        $this->newLine();

        $this->line("2️⃣  Enviar:");
        $this->comment("   \$t->ship({$user->id});");
        $this->comment("   echo \$t->status;  // Debe mostrar: in_transit");
        $this->newLine();

        $this->line("3️⃣  Verificar stock después de enviar:");
        $this->comment("   DB::table('product_location')");
        $this->comment("     ->where('product_id', {$producto->id})");
        $this->comment("     ->where('location_id', {$matriz->id})");
        $this->comment("     ->value('quantity');  // Debe ser menor (decrementó)");
        $this->newLine();

        $this->line("4️⃣  Recibir:");
        $this->comment("   \$t->receive({$user->id}, [{$detail} => 100]);");
        $this->comment("   echo \$t->status;  // Debe mostrar: completed");
        $this->newLine();

        $this->line("5️⃣  Verificar stock después de recibir:");
        $this->comment("   DB::table('product_location')");
        $this->comment("     ->where('product_id', {$producto->id})");
        $this->comment("     ->where('location_id', {$sucursal->id})");
        $this->comment("     ->value('quantity');  // Debe ser 100 (incrementó)");
        $this->newLine();

        $this->info('✅ ¡Listo para probar!');

        return 0;
    }
}

