<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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
        'api/auth/change-password',
        // Lo que la app pide antes de elegir empresa: la lista de empresas
        // (y su administración), el contador de la campana (0 sin empresa) y
        // los tokens push, que son del usuario.
        'api/auth/admin/companies',
        'api/auth/admin/companies/*',
        'api/auth/admin/notifications/unread-count',
        'api/auth/admin/device-tokens',
        'api/auth/admin/device-tokens/*',
        // Reportes de errores de la app: pueden llegar antes de elegir empresa.
        'api/auth/admin/client-errors',
    ];

    /**
     * Rutas que trabajan con datos de una empresa. Sin empresa en el contexto
     * no se ejecutan: los filtros por empresa se saltaban cuando faltaba
     * X-Company-ID y la consulta salía sin aislar.
     */
    private const TENANT_ROUTES = ['api/auth/admin/*'];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Los permisos consultados se reutilizan durante esta petición y se
        // olvidan al terminarla (ver CurrentWorker::beginRequest()).
        CurrentWorker::beginRequest();

        try {
            return $this->resolve($request, $next);
        } finally {
            CurrentWorker::endRequest();
        }
    }

    private function resolve(Request $request, Closure $next): Response
    {
        // Este middleware corre antes de auth:sanctum, así que el usuario se
        // resuelve con el guard de sanctum (null en rutas públicas).
        $authUser = $request->user('sanctum');

        // Una cuenta desactivada no puede operar aunque conserve un token
        // válido. Solo se bloquea cuando is_active es explícitamente falso: un
        // atributo ausente (p. ej. en pruebas con actingAs) no debe
        // interpretarse como inactivo.
        if ($authUser && $authUser->getAttribute('is_active') === false) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada.',
                'code' => 'account_inactive',
            ], 401);
        }

        $companyId = $request->header('X-Company-ID');

        if ($companyId) {
            $company = Company::find($companyId);

            // La empresa viene del cliente: sin esta validación un usuario de
            // la empresa A podía mandar el header de la empresa B y operar
            // sobre sus datos. Los administradores del sistema ("Gestión de
            // compañías") pueden seleccionar cualquiera. Una empresa que ya no
            // existe tampoco vale: antes se ignoraba y la petición seguía sin
            // empresa, es decir, sin aislar.
            //
            // Sin token no hay a quién validar (p. ej. los PDF firmados
            // públicos): la ruta valida el company_id contra el registro.
            if ($authUser && (!$company || !CurrentWorker::canAccessCompany((int) $company->id, $authUser))) {
                if ($request->is(...self::COMPANY_AGNOSTIC_ROUTES)) {
                    return $next($request);
                }

                return response()->json([
                    'message' => 'No tienes acceso a esta empresa.',
                    'code' => 'company_forbidden',
                ], 403);
            }

            if ($company) {
                CurrentCompany::set($company);
            }
        } elseif ($authUser && ($onlyCompany = $this->onlyCompanyOf($authUser))) {
            // Sin header: si el usuario pertenece a una sola empresa no hay
            // nada que elegir y se usa esa.
            CurrentCompany::set($onlyCompany);
        }

        if (
            $authUser
            && !CurrentCompany::exists()
            && $request->is(...self::TENANT_ROUTES)
            && !$request->is(...self::COMPANY_AGNOSTIC_ROUTES)
        ) {
            // `code` le dice a la app que muestre el selector de empresa.
            return response()->json([
                'message' => 'Selecciona una empresa para continuar.',
                'code' => 'company_required',
            ], 403);
        }

        return $next($request);
    }

    /**
     * La empresa del usuario cuando pertenece exactamente a una.
     */
    private function onlyCompanyOf(Authenticatable $user): ?Company
    {
        $companyIds = CurrentWorker::companyIds($user);

        return count($companyIds) === 1 ? Company::find($companyIds[0]) : null;
    }
}
