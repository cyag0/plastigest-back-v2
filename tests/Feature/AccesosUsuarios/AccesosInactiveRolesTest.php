<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Http\Controllers\CashClosingController;
use App\Models\Admin\Location;
use App\Models\CashClosing;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Services\RecipientResolver;
use App\Services\MovementService;
use App\Services\TaskService;
use App\Support\CurrentWorker;
use App\Support\SystemAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Un rol desactivado ya no da sus permisos. Antes solo dejaba de salir en el
 * selector de roles y quien lo tenía seguía pudiendo todo. Ahora cuenta como
 * si no existiera: sin acceso, sin sus avisos ni sus tareas, hasta que se
 * vuelve a activar. El rol del sistema (Super Admin) siempre cuenta, y nadie
 * desactiva un rol que él mismo tiene.
 */
class AccesosInactiveRolesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const ROLES_URL = '/api/auth/admin/roles';
    private const CATEGORIES_URL = '/api/auth/admin/categories';

    protected function setUp(): void
    {
        parent::setUp();
        // $this->user: todos los permisos (system_admin incluido) en $this->location.
        $this->createTenant();
    }

    /** El rol que el usuario tiene en la sucursal. */
    private function branchRoleOf(User $user, ?Location $location = null): int
    {
        return (int) DB::table('user_location_roles')
            ->where('user_id', $user->id)
            ->where('location_id', ($location ?? $this->location)->id)
            ->value('role_id');
    }

    private function setActive(int $roleId, bool $active): void
    {
        DB::table('roles')->where('id', $roleId)->update(['is_active' => $active]);
    }

    /** Rol con los permisos dados (por nombre). */
    private function role(string $name, array $permissions, bool $system = false, bool $active = true): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name,
            'company_id' => $system ? null : $this->company->id,
            'is_system' => $system,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rol_permission')->insert(
            DB::table('permissions')->whereIn('name', $permissions)->pluck('id')
                ->map(fn($permissionId) => ['role_id' => $roleId, 'permission_id' => $permissionId])
                ->all()
        );

        return $roleId;
    }

    private function giveDirectRole(User $user, int $roleId): void
    {
        DB::table('users_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
    }

    private function roleName(int $roleId): string
    {
        return (string) DB::table('roles')->where('id', $roleId)->value('name');
    }

    // ─── Quitar y devolver el acceso ─────────────────────────────────────────

    public function test_an_inactive_role_gives_none_of_its_permissions_until_it_is_reactivated(): void
    {
        $cashier = $this->createBranchUser($this->location, ['categories_list']);
        $roleId = $this->branchRoleOf($cashier);

        $this->actingInBranch($cashier)->getJson(self::CATEGORIES_URL)->assertOk();

        $this->setActive($roleId, false);

        $this->actingInBranch($cashier)->getJson(self::CATEGORIES_URL)->assertForbidden();
        // La app tampoco le muestra nada: sin permisos y sin rol en la sucursal.
        $this->actingInBranch($cashier)->getJson('/api/auth/my-permissions')
            ->assertOk()
            ->assertJsonPath('permissions', [])
            ->assertJsonPath('role', null);

        $this->setActive($roleId, true);

        $this->actingInBranch($cashier)->getJson(self::CATEGORIES_URL)->assertOk();
        $this->actingInBranch($cashier)->getJson('/api/auth/my-permissions')
            ->assertOk()
            ->assertJsonPath('permissions', ['categories_list'])
            ->assertJsonPath('role.id', $roleId);
    }

    public function test_deactivating_from_the_roles_screen_takes_effect_and_reactivating_gives_it_back(): void
    {
        $cashier = $this->createBranchUser($this->location, ['categories_list']);
        $roleId = $this->branchRoleOf($cashier);
        $name = $this->roleName($roleId);

        $this->actingInBranch($this->user)
            ->putJson(self::ROLES_URL . "/{$roleId}", ['name' => $name, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingInBranch($cashier)->getJson(self::CATEGORIES_URL)->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['description' => "Desactivó el rol «{$name}»"]);

        $this->actingInBranch($this->user)
            ->putJson(self::ROLES_URL . "/{$roleId}", ['name' => $name, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->actingInBranch($cashier)->getJson(self::CATEGORIES_URL)->assertOk();
    }

    public function test_an_inactive_direct_role_gives_nothing_either(): void
    {
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        $catalog = $this->role('Catálogo', ['categories_list']);
        $this->giveDirectRole($user, $catalog);

        $this->actingInBranch($user)->getJson(self::CATEGORIES_URL)->assertOk();

        $this->setActive($catalog, false);

        $this->actingInBranch($user)->getJson(self::CATEGORIES_URL)->assertForbidden();
        $this->actingInBranch($user)->getJson('/api/auth/my-permissions')
            ->assertOk()->assertJsonPath('permissions', ['sales_pos']);
        // /auth/me tampoco cuenta los permisos del rol desactivado.
        $names = collect($this->actingInBranch($user)->getJson('/api/auth/me')->assertOk()->json('user.permissions'))
            ->pluck('name');
        $this->assertNotContains('categories_list', $names);
    }

    public function test_an_inactive_role_neither_opens_every_branch_nor_makes_a_system_admin(): void
    {
        $other = $this->createBranch();
        $owner = $this->createUserWithRole($this->location, ['locations_all', 'system_admin', 'categories_list']);
        $this->assertTrue(CurrentWorker::isSystemAdmin($owner));
        $this->assertTrue(CurrentWorker::canAccessAllLocations($owner));

        $this->setActive($this->branchRoleOf($owner), false);

        $this->assertFalse(CurrentWorker::isSystemAdmin($owner));
        $this->assertFalse(CurrentWorker::canAccessAllLocations($owner));
        $this->actingInBranch($owner, $other)->getJson(self::CATEGORIES_URL)
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');
    }

    public function test_the_system_role_always_counts_even_if_it_was_marked_inactive(): void
    {
        Mail::fake();
        $superAdmin = $this->role('Super Admin', DB::table('permissions')->pluck('name')->all(), system: true, active: false);
        $owner = User::factory()->create();
        $this->giveDirectRole($owner, $superAdmin);
        DB::table('user_company')->insert(['user_id' => $owner->id, 'company_id' => $this->company->id]);
        DB::table('user_location_roles')->insert([
            'user_id' => $owner->id, 'location_id' => $this->location->id, 'role_id' => $superAdmin,
        ]);

        $this->assertTrue(CurrentWorker::isSystemAdmin($owner));
        $this->actingInBranch($owner)->getJson(self::CATEGORIES_URL)->assertOk();

        // Y le siguen llegando las alertas del sistema.
        app(SystemAlerts::class)->raise('test:inactive-system-role', 'Falla de prueba', 'Algo falló.');
        $this->assertSame(1, Notification::where('user_id', $owner->id)
            ->where('event_type', SystemAlerts::EVENT_TYPE)->count());
    }

    // ─── Avisos, tareas y listas ─────────────────────────────────────────────

    public function test_notifications_and_the_recipient_picker_ignore_an_inactive_role(): void
    {
        $active = $this->createUserWithRole($this->location, ['inventory_manage']);
        $inactive = $this->createUserWithRole($this->location, ['inventory_manage']);
        $this->setActive($this->branchRoleOf($inactive), false);
        // En la sucursal es cajero; el permiso le llegaba por un rol directo.
        $direct = $this->createUserWithRole($this->location, ['sales_pos']);
        $this->giveDirectRole($direct, $this->role('Inventario', ['inventory_manage'], active: false));

        $resolved = app(RecipientResolver::class)
            ->resolve($this->company->id, 'low_stock', 'inventory_manage', null, $this->location->id)
            ->pluck('id');
        $this->assertContains($active->id, $resolved);
        $this->assertNotContains($inactive->id, $resolved);
        $this->assertNotContains($direct->id, $resolved);

        $eligible = collect($this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/notification-preferences/low_stock/eligible-users')
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertContains($active->id, $eligible);
        $this->assertNotContains($inactive->id, $eligible);
        $this->assertNotContains($direct->id, $eligible);

        // De punta a punta: el producto se agota y solo avisa a quien sí tiene el permiso.
        $product = $this->createProduct([], 2);
        (new MovementService())->decrement($this->location->id, $product->id, $product->unit_id, 2);

        $inbox = fn(User $user) => Notification::where('user_id', $user->id)
            ->where('event_type', 'low_stock')->where('channel', 'db')->count();
        $this->assertSame(1, $inbox($active));
        $this->assertSame(0, $inbox($inactive));
        $this->assertSame(0, $inbox($direct));
    }

    public function test_tasks_are_not_assigned_through_an_inactive_role(): void
    {
        $branch = $this->createBranch();
        $stale = $this->createUserWithRole($branch, ['purchases_update', 'tasks_list', 'tasks_update']);
        $this->setActive($this->branchRoleOf($stale, $branch), false);

        $task = Task::create([
            'company_id' => $this->company->id,
            'location_id' => $branch->id,
            'title' => 'Recibir compra de prueba',
            'type' => 'receive_purchase',
            'status' => 'pending',
            'priority' => 'high',
            'due_date' => now()->addDay(),
        ]);

        app(TaskService::class)->assignAndNotify($task);

        // Nadie más en esa sucursal: recae en quien tiene el permiso en la
        // empresa (el administrador), no en el del rol desactivado.
        $this->assertSame($this->user->id, (int) $task->fresh()->assigned_to);
    }

    public function test_the_cash_closing_email_skips_an_inactive_role(): void
    {
        $active = $this->createUserWithRole($this->location, ['cash_movements_create']);
        $inactive = $this->createUserWithRole($this->location, ['cash_movements_create']);
        $this->setActive($this->branchRoleOf($inactive), false);

        $closing = new CashClosing();
        $closing->forceFill(['location_id' => $this->location->id, 'user_id' => $this->user->id]);

        $recipients = (fn() => $this->resolveClosingRecipients($closing))
            ->call(app(CashClosingController::class));

        $this->assertContains($active->email, $recipients);
        $this->assertNotContains($inactive->email, $recipients);
    }

    public function test_the_users_list_does_not_treat_an_inactive_admin_role_as_system_admin(): void
    {
        $former = $this->createUserWithRole($this->location, ['system_admin', 'sales_pos']);
        $this->setActive($this->branchRoleOf($former), false);

        $rows = collect($this->actingInBranch($this->user)->getJson('/api/auth/admin/users?scope=location')
            ->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($rows[$former->id]['is_system_admin']);
        $this->assertTrue($rows[$this->user->id]['is_system_admin']);

        // Ya no es administrador del sistema: un encargado lo ve en su lista.
        $manager = $this->createBranchUser($this->location, ['users_list', 'users_read', 'users_update', 'sales_pos']);
        $ids = collect($this->actingInBranch($manager)->getJson('/api/auth/admin/users?scope=location')
            ->assertOk()->json('data'))->pluck('id');
        $this->assertContains($former->id, $ids);
    }

    public function test_a_new_branch_only_adds_admins_whose_role_is_active(): void
    {
        $current = User::factory()->create();
        $this->giveDirectRole($current, $this->role('Dueño', ['system_admin']));
        $former = User::factory()->create();
        $this->giveDirectRole($former, $this->role('Dueño anterior', ['system_admin'], active: false));
        foreach ([$current, $former] as $user) {
            DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $this->company->id]);
        }

        $branchId = $this->actingInBranch($this->user)->postJson('/api/auth/admin/locations', [
            'name' => 'Sucursal Nueva', 'address' => 'Av. México 120, Puerto Vallarta', 'company_id' => $this->company->id,
        ])->assertCreated()->json('data.id');

        $joined = DB::table('user_location_roles')->where('location_id', $branchId)->pluck('user_id');
        $this->assertContains($current->id, $joined);
        $this->assertNotContains($former->id, $joined);
    }

    // ─── Desactivar desde el editor de roles ─────────────────────────────────

    public function test_nobody_deactivates_a_role_they_hold(): void
    {
        $manager = $this->createBranchUser($this->location, ['roles_list', 'roles_read', 'roles_update', 'sales_pos']);
        $roleId = $this->branchRoleOf($manager);

        $this->actingInBranch($manager)
            ->putJson(self::ROLES_URL . "/{$roleId}", ['name' => $this->roleName($roleId), 'is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes desactivar un rol que tú tienes: te quedarías sin sus permisos.');
        $this->assertTrue((bool) DB::table('roles')->where('id', $roleId)->value('is_active'));

        // Tampoco el administrador del sistema, ni por un rol directo.
        $direct = $this->role('Supervisor', ['categories_list']);
        $this->giveDirectRole($this->user, $direct);
        $this->actingInBranch($this->user)
            ->putJson(self::ROLES_URL . "/{$direct}", ['name' => 'Supervisor', 'is_active' => false])
            ->assertStatus(422);
        $this->assertTrue((bool) DB::table('roles')->where('id', $direct)->value('is_active'));

        // Editar su rol sin desactivarlo sigue igual.
        $this->actingInBranch($manager)
            ->putJson(self::ROLES_URL . "/{$roleId}", ['name' => 'Encargado', 'is_active' => true])
            ->assertOk();
    }

    public function test_the_system_role_cannot_be_deactivated(): void
    {
        $superAdmin = $this->role('Super Admin', DB::table('permissions')->pluck('name')->all(), system: true);

        $this->actingInBranch($this->user)
            ->putJson(self::ROLES_URL . "/{$superAdmin}", ['name' => 'Super Admin', 'is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El rol del sistema no se puede modificar.');
        $this->assertTrue((bool) DB::table('roles')->where('id', $superAdmin)->value('is_active'));
    }

    public function test_a_manager_cannot_reactivate_a_role_with_permissions_they_do_not_have(): void
    {
        $manager = $this->createBranchUser($this->location, ['roles_list', 'roles_read', 'roles_update', 'sales_pos']);
        // El dueño desactivó el rol del gerente, que el encargado también tenía.
        $gerente = $this->role('Gerente', ['sales_pos', 'categories_list'], active: false);
        $this->giveDirectRole($manager, $gerente);

        $this->actingInBranch($manager)
            ->putJson(self::ROLES_URL . "/{$gerente}", ['name' => 'Gerente', 'is_active' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes activar un rol con permisos que tú no tienes.');
        $this->assertFalse((bool) DB::table('roles')->where('id', $gerente)->value('is_active'));
        $this->actingInBranch($manager)->getJson(self::CATEGORIES_URL)->assertForbidden();

        // Uno con permisos que sí tiene, sí.
        $cajero = $this->role('Cajero', ['sales_pos'], active: false);
        $this->actingInBranch($manager)
            ->putJson(self::ROLES_URL . "/{$cajero}", ['name' => 'Cajero', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    // ─── Cuántas personas lo tienen ──────────────────────────────────────────

    public function test_the_roles_api_says_how_many_people_have_each_role(): void
    {
        $other = $this->createBranch();
        $cashierRole = $this->role('Cajero', ['sales_pos']);
        $emptyRole = $this->role('Sin asignar', ['sales_pos']);

        // Una en la sucursal, otra en dos sucursales y otra directo y en una
        // sucursal: tres personas, cada una una vez.
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        DB::table('user_location_roles')->insert([
            ['user_id' => $a->id, 'location_id' => $this->location->id, 'role_id' => $cashierRole],
            ['user_id' => $b->id, 'location_id' => $this->location->id, 'role_id' => $cashierRole],
            ['user_id' => $b->id, 'location_id' => $other->id, 'role_id' => $cashierRole],
            ['user_id' => $c->id, 'location_id' => $other->id, 'role_id' => $cashierRole],
        ]);
        $this->giveDirectRole($c, $cashierRole);

        $rows = collect($this->actingInBranch($this->user)->getJson(self::ROLES_URL . '?per_page=50')
            ->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(3, $rows[$cashierRole]['users_count']);
        $this->assertSame(0, $rows[$emptyRole]['users_count']);
        $this->assertSame(1, $rows[$this->branchRoleOf($this->user)]['users_count']);

        $this->actingInBranch($this->user)->getJson(self::ROLES_URL . "/{$cashierRole}")
            ->assertOk()
            ->assertJsonPath('data.users_count', 3)
            ->assertJsonPath('data.held_by_current_user', false);

        // El suyo lo marca, para que la app no ofrezca desactivarlo.
        $this->actingInBranch($this->user)->getJson(self::ROLES_URL . '/' . $this->branchRoleOf($this->user))
            ->assertOk()
            ->assertJsonPath('data.held_by_current_user', true);

        // Eliminar cuenta a cada persona una vez (antes c contaba doble).
        $this->actingInBranch($this->user)->deleteJson(self::ROLES_URL . "/{$cashierRole}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar: 3 usuarios tienen este rol. Asígnales otro rol primero.');
    }

    public function test_an_inactive_role_is_not_assigned_but_a_held_one_does_not_block_saving(): void
    {
        $inactive = $this->role('Temporada', ['sales_pos'], active: false);
        $user = $this->createBranchUser($this->location, ['sales_pos']);

        // Asignarlo dejaba a la persona sin acceso sin que nadie lo notara.
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/users/{$user->id}", ['location_role_id' => $inactive])
            ->assertStatus(422)
            ->assertJsonPath('code', 'inactive_role')
            ->assertJsonPath('message', 'El rol «Temporada» está desactivado: actívalo en Roles antes de asignarlo.');

        // Si ya lo tenía (se desactivó después), se puede guardar lo demás.
        DB::table('user_location_roles')->where('user_id', $user->id)->where('location_id', $this->location->id)
            ->update(['role_id' => $inactive]);
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/users/{$user->id}", ['name' => 'Nombre nuevo', 'location_role_id' => $inactive])
            ->assertOk();
        $this->assertSame('Nombre nuevo', $user->fresh()->name);
    }
}
