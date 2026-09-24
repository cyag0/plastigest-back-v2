<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin\Location;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentLocation
{
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
            if ($location) {
                // Este middleware corre antes de auth:sanctum, así que el
                // usuario se resuelve con el guard de sanctum. Sin token no
                // hay a quién validar y la ruta decidirá si exige sesión.
                $user = $request->user('sanctum');

                // La sucursal viene del cliente: sin esta validación un
                // usuario básico podía mandar la de otra sucursal y operar o
                // ver su inventario.
                if ($user && !CurrentWorker::canAccessLocation((int) $location->id, $user)) {
                    return response()->json([
                        'message' => 'No tienes acceso a esta sucursal.',
                    ], 403);
                }

                CurrentLocation::set($location);
            }
        }

        return $next($request);
    }
}
