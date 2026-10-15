<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Minutos de vigencia del código OTP. Debe coincidir con
     * PasswordResetMail::EXPIRATION_MINUTES.
     */
    private const EXPIRATION_MINUTES = 15;

    /**
     * Máximo de intentos de verificación de cada código.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Máximo de códigos equivocados por correo en FAILURE_WINDOW_SECONDS,
     * sumando todos los códigos que se pidan. Antes cada código nuevo
     * reiniciaba los intentos a 0: pidiendo códigos sin parar se podían
     * probar combinaciones sin límite.
     */
    private const MAX_FAILURES = 10;

    private const FAILURE_WINDOW_SECONDS = 86400;

    /**
     * Un solo mensaje para cualquier código que no sirve (equivocado, vencido,
     * agotado o sin solicitar): mensajes distintos revelaban qué correos
     * tienen cuenta.
     */
    private const INVALID_CODE_MESSAGE = 'El código es inválido o ya expiró. Solicita uno nuevo.';

    /**
     * Paso 1: solicitar el código de recuperación.
     *
     * Siempre responde 200 con un mensaje genérico para evitar la enumeración
     * de correos. Solo envía el código si el usuario existe y está activo.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ]);

            $genericResponse = response()->json([
                'message' => 'Si el correo está registrado, recibirás un código de recuperación en breve.',
            ], 200);

            $user = User::where('email', $validated['email'])
                ->where('is_active', true)
                ->first();

            if (!$user) {
                // Se hace el mismo trabajo de hashing que en el camino exitoso
                // para no revelar por tiempo de respuesta si el correo existe.
                Hash::make(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));
                return $genericResponse;
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token'      => Hash::make($code),
                    'attempts'   => 0,
                    'created_at' => now(),
                ]
            );

            // Encolado: no bloquear la respuesta esperando al SMTP (la respuesta
            // es genérica a propósito, no revela si el correo existe). El queue
            // worker lo entrega; con QUEUE_CONNECTION=sync corre inline.
            Mail::to($user->email)->queue(new PasswordResetMail($user, $code));

            return $genericResponse;
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error'   => $this->errorDetail($e),
            ], 500);
        }
    }

    /**
     * Paso 2: verificar el código antes de pedir la nueva contraseña.
     * Es un paso de UX; resetPassword vuelve a validar el código.
     */
    public function verifyResetCode(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'code'  => 'required|string',
            ]);

            $result = $this->validateCode($validated['email'], $validated['code']);

            if (!$result['ok']) {
                return response()->json(['message' => $result['message']], $result['status']);
            }

            return response()->json([
                'message' => 'Código verificado correctamente.',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error'   => $this->errorDetail($e),
            ], 500);
        }
    }

    /**
     * Paso 3: restablecer la contraseña usando el código.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email'    => 'required|email',
                'code'     => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $result = $this->validateCode($validated['email'], $validated['code']);

            if (!$result['ok']) {
                return response()->json(['message' => $result['message']], $result['status']);
            }

            $user = User::where('email', $validated['email'])->firstOrFail();

            $user->update([
                'password' => Hash::make($validated['password']),
            ]);

            // Invalidar el código usado y cerrar todas las sesiones activas.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->tokens()->delete();
            RateLimiter::clear($this->failuresKey($validated['email']));

            return response()->json([
                'message' => 'Contraseña restablecida exitosamente. Ya puedes iniciar sesión.',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error'   => $this->errorDetail($e),
            ], 500);
        }
    }

    /**
     * Valida el código OTP contra el registro de password_reset_tokens.
     *
     * Cada comparación gasta un intento del código, reservado con un UPDATE
     * condicionado (attempts < MAX_ATTEMPTS) antes de comparar: antes se leía
     * y luego se incrementaba, y peticiones simultáneas se saltaban el límite.
     * Los fallos también se cuentan por correo (failuresKey), sin importar
     * cuántos códigos se pidan.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    private function validateCode(string $email, string $code): array
    {
        $failuresKey = $this->failuresKey($email);

        if (RateLimiter::tooManyAttempts($failuresKey, self::MAX_FAILURES)) {
            return [
                'ok' => false,
                'status' => 429,
                'message' => 'Demasiados intentos fallidos. Intenta de nuevo más tarde.',
            ];
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        // Expiración por antigüedad del código.
        if ($record && Carbon::parse($record->created_at)->addMinutes(self::EXPIRATION_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $record = null;
        }

        $reserved = $record && DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts') === 1;

        if (!$reserved || !Hash::check($code, $record->token)) {
            RateLimiter::hit($failuresKey, self::FAILURE_WINDOW_SECONDS);

            return ['ok' => false, 'status' => 422, 'message' => self::INVALID_CODE_MESSAGE];
        }

        // El código correcto no gasta intentos: verificar y restablecer lo
        // comparan dos veces.
        DB::table('password_reset_tokens')->where('email', $email)->decrement('attempts');

        return ['ok' => true, 'status' => 200, 'message' => 'Código válido.'];
    }

    /**
     * Contador de códigos equivocados del correo (normalizado).
     */
    private function failuresKey(string $email): string
    {
        return 'password-reset-failures:' . sha1(mb_strtolower(trim($email)));
    }
}
