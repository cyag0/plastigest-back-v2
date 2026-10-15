<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\User;
use Database\Seeders\CompaniesSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Volver a correr UserSeeder repetía el rol del Super Admin en users_roles
 * (sin índice único, insertOrIgnore no ignoraba nada), y sin ADMIN_PASSWORD
 * el Super Admin quedaba con la contraseña "password", escrita en el código.
 */
class AccesosUserSeederTest extends TestCase
{
    use RefreshDatabase;

    // Sin ResourceSeeder: su truncate() cierra la transacción de la prueba y
    // obliga a migrar de nuevo la base para la siguiente.

    private function superAdmin(): User
    {
        return User::where('email', 'admin@cocos-francisco.com')->firstOrFail();
    }

    public function test_seeding_again_does_not_repeat_the_super_admin_access(): void
    {
        $this->seed([PermissionSeeder::class, CompaniesSeeder::class, UserSeeder::class]);
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $admin = $this->superAdmin();
        $roleId = DB::table('roles')->where('name', 'Super Admin')->where('is_system', true)->value('id');

        $this->assertSame(1, DB::table('users_roles')->where('user_id', $admin->id)->count());
        $this->assertSame(DB::table('locations')->count(), DB::table('user_location_roles')->where('user_id', $admin->id)->count());
        $this->assertSame(DB::table('companies')->count(), DB::table('user_company')->where('user_id', $admin->id)->count());
        $this->assertSame(DB::table('permissions')->count(), DB::table('rol_permission')->where('role_id', $roleId)->count());
        $this->assertSame(1, User::where('email', $admin->email)->count());
    }

    public function test_a_user_cannot_have_the_same_direct_role_twice(): void
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->insertGetId(['name' => 'Rol', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
    }

    public function test_without_admin_password_the_super_admin_does_not_get_a_known_password(): void
    {
        $this->seed([PermissionSeeder::class, CompaniesSeeder::class, UserSeeder::class]);

        $this->assertFalse(Hash::check('password', $this->superAdmin()->password));

        // Ninguna contraseña escrita en el seeder.
        $source = file_get_contents(database_path('seeders/UserSeeder.php'));
        $this->assertDoesNotMatchRegularExpression('/Hash::make\(\s*[\'"]/', $source);
        $this->assertStringNotContainsString("return 'password'", $source);
    }

    public function test_outside_local_the_super_admin_needs_admin_password(): void
    {
        $this->seed([PermissionSeeder::class, CompaniesSeeder::class]);
        $this->app['env'] = 'production';

        try {
            $this->app->make(UserSeeder::class)->run();
            $this->fail('Se creó el Super Admin sin ADMIN_PASSWORD');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertFalse(User::where('email', 'admin@cocos-francisco.com')->exists());
    }
}
