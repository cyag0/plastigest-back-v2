<?php

namespace App\Http\Controllers;

use App\Support\Activity\ActivityLogger;
use App\Models\Admin\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Error;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrar un nuevo usuario
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Usuario registrado exitosamente',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                ]
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Iniciar sesión
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            if (!Auth::attempt($credentials)) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            $user = User::where('email', $credentials['email'])->firstOrFail();

            // Una cuenta desactivada no puede iniciar sesión aunque la
            // contraseña sea correcta. No se emite token ni sesión.
            if (!$user->is_active) {
                return response()->json([
                    'message' => 'Tu cuenta está desactivada. Contacta a un administrador.',
                    'code' => 'account_inactive',
                ], 403);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            ActivityLogger::log('sesion', 'login', 'Inició sesión', $user, $user->name);

            return response()->json([
                'message' => 'Inicio de sesión exitoso',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Cerrar sesión
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revocar el token actual
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Sesión cerrada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cerrar sesión',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Cerrar todas las sesiones
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            // Revocar todos los tokens del usuario y dejar de mandar push a
            // sus dispositivos (si no, seguían recibiendo avisos sin sesión).
            $request->user()->tokens()->delete();
            \App\Models\DeviceToken::where('user_id', $request->user()->id)
                ->update(['is_active' => false]);

            return response()->json([
                'message' => 'Todas las sesiones han sido cerradas'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cerrar las sesiones',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Obtener información del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->load(['companies', 'roles.permissions', 'locationRoles']);

            $roles = $user->roles->map(fn($role) => [
                'id'          => $role->id,
                'name'        => $role->name,
                'description' => $role->description ?? null,
                'is_system'   => $role->is_system ?? false,
            ])->values()->toArray();

            // Un rol desactivado no da permisos (Role::grantsAccess()).
            $permissions = $user->roles->filter(fn(Role $role) => $role->grantsAccess())
                ->flatMap(fn($role) => $role->permissions)
                ->unique('id')
                ->map(fn($p) => [
                    'id'          => $p->id,
                    'name'        => $p->name,
                    'description' => $p->description ?? null,
                    'resource'    => $p->resource ?? null,
                ])->values()->toArray();

            $companies = $user->companies->map(fn($c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'business_name' => $c->business_name ?? null,
            ])->values()->toArray();

            $locationRoles = $user->locationRoles->map(fn($loc) => [
                'location_id'   => $loc->id,
                'location_name' => $loc->name,
                'role_id'       => $loc->pivot->role_id,
                'company_id'    => $loc->company_id,
            ])->values()->toArray();

            return response()->json([
                'user' => [
                    'id'                => $user->id,
                    'name'              => $user->name,
                    'email'             => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at'        => $user->created_at,
                    'updated_at'        => $user->updated_at,
                    'roles'             => $roles,
                    'permissions'       => $permissions,
                    'companies'         => $companies,
                    'location_roles'    => $locationRoles,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener información del usuario',
                'error'   => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Cambiar contraseña
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'current_password' => 'required',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            $user = $request->user();

            // Verificar contraseña actual
            if (!Hash::check($validated['current_password'], $user->password)) {
                // Con `errors` por campo, como cualquier 422, para que la app
                // marque el campo en el formulario.
                return response()->json([
                    'message' => 'La contraseña actual es incorrecta',
                    'errors' => ['current_password' => ['La contraseña actual es incorrecta']],
                ], 422);
            }

            // Actualizar contraseña
            $user->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            // Revocar todos los tokens existentes para forzar re-login, y
            // apagar los push de sus dispositivos: la app ya no puede darlos de
            // baja porque su token quedó revocado.
            $user->tokens()->delete();
            \App\Models\DeviceToken::where('user_id', $user->id)
                ->update(['is_active' => false]);

            return response()->json([
                'message' => 'Contraseña cambiada exitosamente'
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cambiar la contraseña',
                'error' => $this->errorDetail($e)
            ], 500);
        }
    }

    /**
     * Retorna los permisos del usuario autenticado para la sucursal actual.
     * El contexto de sucursal se lee automáticamente del middleware (X-Location-ID).
     */
    public function myPermissions(): JsonResponse
    {
        try {
            $user     = Auth::user();
            $locationId = CurrentLocation::id();

            if (!$user || !$locationId) {
                return response()->json([
                    'permissions' => [],
                    'role'        => null,
                    'company_id'  => CurrentCompany::id(),
                ], 200);
            }

            // Misma resolución que usa el backend al autorizar: un administrador
            // sin rol en esta sucursal hereda los de sus otras sucursales.
            $role = CurrentWorker::role();
            $permissions = CurrentWorker::permissions();

            return response()->json([
                'permissions' => $permissions,
                'role'        => $role ? ['id' => $role->id, 'name' => $role->name] : null,
                'company_id'  => CurrentCompany::id(),
                'location_id' => $locationId,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener permisos',
                'error'   => $this->errorDetail($e),
            ], 500);
        }
    }
}
