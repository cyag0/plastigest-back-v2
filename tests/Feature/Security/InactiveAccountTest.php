<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las cuentas desactivadas no pueden iniciar sesión ni usar un token previo.
 *
 * Sin CreatesTenant a propósito: ese trait hace Sanctum::actingAs y dejaría la
 * sesión fijada a un usuario, enmascarando la resolución del token Bearer real.
 */
class InactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('code', 'account_inactive');
    }

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_inactive_user_token_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        // Se desactiva la cuenta después de emitir el token.
        $user->update(['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_active_user_token_still_works(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(200);
    }
}
