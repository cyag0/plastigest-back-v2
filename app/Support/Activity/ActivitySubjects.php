<?php

namespace App\Support\Activity;

use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Models\Admin\Role;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use App\Models\Operations\Formula;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\PurchaseV2;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué modelos quedan en la bitácora y cómo se cuentan en español.
 *
 * Claves de cada entrada:
 *  - module:  grupo para filtrar (ventas, caja, compras, inventario…).
 *  - type:    tipo corto que entiende la app para abrir el documento.
 *  - noun:    "la venta", "el producto"… (con artículo, para la frase).
 *  - label:   atributo o closure con el nombre/folio del registro.
 *  - quote:   true si la etiqueta es un nombre (va entre «»), no un folio.
 *  - amount:  atributo con el importe, si lo tiene ("… por $120.00").
 *  - created: verbo o closure con la frase completa al crear.
 *  - status:  [estado nuevo => verbo] o closure($model, $nuevo): ?string.
 *  - skip:    closure($model, $event): bool para no registrar ruido.
 *  - ignore:  campos que no se cuentan al editar.
 */
class ActivitySubjects
{
    public static function all(): array
    {
        return [
            // ── Ventas ──────────────────────────────────────────────────────
            Sale::class => [
                'module' => 'ventas', 'type' => 'sale', 'noun' => 'la venta',
                'label' => 'sale_number', 'amount' => 'total', 'created' => 'Registró',
                'status' => ['cancelled' => 'Canceló'],
            ],
            SalesOrder::class => [
                'module' => 'ventas', 'type' => 'sales_order', 'noun' => 'el pedido',
                'label' => 'order_number', 'amount' => 'total_amount', 'created' => 'Registró',
                'status' => [
                    'preparing' => 'Empezó a preparar', 'in_transit' => 'Envió',
                    'delivered' => 'Entregó', 'cancelled' => 'Canceló',
                ],
            ],

            // ── Caja ────────────────────────────────────────────────────────
            CashRegisterSession::class => [
                'module' => 'caja', 'type' => 'cash_session', 'noun' => 'el turno',
                'label' => fn($m) => $m->cashRegister?->name ?? 'Caja',
                'created' => fn($m) => 'Abrió turno en ' . ($m->cashRegister?->name ?? 'la caja')
                    . ' con fondo de ' . ActivityFormatter::money($m->opening_amount),
                'status' => fn($m, $status) => $status === 'closed'
                    ? 'Cerró el turno de ' . ($m->cashRegister?->name ?? 'la caja')
                        . ': contó ' . ActivityFormatter::money($m->counted_cash)
                        . ((float) $m->difference != 0.0 ? ' (diferencia ' . ActivityFormatter::money($m->difference) . ')' : ' (cuadró)')
                    : null,
                'skip' => fn($m, $event) => $event === 'updated' && !$m->wasChanged('status'),
            ],
            CashMovement::class => [
                'module' => 'caja', 'type' => 'cash_movement', 'noun' => 'el movimiento de caja',
                'label' => 'concept', 'quote' => true,
                'created' => fn($m) => 'Registró un ' . ($m->type === 'expense' ? 'egreso' : 'ingreso')
                    . ' de ' . ActivityFormatter::money($m->amount) . ' en caja: ' . $m->concept,
                // Los que genera el sistema (ventas, compras, gastos) ya cuentan en su documento.
                'skip' => fn($m) => !in_array($m->source_type, [null, '', 'manual'], true),
            ],
            Expense::class => [
                'module' => 'caja', 'type' => 'expense', 'noun' => 'el gasto',
                'label' => fn($m) => $m->description ?: $m->category, 'quote' => true,
                'amount' => 'amount', 'created' => 'Registró',
            ],

            // ── Compras ─────────────────────────────────────────────────────
            PurchaseV2::class => [
                'module' => 'compras', 'type' => 'purchase', 'noun' => 'la compra',
                'label' => 'purchase_number', 'amount' => 'total', 'created' => 'Registró',
                'status' => [
                    'ordered' => 'Confirmó', 'in_transit' => 'Marcó en camino',
                    'received' => 'Recibió', 'cancelled' => 'Canceló',
                ],
                // El borrador se sincroniza en vivo mientras se captura: sólo
                // cuenta cuando sale de borrador.
                'skip' => fn($m, $event) => $event !== 'deleted'
                    && ActivityFormatter::value($m->status) === 'draft',
            ],

            // ── Inventario ──────────────────────────────────────────────────
            InventoryAdjustmentDetail::class => [
                'module' => 'inventario', 'type' => 'adjustment', 'noun' => 'el ajuste',
                'label' => fn($m) => $m->product?->name ?? 'Producto',
                'created' => fn($m) => 'Ajustó ' . ($m->product?->name ?? 'un producto') . ': '
                    . ($m->direction === 'out' ? '−' : '+') . ActivityFormatter::quantity($m->quantity)
                    . ' ' . ($m->unit?->abbreviation ?? $m->product?->unit?->abbreviation ?? '')
                    . ($m->reason_code ? ' (' . $m->reason_code->label() . ')' : ''),
                'skip' => fn($m, $event) => $event === 'updated',
            ],
            InventoryCount::class => [
                'module' => 'inventario', 'type' => 'count', 'noun' => 'el conteo',
                'label' => 'name', 'quote' => true, 'created' => 'Inició',
                'status' => ['counting' => 'Empezó a contar', 'completed' => 'Cerró', 'cancelled' => 'Canceló'],
            ],
            InventoryTransfer::class => [
                'module' => 'transferencias', 'type' => 'transfer', 'noun' => 'la transferencia',
                'label' => 'transfer_number', 'created' => 'Solicitó',
                'status' => [
                    'approved' => 'Aprobó', 'rejected' => 'Rechazó', 'in_transit' => 'Envió',
                    'completed' => 'Recibió', 'cancelled' => 'Canceló',
                ],
            ],

            // ── Producción ──────────────────────────────────────────────────
            ProductionOrder::class => [
                'module' => 'produccion', 'type' => 'production_order', 'noun' => 'la orden de producción',
                'label' => 'folio',
                'status' => [
                    'completed' => 'Completó', 'packaging' => 'Empezó a empacar',
                    'packed' => 'Terminó de empacar', 'cancelled' => 'Canceló',
                ],
            ],
            Formula::class => [
                'module' => 'produccion', 'type' => 'formula', 'noun' => 'la fórmula',
                'label' => 'name', 'quote' => true,
            ],

            // ── Catálogo ────────────────────────────────────────────────────
            Product::class => ['module' => 'catalogo', 'type' => 'product', 'noun' => 'el producto', 'label' => 'name', 'quote' => true],
            ProductPackage::class => ['module' => 'catalogo', 'type' => 'package', 'noun' => 'el paquete', 'label' => 'package_name', 'quote' => true],
            Category::class => ['module' => 'catalogo', 'type' => 'category', 'noun' => 'la categoría', 'label' => 'name', 'quote' => true],
            Supplier::class => ['module' => 'catalogo', 'type' => 'supplier', 'noun' => 'el proveedor', 'label' => 'name', 'quote' => true],
            Customer::class => ['module' => 'catalogo', 'type' => 'customer', 'noun' => 'el cliente', 'label' => 'name', 'quote' => true],
            Unit::class => ['module' => 'catalogo', 'type' => 'unit', 'noun' => 'la unidad', 'label' => 'name', 'quote' => true],

            // ── Administración ──────────────────────────────────────────────
            Location::class => ['module' => 'administracion', 'type' => 'location', 'noun' => 'la sucursal', 'label' => 'name', 'quote' => true],
            CashRegister::class => ['module' => 'administracion', 'type' => 'cash_register', 'noun' => 'la caja', 'label' => 'name', 'quote' => true],
            Company::class => ['module' => 'administracion', 'type' => 'company', 'noun' => 'la empresa', 'label' => 'name', 'quote' => true],
            User::class => ['module' => 'administracion', 'type' => 'user', 'noun' => 'el usuario', 'label' => 'name', 'quote' => true],
            Role::class => ['module' => 'administracion', 'type' => 'role', 'noun' => 'el rol', 'label' => 'name', 'quote' => true],

            // ── Tareas ──────────────────────────────────────────────────────
            Task::class => [
                'module' => 'tareas', 'type' => 'task', 'noun' => 'la tarea',
                'label' => 'title', 'quote' => true,
                'status' => ['in_progress' => 'Inició', 'completed' => 'Completó', 'cancelled' => 'Canceló'],
                // Muchas las crea el sistema (recibir compra, revisar conteo…):
                // la alta manual se registra en TaskController; aquí sólo cuenta
                // cuando alguien la trabaja.
                'skip' => fn($m, $event) => $event === 'created',
                'ignore' => ['due_soon_notified_at', 'overdue_notified_at', 'next_occurrence', 'completed_at', 'started_at'],
            ],
        ];
    }

    public static function for(Model $model): ?array
    {
        return self::all()[$model::class] ?? null;
    }
}
