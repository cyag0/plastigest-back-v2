<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin\Location;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentLocation
{
    /**
     * Rutas que no dependen de la sucursal. La sesión en sí no depende de la
     * sucursal: si /auth/me también fallara, la app cerraría la sesión en vez
     * de pedir otra sucursal (p. ej. cuando se la quitaron al usuario). Lo
     * mismo con lo que la app pide para elegir empresa y sucursal.
     */
    private const LOCATION_AGNOSTIC_ROUTES = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/logout-all',
        'api/auth/change-password',
        'api/auth/admin/companies',
        'api/auth/admin/companies/*',
        'api/auth/admin/device-tokens',
        'api/auth/admin/device-tokens/*',
        'api/auth/admin/client-errors',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locationId = $request->header('X-Location-ID');

        if ($locationId) {
            $location = Location::find($locationId);

            // Este middleware corre antes de auth:sanctum, así que el usuario
            // se resuelve con el guard de sanctum. Sin token no hay a quién
            // validar y la ruta decidirá si exige sesión.
            $user = $request->user('sanctum');

            // La sucursal viene del cliente: sin esta validación un usuario
            // básico podía mandar la de otra sucursal y operar o ver su
            // inventario. canAccessLocation() también exige que sea de la
            // empresa del contexto. Una sucursal que ya no existe tampoco vale:
            // antes se ignoraba y la petición seguía sin sucursal.
            if ($user && (!$location || !CurrentWorker::canAccessLocation((int) $location->id, $user))) {
                if ($request->is(...self::LOCATION_AGNOSTIC_ROUTES)) {
                    return $next($request);
                }

                // `code` le dice a la app que limpie la sucursal guardada y
                // muestre el selector.
                return response()->json([
                    'message' => 'No tienes acceso a esta sucursal.',
                    'code' => 'location_forbidden',
                ], 403);
            }

            // Sin sesión tampoco se fija la sucursal de otra empresa.
            if ($location && CurrentCompany::exists() && (int) $location->company_id !== (int) CurrentCompany::id()) {
                $location = null;
            }

            if ($location) {
                CurrentLocation::set($location);
            }
        }

        return $next($request);
    }
}
