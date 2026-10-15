<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cuadre del libro mayor de existencias contra la existencia de cada
 * producto por sucursal. Solo lee: no corrige nada.
 *
 * Todo cambio de stock pasa por MovementService, que deja un asiento con la
 * existencia de antes y la cantidad. Por eso, para cada producto y sucursal
 * con movimientos:
 *
 *   existencia esperada = existencia antes del primer asiento
 *                         + entradas − salidas
 *
 * tiene que ser igual a product_location.current_stock. La existencia antes
 * del primer asiento cubre el stock que ya había cuando empezó el libro
 * mayor. Una diferencia significa que el stock cambió sin pasar por
 * MovementService (un UPDATE directo, un seeder, una fila repetida) o un
 * error de redondeo: se lista, se registra como error en el log y el comando
 * termina con código 1 para que el monitoreo lo note.
 *
 * Corre cada noche (routes/console.php).
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile
        {--company= : Solo esta empresa (id)}
        {--tolerance=0.001 : Diferencia máxima aceptada por redondeo}';

    protected $description = 'Compara el libro mayor de existencias con la existencia actual por producto y sucursal (solo lectura)';

    /** Renglones que se muestran y se mandan al log. */
    private const REPORT_LIMIT = 50;

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $tolerance = max(0.0, (float) $this->option('tolerance'));

        $ledger = DB::table('stock_ledger_entries')
            ->selectRaw('product_id, location_id, MIN(id) as first_id, COUNT(*) as movements')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as net")
            ->when($companyId, fn($query) => $query->where('company_id', $companyId))
            ->groupBy('product_id', 'location_id');

        $expected = 'first.previous_stock + ledger.net';

        $differences = DB::query()
            ->fromSub($ledger, 'ledger')
            ->join('stock_ledger_entries as first', 'first.id', '=', 'ledger.first_id')
            ->leftJoin('product_location as stock', function ($join) {
                $join->on('stock.product_id', '=', 'ledger.product_id')
                    ->on('stock.location_id', '=', 'ledger.location_id');
            })
            ->leftJoin('products', 'products.id', '=', 'ledger.product_id')
            ->leftJoin('locations', 'locations.id', '=', 'ledger.location_id')
            ->selectRaw('first.company_id, ledger.product_id, ledger.location_id, ledger.movements')
            ->selectRaw('products.name as product_name, locations.name as location_name')
            ->selectRaw("{$expected} as expected_stock")
            ->selectRaw('COALESCE(stock.current_stock, 0) as current_stock')
            ->whereRaw("ABS({$expected} - COALESCE(stock.current_stock, 0)) > ?", [$tolerance])
            ->orderBy('first.company_id')
            ->orderBy('ledger.location_id')
            ->orderBy('ledger.product_id')
            ->get();

        $checked = DB::query()->fromSub($ledger, 'ledger')->count();

        if ($differences->isEmpty()) {
            $this->info("Inventario cuadrado: {$checked} producto(s) por sucursal con movimientos, sin diferencias.");

            return self::SUCCESS;
        }

        $rows = $differences->take(self::REPORT_LIMIT)->map(fn($row) => [
            'company_id' => (int) $row->company_id,
            'location' => $row->location_name ?? "#{$row->location_id}",
            'location_id' => (int) $row->location_id,
            'product' => $row->product_name ?? "#{$row->product_id}",
            'product_id' => (int) $row->product_id,
            'expected_stock' => round((float) $row->expected_stock, 4),
            'current_stock' => round((float) $row->current_stock, 4),
            'difference' => round((float) $row->current_stock - (float) $row->expected_stock, 4),
            'movements' => (int) $row->movements,
        ]);

        $this->error("La existencia no cuadra con el libro mayor en {$differences->count()} de {$checked} producto(s) por sucursal.");
        $this->table(
            ['Empresa', 'Sucursal', 'Producto', 'Esperada', 'Actual', 'Diferencia', 'Movimientos'],
            $rows->map(fn($row) => [
                $row['company_id'], $row['location'], $row['product'],
                $row['expected_stock'], $row['current_stock'], $row['difference'], $row['movements'],
            ])->all()
        );
        if ($differences->count() > self::REPORT_LIMIT) {
            $this->warn('Se muestran las primeras ' . self::REPORT_LIMIT . '.');
        }

        Log::error('Inventario: la existencia no cuadra con el libro mayor', [
            'differences' => $differences->count(),
            'checked' => $checked,
            'company_id' => $companyId,
            'tolerance' => $tolerance,
            'rows' => $rows->all(),
        ]);

        return self::FAILURE;
    }
}
