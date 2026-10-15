<?php

namespace Tests\Feature\Notifications;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\PurchaseV2;
use App\Models\Reminder;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Services\MovementService;
use App\Services\ReminderNotificationService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Cada aviso se dispara cuando debe, le llega a quien debe y una sola vez.
 * La cola corre en modo sync en las pruebas, así que la bandeja (canal db)
 * refleja lo que vería el usuario.
 */
class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MANAGER_PERMISSIONS = [
        'inventory_manage', 'inventory_list', 'purchases_manage', 'purchases_update', 'purchases_list',
        'transfers_update', 'transfers_list', 'transfers_create', 'tasks_list', 'tasks_update', 'notifications_list',
    ];

    private User $manager;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant(); // $this->user: todos los permisos en $this->location
        Storage::fake('public');

        $this->manager = $this->createUserWithRole($this->location, self::MANAGER_PERMISSIONS);
        $this->cashier = $this->createUserWithRole($this->location, ['sales_pos']);
    }

    private function inbox(User $user, ?string $eventType = null)
    {
        return Notification::where('user_id', $user->id)
            ->where('channel', 'db')
            ->when($eventType, fn($q) => $q->where('event_type', $eventType))
            ->orderBy('id')
            ->get();
    }

    // ─── Stock bajo ──────────────────────────────────────────────────────────

    public function test_low_stock_is_notified_once_when_crossing_the_minimum_and_when_running_out(): void
    {
        $product = $this->createProduct([], 10);
        DB::table('product_location')
            ->where('product_id', $product->id)->where('location_id', $this->location->id)
            ->update(['minimum_stock' => 5]);

        $movements = new MovementService();
        $sell = fn(float $qty) => $movements->decrement($this->location->id, $product->id, $product->unit_id, $qty);

        $sell(3); // 10 → 7: sigue arriba del mínimo
        $this->assertCount(0, $this->inbox($this->manager, 'low_stock'));

        $sell(3); // 7 → 4: cruza el mínimo
        $this->assertCount(1, $this->inbox($this->manager, 'low_stock'));

        $sell(1); // 4 → 3: sigue bajo, no se repite el aviso
        $this->assertCount(1, $this->inbox($this->manager, 'low_stock'));

        $sell(3); // 3 → 0: agotado
        $notes = $this->inbox($this->manager, 'low_stock');
        $this->assertCount(2, $notes);
        $this->assertStringStartsWith('Agotado', $notes->last()->title);
        $this->assertSame('error', $notes->last()->severity);

        // El cajero no administra inventario.
        $this->assertCount(0, $this->inbox($this->cashier, 'low_stock'));
    }

    public function test_low_stock_only_reaches_staff_of_that_branch(): void
    {
        $otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $otherManager = $this->createUserWithRole($otherBranch, self::MANAGER_PERMISSIONS);

        $product = $this->createProduct([], 2);
        (new MovementService())->decrement($this->location->id, $product->id, $product->unit_id, 2);

        $this->assertCount(1, $this->inbox($this->manager, 'low_stock'));
        $this->assertCount(0, $this->inbox($otherManager, 'low_stock'));
    }

    // ─── Ajustes ─────────────────────────────────────────────────────────────

    public function test_inventory_adjustment_notifies_managers_but_not_whoever_made_it(): void
    {
        $product = $this->createProduct([], 20);

        Sanctum::actingAs($this->manager, ['*']);
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-adjustments', [
                'details' => [[
                    'product_id' => $product->id,
                    'direction' => 'out',
                    'quantity' => 3,
                    'unit_id' => $product->unit_id,
                    'reason_code' => 'damage',
                ]],
            ])->assertCreated();

        $this->assertCount(0, $this->inbox($this->manager, 'inventory_adjustment'), 'quien registró no se avisa a sí mismo');
        $notes = $this->inbox($this->user, 'inventory_adjustment');
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('−3', $notes->first()->message);
        $this->assertNotNull($notes->first()->data['adjustment_id'] ?? null);
    }

    // ─── Compras ─────────────────────────────────────────────────────────────

    public function test_purchase_marked_in_transit_notifies_and_assigns_the_receive_task_to_a_manager(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerteros', 'phone' => '3221234567']);
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'PUR-N-1',
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_ORDERED,
            'total' => 100,
            'user_id' => $this->user->id,
        ]);

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/mark-in-transit")
            ->assertOk();

        $this->assertCount(1, $this->inbox($this->manager, 'purchase_update'));
        $this->assertStringContainsString('en camino', $this->inbox($this->manager, 'purchase_update')->first()->title);

        // La tarea "Recibir compra" la puede resolver el encargado, no el cajero.
        $task = Task::where('related_type', PurchaseV2::class)->where('related_id', $purchase->id)->firstOrFail();
        $this->assertNotEquals($this->cashier->id, $task->assigned_to);
        $this->assertContains($task->assigned_to, [$this->manager->id, $this->user->id]);
        $this->assertCount(1, $this->inbox(User::find($task->assigned_to), 'task_event'));
    }

    // ─── Transferencias ──────────────────────────────────────────────────────

    public function test_requester_is_notified_when_the_origin_approves(): void
    {
        $origin = Location::factory()->create(['company_id' => $this->company->id]);
        $originManager = $this->createUserWithRole($origin, self::MANAGER_PERMISSIONS);
        $coco = $this->createProduct();
        $this->setStock($coco, $origin, 30);

        // Pide el encargado de la sucursal destino.
        Sanctum::actingAs($this->manager, ['*']);
        $transferId = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/inventory-transfers', [
                'from_location_id' => $origin->id,
                'to_location_id' => $this->location->id,
                'details' => [['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity_requested' => 5, 'unit_cost' => 10]],
            ])->assertCreated()->json('data.id');
        $transfer = InventoryTransfer::with('details')->findOrFail($transferId);

        // La tarea de aprobar le llega al encargado del origen.
        $this->assertCount(1, $this->inbox($originManager, 'task_event'));

        // Aprueba el origen → aviso a quien pidió.
        Sanctum::actingAs($originManager, ['*']);
        $this->withHeaders($this->tenantHeaders($origin))
            ->postJson("/api/auth/admin/inventory-transfers/{$transferId}/approve", [
                'items' => [['detail_id' => $transfer->details->first()->id, 'quantity_requested' => 5]],
            ])->assertOk();

        $updates = $this->inbox($this->manager, 'transfer_update');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('aprobada', $updates->first()->title);
        $this->assertSame($transferId, (int) $updates->first()->data['transfer_id']);

        // Quien aprobó no recibe su propio aviso.
        $this->assertCount(0, $this->inbox($originManager, 'transfer_update'));
    }

    // ─── Tareas por vencer / vencidas ────────────────────────────────────────

    public function test_due_soon_and_overdue_tasks_are_notified_once_without_changing_their_status(): void
    {
        $dueSoon = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Vence en unas horas', 'type' => 'custom', 'status' => 'pending',
            'assigned_to' => $this->manager->id, 'due_date' => now()->addHours(5),
        ]);
        $overdue = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Ya venció', 'type' => 'custom', 'status' => 'in_progress',
            'assigned_to' => $this->manager->id, 'due_date' => now()->subHours(2),
        ]);
        Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Cerrada', 'type' => 'custom', 'status' => 'completed',
            'assigned_to' => $this->manager->id, 'due_date' => now()->subDay(),
        ]);

        $tasks = app(TaskService::class);
        $this->assertSame(['due_soon' => 1, 'overdue' => 1], $tasks->notifyDueTasks());
        // Segunda corrida (la siguiente hora): no repite.
        $this->assertSame(['due_soon' => 0, 'overdue' => 0], $tasks->notifyDueTasks());

        $subTypes = $this->inbox($this->manager, 'task_event')->pluck('data.sub_type')->all();
        $this->assertEqualsCanonicalizing(['due_soon', 'overdue'], $subTypes);

        // El status no se pisa con "overdue": sigue en proceso y lo indica is_overdue.
        $this->assertSame('in_progress', $overdue->fresh()->status);
        $this->assertTrue($overdue->fresh()->is_overdue);
        $this->assertSame('pending', $dueSoon->fresh()->status);
    }

    public function test_reassigning_a_task_notifies_the_new_assignee_and_resets_due_notices(): void
    {
        $other = $this->createUserWithRole($this->location, self::MANAGER_PERMISSIONS);
        $task = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Limpiar bodega', 'type' => 'custom', 'status' => 'pending',
            'assigned_to' => $this->manager->id, 'due_date' => now()->addHours(3),
            'due_soon_notified_at' => now(),
        ]);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/tasks/{$task->id}", [
                'assigned_to' => $other->id,
                'due_date' => now()->addDays(3)->toDateTimeString(),
            ])->assertOk();

        $this->assertCount(1, $this->inbox($other, 'task_event'));
        $this->assertNull($task->fresh()->due_soon_notified_at, 'con otra fecha el aviso vuelve a aplicar');
    }

    public function test_assignee_without_tasks_update_can_complete_their_task_but_not_others(): void
    {
        $receiver = $this->createUserWithRole($this->location, ['purchases_update', 'purchases_list']);
        $own = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Recibir compra', 'type' => 'receive_purchase', 'status' => 'pending',
            'assigned_to' => $receiver->id, 'assigned_by' => $this->user->id,
        ]);
        $other = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Ajena', 'type' => 'custom', 'status' => 'pending',
            'assigned_to' => $this->manager->id,
        ]);

        Sanctum::actingAs($receiver, ['*']);
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/tasks/{$own->id}/change-status", ['action' => 'complete'])
            ->assertOk();
        $this->assertSame('completed', $own->fresh()->status);
        // Quien la asignó se entera.
        $this->assertCount(1, $this->inbox($this->user, 'task_event'));

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/tasks/{$other->id}/change-status", ['action' => 'complete'])
            ->assertForbidden();
    }

    public function test_recurring_tasks_do_not_multiply(): void
    {
        $template = Task::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id,
            'title' => 'Conteo semanal', 'type' => 'custom', 'status' => 'pending',
            'assigned_to' => $this->manager->id, 'is_recurring' => true,
            'recurrence_frequency' => 'daily', 'next_occurrence' => now()->subMinute(),
        ]);

        $tasks = app(TaskService::class);
        $this->assertSame(1, $tasks->generateDueRecurringTasks());

        // Un día después: sólo la original genera la siguiente (antes también la copia).
        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->assertSame(1, $tasks->generateDueRecurringTasks());

        $this->assertSame(1, Task::where('is_recurring', true)->count());
        $this->assertSame(3, Task::where('title', 'Conteo semanal')->count());
        $this->assertCount(2, $this->inbox($this->manager, 'task_event'), 'cada ocurrencia avisa a su responsable');
        $this->assertTrue($template->fresh()->is_recurring);
    }

    public function test_company_can_turn_task_notifications_off(): void
    {
        NotificationPreference::create(array_merge(
            NotificationPreference::getDefaults()['task_event'],
            ['company_id' => $this->company->id, 'event_type' => 'task_event', 'is_active' => false]
        ));

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/tasks', [
                'title' => 'Revisar refrigerador', 'type' => 'custom',
                'assigned_to' => $this->manager->id, 'location_id' => $this->location->id,
            ])->assertCreated();

        $this->assertCount(0, $this->inbox($this->manager, 'task_event'));
    }

    public function test_email_is_off_by_default_for_frequent_events(): void
    {
        $product = $this->createProduct([], 1);
        (new MovementService())->decrement($this->location->id, $product->id, $product->unit_id, 1);

        $channels = Notification::where('user_id', $this->manager->id)->where('event_type', 'low_stock')->pluck('channel')->all();
        $this->assertContains('db', $channels);
        $this->assertNotContains('email', $channels);
    }

    // ─── Recordatorios ───────────────────────────────────────────────────────

    public function test_reminders_notify_ahead_of_time_and_on_the_day_once_each(): void
    {
        $reminder = Reminder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'user_id' => $this->manager->id,
            'title' => 'Pagar a Huerteros',
            'type' => 'payment',
            'reminder_date' => today()->addDays(2),
            'status' => 'pending',
            'notify_enabled' => true,
            'notify_days_before' => 2,
            'amount' => 1500,
        ]);

        $service = app(ReminderNotificationService::class);

        $this->assertSame(1, $service->notifyDue(), 'entra en la ventana de 2 días');
        $this->assertSame(0, $service->notifyDue(), 'no se repite el mismo día');

        $this->travel(1)->days();
        $this->assertSame(0, $service->notifyDue(), 'misma etapa (anticipación)');

        $this->travel(1)->days();
        $this->assertSame(1, $service->notifyDue(), 'el mismo día del recordatorio');

        $notes = $this->inbox($this->manager, 'reminder');
        $this->assertCount(2, $notes);
        $this->assertStringStartsWith('Hoy:', $notes->last()->title);
        $this->assertSame($reminder->id, (int) $notes->last()->data['reminder_id']);
    }
}
