<?php

namespace App\Console\Commands;

use App\Services\KioskOrderService;
use Illuminate\Console\Command;

/**
 * Vence los pedidos del kiosko que nadie cobró: los que siguen pendientes
 * después de kiosk.order_ttl_minutes (120 por omisión) se cancelan y su
 * mercancía apartada vuelve a estar a la venta.
 *
 * Corre cada 10 minutos (routes/console.php).
 */
class ExpireKioskOrders extends Command
{
    protected $signature = 'kiosk:expire-orders';

    protected $description = 'Cancela los pedidos del kiosko que nadie cobró a tiempo y libera lo apartado';

    public function handle(KioskOrderService $orders): int
    {
        $expired = $orders->expireStale();

        $this->info("Pedidos del kiosko vencidos: {$expired}");

        return self::SUCCESS;
    }
}
