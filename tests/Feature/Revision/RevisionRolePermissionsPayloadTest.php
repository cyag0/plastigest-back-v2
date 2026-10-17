<?php

namespace Tests\Feature\Revision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * permissions del rol es el mapa del editor: {id_del_permiso: true|false}.
 * Antes solo se pedía "array": una lista de ids [12, 13] se tomaba por sus
 * índices (ids 0 y 1) y sync() reventaba con 500 por la llave foránea. Ahora
 * cualquier forma que no sea ese mapa responde 422 con un mensaje claro y no
 * se crea ni se cambia nada.
 */
class RevisionRolePermissionsPayloadTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const SHAPE = 'Los permisos se mandan como un mapa {id_del_permiso: true|false}, no como una lista de ids.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function ids(array $names): array
    {
        return DB::table('permissions')->whereIn('name', $names)->orderBy('id')->pluck('id')->all();
    }

    private function role(string $name, array $permissionNames): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $this->company->id, 'is_system' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            array_map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id], $this->ids($permissionNames))
        );

        return $roleId;
    }

    private function permissionNames(int $roleId): array
    {
        return DB::table('rol_permission')->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
            ->where('role_id', $roleId)->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    public function test_a_list_of_permission_ids_is_rejected_with_422_when_creating(): void
    {
        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/roles', ['name' => 'Rol con lista', 'permissions' => $this->ids(['sales_pos', 'sales_list'])])
            ->assertStatus(422)
            ->assertJsonPath('errors.permissions.0', self::SHAPE)
            // Forma de CrudController; AppForm muestra "message: primer error".
            ->assertJsonPath('message', 'Error de validación');

        $this->assertDatabaseMissing('roles', ['name' => 'Rol con lista']);
    }

    public function test_a_list_is_rejected_when_updating_and_the_role_keeps_its_permissions(): void
    {
        $roleId = $this->role('Cajero', ['sales_pos']);

        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/roles/{$roleId}", ['name' => 'Cajero', 'permissions' => $this->ids(['sales_list', 'sales_read'])])
            ->assertStatus(422)
            ->assertJsonPath('errors.permissions.0', self::SHAPE);

        $this->assertSame(['sales_pos'], $this->permissionNames($roleId));
    }

    public function test_a_branch_manager_sending_a_list_gets_422_too(): void
    {
        $manager = $this->createBranchUser($this->location, ['roles_list', 'roles_read', 'roles_create', 'sales_pos', 'sales_list']);

        $this->actingInBranch($manager)
            ->postJson('/api/auth/admin/roles', ['name' => 'Rol del gerente', 'permissions' => $this->ids(['sales_pos'])])
            ->assertStatus(422)
            ->assertJsonPath('errors.permissions.0', self::SHAPE);

        $this->assertDatabaseMissing('roles', ['name' => 'Rol del gerente']);
    }

    public function test_other_shapes_that_are_not_the_editor_map_are_rejected(): void
    {
        [$pos] = $this->ids(['sales_pos']);

        foreach ([
            'texto' => ['permissions' => "{$pos}", 'error' => self::SHAPE],
            'valor que no es booleano' => ['permissions' => [$pos => 'si'], 'error' => self::SHAPE],
            '"false" en texto' => ['permissions' => [$pos => 'false'], 'error' => self::SHAPE],
            'llave que no es un id' => ['permissions' => ['ventas' => true], 'error' => self::SHAPE],
            'permiso que no existe' => ['permissions' => [$pos => true, 999999 => true], 'error' => 'Alguno de los permisos enviados no existe.'],
        ] as $case => $data) {
            $this->actingInBranch($this->user)
                ->postJson('/api/auth/admin/roles', ['name' => "Rol {$case}", 'permissions' => $data['permissions']])
                ->assertStatus(422)
                ->assertJsonPath('errors.permissions.0', $data['error']);

            $this->assertDatabaseMissing('roles', ['name' => "Rol {$case}"]);
        }
    }

    public function test_the_editor_map_still_works_in_json_and_multipart(): void
    {
        [$pos, $list, $read] = $this->ids(['sales_pos', 'sales_list', 'sales_read']);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/roles', ['name' => 'Rol JSON', 'permissions' => [$pos => true, $list => false]])
            ->assertCreated();
        $jsonRole = DB::table('roles')->where('name', 'Rol JSON')->value('id');
        $this->assertSame(['sales_pos'], $this->permissionNames($jsonRole));

        // Como lo manda la app (FormData): permissions[id]=1 / permissions[id]=0.
        $this->actingInBranch($this->user)
            ->post('/api/auth/admin/roles', ['name' => 'Rol multipart', 'permissions' => [$pos => '0', $list => '1', $read => '1']], ['Accept' => 'application/json'])
            ->assertCreated();
        $formRole = DB::table('roles')->where('name', 'Rol multipart')->value('id');
        $this->assertSame(['sales_list', 'sales_read'], $this->permissionNames($formRole));

        // Un mapa vacío deja el rol sin permisos (el editor con todo desmarcado).
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/roles/{$formRole}", ['name' => 'Rol multipart', 'permissions' => []])
            ->assertOk();
        $this->assertSame([], $this->permissionNames($formRole));
    }

    public function test_a_repeated_or_too_long_name_is_a_validation_error(): void
    {
        $this->actingInBranch($this->user)->postJson('/api/auth/admin/roles', ['name' => 'Cajero'])->assertCreated();

        // Antes las dos llegaban a MySQL y respondían 500.
        $this->actingInBranch($this->user)->postJson('/api/auth/admin/roles', ['name' => 'Cajero'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe un rol con ese nombre.');
        $this->actingInBranch($this->user)->postJson('/api/auth/admin/roles', ['name' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
        $this->actingInBranch($this->user)->postJson('/api/auth/admin/roles', ['name' => 'Otro', 'description' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');

        // Guardar el mismo rol sin cambiarle el nombre no choca consigo mismo.
        $id = DB::table('roles')->where('name', 'Cajero')->value('id');
        $this->actingInBranch($this->user)->putJson("/api/auth/admin/roles/{$id}", ['name' => 'Cajero', 'description' => 'Cobra en caja'])
            ->assertOk();
    }
}
