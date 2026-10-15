<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin\Company;
use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentCompany
{
    /**
     * Rutas que no dependen de la empresa: la sesión en sí no debe caerse
     * porque al usuario le hayan quitado el acceso a una empresa. La app
     * seguirá pudiendo pedir otra empresa en vez de cerrar la sesión.
     */
    private const COMPANY_AGNOSTIC_ROUTES = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/logout-all',
        'api/auth/my-permissions',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Una cuenta desactivada no puede operar aunque conserve un token válido.
        // Este middleware corre antes de auth:sanctum, así que el usuario se
        // resuelve con el guard de sanctum (null en rutas públicas). Solo se
        // bloquea cuando is_active es explícitamente falso: un atributo ausente
        // (p. ej. en pruebas con actingAs) no debe interpretarse como inactivo.
        $authUser = $request->user('sanctum');
        if ($authUser && $authUser->getAttribute('is_active') === false) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada.',
                'code' => 'account_inactive',
            ], 401);
        }

        $companyId = $request->header('X-Company-ID');

        if ($companyId) {
            $company = Company::find($companyId);

            if ($company) {
                // Este middleware corre antes de auth:sanctum, así que el
                // usuario se resuelve con el guard de sanctum. Sin token no hay
                // a quién validar (p. ej. los PDF firmados públicos): la ruta
                // valida el company_id contra el registro.
                $user = $request->user('sanctum');

                // La empresa viene del cliente: sin esta validación un usuario
                // de la empresa A podía mandar el header de la empresa B y
                // operar sobre sus datos. Los administradores del sistema
                // ("Gestión de compañías") pueden seleccionar cualquiera.
                if ($user && !CurrentWorker::canAccessCompany((int) $company->id, $user)) {
                    if ($request->is(...self::COMPANY_AGNOSTIC_ROUTES)) {
                        return $next($request);
                    }

                    return response()->json([
                        'message' => 'No tienes acceso a esta empresa.',
                        'code' => 'company_forbidden',
                    ], 403);
                }

                CurrentCompany::set($company);
            }
        }

        return $next($request);
    }
}
