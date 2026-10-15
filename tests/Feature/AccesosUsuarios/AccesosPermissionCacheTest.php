<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Los permisos se consultan una vez por petición (antes cada revisión eran 3
 * consultas y una pantalla hacía decenas) y no pasan de una petición a otra.
 */
class AccesosPermissionCacheTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Consultas de la petición que leen las tablas de roles y permisos. */
    private function authorizationQueries(callable $request): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (preg_match('/\b(user_location_roles|users_roles|rol_permission)\b/', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $request();

        return $queries;
    }

    public function test_one_request_reads_roles_and_permissions_once(): void
    {
        $cashier = $this->createBranchUser($this->location, ['sales_list', 'sales_pos', 'inventory_list', 'transfers_list', 'purchases_list']);

        $queries = $this->authorizationQueries(
            fn() => $this->actingInBranch($cashier)->getJson('/api/auth/admin/home/summary')->assertOk()
        );

        // Inicio revisa 7 permisos; antes eran 3 consultas por permiso.
        $this->assertLessThanOrEqual(3, count($queries), implode("\n", $queries));
    }

    public function test_a_permission_change_counts_from_the_next_request(): void
    {
        $user = $this->createBranchUser($this->location, ['sales_pos']);

        $this->actingInBranch($user)->getJson('/api/auth/admin/categories')->assertForbidden();

        $roleId = DB::table('user_location_roles')->where('user_id', $user->id)->value('role_id');
        DB::table('rol_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => DB::table('permissions')->where('name', 'categories_list')->value('id'),
        ]);

        $this->actingInBranch($user)->getJson('/api/auth/admin/categories')->assertOk();

        DB::table('rol_permission')->where('role_id', $roleId)->delete();

        $this->actingInBranch($user)->getJson('/api/auth/admin/categories')->assertForbidden();
    }

    public function test_another_user_in_the_next_request_does_not_inherit_permissions(): void
    {
        $outsider = $this->createBranchUser($this->location, ['sales_pos']);

        $this->actingInBranch($this->user)->getJson('/api/auth/admin/categories')->assertOk();
        $this->actingInBranch($outsider)->getJson('/api/auth/admin/categories')->assertForbidden();
    }

    public function test_outside_a_request_every_call_reads_the_database(): void
    {
        // Consola, colas o código de pruebas: sin petición no hay caché.
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        Sanctum::actingAs($user, ['*']);
        CurrentCompany::set($this->company);
        CurrentLocation::set($this->location);

        $this->assertFalse(CurrentWorker::hasPermission('categories_list'));

        $roleId = DB::table('user_location_roles')->where('user_id', $user->id)->value('role_id');
        DB::table('rol_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => DB::table('permissions')->where('name', 'categories_list')->value('id'),
        ]);

        $this->assertTrue(CurrentWorker::hasPermission('categories_list'));
    }

    public function test_flush_forgets_what_the_request_already_read(): void
    {
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        Sanctum::actingAs($user, ['*']);
        CurrentCompany::set($this->company);
        CurrentLocation::set($this->location);

        CurrentWorker::beginRequest();
        try {
            $this->assertFalse(CurrentWorker::hasPermission('categories_list'));

            $roleId = DB::table('user_location_roles')->where('user_id', $user->id)->value('role_id');
            DB::table('rol_permission')->insert([
                'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('name', 'categories_list')->value('id'),
            ]);

            // Dentro de la petición se reutiliza lo leído...
            $this->assertFalse(CurrentWorker::hasPermission('categories_list'));

            // ...hasta que quien cambió los permisos lo pide.
            CurrentWorker::flush();
            $this->assertTrue(CurrentWorker::hasPermission('categories_list'));
        } finally {
            CurrentWorker::endRequest();
        }
    }
}
