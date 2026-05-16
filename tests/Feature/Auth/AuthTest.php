<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── Login ────────────────────────────────────────────────────────────────

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'user' => ['id', 'name', 'email'],
            ])
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Credenciales incorrectas');
    }

    public function test_login_fails_with_missing_fields(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    // ─── Me ───────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_unauthenticated_user_cannot_fetch_profile(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Sesión cerrada exitosamente');
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    // ─── Change Password ──────────────────────────────────────────────────────

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'old-password',
            'new_password'          => 'new-password123',
            'new_password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(200);

        // Verify the old token is revoked and new password works
        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'new-password123',
        ])->assertStatus(200);
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'wrong-password',
            'new_password'          => 'new-password123',
            'new_password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_change_password_fails_when_new_passwords_do_not_match(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'old-password',
            'new_password'          => 'new-password123',
            'new_password_confirmation' => 'different-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_change_password_fails_when_new_password_too_short(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'old-password',
            'new_password'          => 'short',
            'new_password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_change_password_requires_authentication(): void
    {
        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'any-password',
            'new_password'          => 'new-password123',
            'new_password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(401);
    }
}
