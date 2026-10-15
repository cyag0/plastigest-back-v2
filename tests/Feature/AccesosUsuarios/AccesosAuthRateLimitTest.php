<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Las rutas públicas de autenticación compartían un solo contador por IP
 * (throttle:N,1): tres contraseñas mal escritas bloqueaban "Olvidé mi
 * contraseña" y toda una sucursal compartía 5 intentos por minuto. Y cada
 * código de recuperación nuevo reiniciaba los intentos, así que pidiendo
 * códigos se podían probar combinaciones sin límite.
 */
class AccesosAuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID_CODE = 'El código es inválido o ya expiró. Solicita uno nuevo.';

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cashier = User::factory()->create([
            'email' => 'cajera@example.test',
            'password' => Hash::make('Clave-correcta-1'),
            'is_active' => true,
        ]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function login(string $email, string $password = 'equivocada', string $ip = '10.0.0.1')
    {
        return $this->fromIp($ip)->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** Pide un código y devuelve el que se mandó por correo. */
    private function requestCode(string $ip = '10.0.0.1'): string
    {
        $this->fromIp($ip)->postJson('/api/auth/forgot-password', ['email' => $this->cashier->email])->assertOk();

        $code = null;
        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        return $code;
    }

    private function verify(string $code, string $ip = '10.0.0.1')
    {
        return $this->fromIp($ip)->postJson('/api/auth/verify-reset-code', ['email' => $this->cashier->email, 'code' => $code]);
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    // ─── Iniciar sesión ──────────────────────────────────────────────────────

    public function test_failed_logins_lock_that_email_but_not_coworkers_on_the_same_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('CAJERA@example.test ')->assertUnauthorized();
        }

        // Mismo correo (con otras mayúsculas): bloqueado.
        $this->login('cajera@example.test', 'Clave-correcta-1')->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);

        // Otra persona de la misma sucursal (misma IP) entra sin problema.
        $coworker = User::factory()->create(['password' => Hash::make('Otra-clave-123'), 'is_active' => true]);
        $this->login($coworker->email, 'Otra-clave-123')->assertOk();

        // Y "Olvidé mi contraseña" no comparte el contador del login.
        $this->fromIp('10.0.0.1')->postJson('/api/auth/forgot-password', ['email' => $this->cashier->email])->assertOk();
    }

    public function test_one_ip_has_its_own_cap_for_logins(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->login("persona{$i}@example.test")->assertUnauthorized();
        }

        $this->login('otra@example.test')->assertStatus(429);
        $this->login('otra@example.test', 'equivocada', '10.0.0.2')->assertUnauthorized();
    }

    public function test_spreading_guesses_over_many_ips_does_not_multiply_them(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->login($this->cashier->email, 'equivocada', "10.1.0.{$i}")->assertUnauthorized();
        }

        $this->login($this->cashier->email, 'Clave-correcta-1', '10.2.0.1')->assertStatus(429);
    }

    // ─── Recuperar contraseña ────────────────────────────────────────────────

    public function test_codes_per_email_are_capped_from_any_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fromIp("10.0.0.{$i}")->postJson('/api/auth/forgot-password', ['email' => $this->cashier->email])->assertOk();
        }

        $this->fromIp('10.9.9.9')->postJson('/api/auth/forgot-password', ['email' => 'Cajera@Example.test'])->assertStatus(429);
        Mail::assertQueued(PasswordResetMail::class, 3);

        // Otro correo desde la misma IP sí puede pedir el suyo.
        $this->fromIp('10.0.0.1')->postJson('/api/auth/forgot-password', ['email' => 'otro@example.test'])->assertOk();
    }

    public function test_a_new_code_does_not_reset_the_failed_attempts(): void
    {
        // Dos códigos con 5 intentos equivocados cada uno (10 fallos)…
        for ($round = 0; $round < 2; $round++) {
            $code = $this->requestCode();
            for ($i = 0; $i < 5; $i++) {
                $this->verify($this->wrongCode($code))->assertStatus(422)->assertJsonPath('message', self::INVALID_CODE);
            }
            $this->travel(61)->seconds();
        }

        // …y ni el código correcto de una solicitud nueva sirve ya.
        $code = $this->requestCode();
        $this->verify($code)->assertStatus(429)
            ->assertJsonPath('message', 'Demasiados intentos fallidos. Intenta de nuevo más tarde.');
        $this->fromIp('10.0.0.1')->postJson('/api/auth/reset-password', [
            'email' => $this->cashier->email, 'code' => $code,
            'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123',
        ])->assertStatus(429);

        $this->assertTrue(Hash::check('Clave-correcta-1', $this->cashier->fresh()->password));
    }

    public function test_each_code_still_allows_only_five_attempts(): void
    {
        $code = $this->requestCode();
        for ($i = 0; $i < 5; $i++) {
            $this->verify($this->wrongCode($code))->assertStatus(422);
        }
        $this->travel(61)->seconds();

        // Agotado: el correcto tampoco pasa, con el mismo mensaje que un
        // código equivocado (no revela que el correo tiene cuenta).
        $this->verify($code)->assertStatus(422)->assertJsonPath('message', self::INVALID_CODE);
        $this->assertSame(5, (int) DB::table('password_reset_tokens')->where('email', $this->cashier->email)->value('attempts'));
    }

    public function test_unknown_and_expired_codes_answer_the_same(): void
    {
        $this->fromIp('10.0.0.1')->postJson('/api/auth/verify-reset-code', ['email' => 'nadie@example.test', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('message', self::INVALID_CODE);

        $code = $this->requestCode();
        $this->travel(16)->minutes();
        $this->verify($code)->assertStatus(422)->assertJsonPath('message', self::INVALID_CODE);
    }

    public function test_the_right_code_resets_the_password_and_closes_sessions(): void
    {
        $this->cashier->createToken('telefono');
        $code = $this->requestCode();

        // Verificar no gasta intentos: el mismo código sirve para restablecer.
        $this->verify($code)->assertOk();
        $this->assertSame(0, (int) DB::table('password_reset_tokens')->where('email', $this->cashier->email)->value('attempts'));

        $this->fromIp('10.0.0.1')->postJson('/api/auth/reset-password', [
            'email' => $this->cashier->email, 'code' => $code,
            'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('Nueva-clave-123', $this->cashier->fresh()->password));
        $this->assertSame(0, $this->cashier->tokens()->count());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $this->cashier->email)->exists());
    }
}
