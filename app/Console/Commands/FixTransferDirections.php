<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\InventoryTransfer;
use Illuminate\Support\Facades\DB;

class FixTransferDirections extends Command
{
    protected $signature = 'transfers:fix-directions';
    protected $description = 'Corrige la dirección de las transferencias creadas incorrectamente';

    public function handle()
    {
        $this->info('🔄 Iniciando corrección de direcciones de transferencias...');
        
        DB::beginTransaction();
        
        try {
            // Buscar transferencias que parecen estar al revés
            // (Sucursales solicitando A la matriz en lugar de DE la matriz)
            $incorrectTransfers = InventoryTransfer::with(['fromLocation', 'toLocation'])
                ->whereHas('fromLocation', function($query) {
                    $query->where('name', '!=', 'Matriz Central');
                })
                ->whereHas('toLocation', function($query) {
                    $query->where('name', '=', 'Matriz Central');
                })
                ->get();
                
            $this->info("📦 Transferencias encontradas para corregir: " . $incorrectTransfers->count());
            
            foreach ($incorrectTransfers as $transfer) {
                $fromLocation = $transfer->fromLocation;
                $toLocation = $transfer->toLocation;
                
                $this->line("Corrigiendo transferencia #{$transfer->id}: {$fromLocation->name} → {$toLocation->name}");
                
                // Intercambiar las ubicaciones
                $transfer->from_location_id = $toLocation->id;
                $transfer->to_location_id = $fromLocation->id;
                $transfer->save();
                
                $this->info("✅ Corregida a: {$toLocation->name} → {$fromLocation->name}");
            }
            
            DB::commit();
            $this->info('🎉 Corrección completada exitosamente!');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Error durante la corrección: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}