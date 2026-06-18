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
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Minutos de vigencia del código OTP. Debe coincidir con
     * PasswordResetMail::EXPIRATION_MINUTES.
     */
    private const EXPIRATION_MINUTES = 15;

    /**
     * Máximo de intentos de verificación de código antes de bloquear.
     */
    private const MAX_ATTEMPTS = 5;

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

            Mail::to($user->email)->send(new PasswordResetMail($user, $code));

            return $genericResponse;
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error'   => $e->getMessage(),
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
                return response()->json(['message' => $result['message']], 422);
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
                'error'   => $e->getMessage(),
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
                return response()->json(['message' => $result['message']], 422);
            }

            $user = User::where('email', $validated['email'])->firstOrFail();

            $user->update([
                'password' => Hash::make($validated['password']),
            ]);

            // Invalidar el código usado y cerrar todas las sesiones activas.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->tokens()->delete();

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
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Valida el código OTP contra el registro de password_reset_tokens.
     * Incrementa el contador de intentos en cada fallo de código.
     *
     * @return array{ok: bool, message: string}
     */
    private function validateCode(string $email, string $code): array
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record) {
            return ['ok' => false, 'message' => 'El código es inválido o ya expiró.'];
        }

        // Expiración por antigüedad del código.
        if (Carbon::parse($record->created_at)->addMinutes(self::EXPIRATION_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return ['ok' => false, 'message' => 'El código expiró. Solicita uno nuevo.'];
        }

        // Bloqueo por exceso de intentos.
        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Demasiados intentos fallidos. Solicita un código nuevo.'];
        }

        if (!Hash::check($code, $record->token)) {
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->increment('attempts');

            return ['ok' => false, 'message' => 'El código es inválido o ya expiró.'];
        }

        return ['ok' => true, 'message' => 'Código válido.'];
    }
}
