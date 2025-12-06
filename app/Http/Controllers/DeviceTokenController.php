<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceTokenController extends Controller
{
    /**
     * Registrar o actualizar token de dispositivo
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'device_type' => 'nullable|string|in:ios,android,web',
            'device_name' => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:50',
        ]);

        $user = Auth::user();

        // Buscar si ya existe el token
        $deviceToken = DeviceToken::where('token', $validated['token'])->first();

        if ($deviceToken) {
            // Actualizar token existente
            $deviceToken->update([
                'user_id' => $user->id,
                'device_type' => $validated['device_type'] ?? $deviceToken->device_type,
                'device_name' => $validated['device_name'] ?? $deviceToken->device_name,
                'app_version' => $validated['app_version'] ?? $deviceToken->app_version,
                'is_active' => true,
                'last_used_at' => now(),
            ]);
        } else {
            // Crear nuevo token
            $deviceToken = DeviceToken::create([
                'user_id' => $user->id,
                'token' => $validated['token'],
                'device_type' => $validated['device_type'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Token registrado exitosamente',
            'data' => $deviceToken,
        ], 201);
    }

    /**
     * Desactivar token de dispositivo
     */
    public function deactivate(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $user = Auth::user();

        $deviceToken = DeviceToken::where('token', $validated['token'])
            ->where('user_id', $user->id)
            ->first();

        if (!$deviceToken) {
            return response()->json([
                'message' => 'Token no encontrado',
            ], 404);
        }

        $deviceToken->deactivate();

        return response()->json([
            'message' => 'Token desactivado exitosamente',
        ]);
    }

    /**
     * Obtener todos los tokens del usuario autenticado
     */
    public function index()
    {
        $user = Auth::user();

        $tokens = DeviceToken::forUser($user->id)
            ->active()
            ->orderBy('last_used_at', 'desc')
            ->get();

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * Eliminar un token específico
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $deviceToken = DeviceToken::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$deviceToken) {
            return response()->json([
                'message' => 'Token no encontrado',
            ], 404);
        }

        $deviceToken->delete();

        return response()->json([
            'message' => 'Token eliminado exitosamente',
        ]);
    }
}
