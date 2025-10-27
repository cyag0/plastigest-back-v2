<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin\Location;
use App\Support\CurrentLocation;
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
                CurrentLocation::set($location);
            }
        }

        return $next($request);
    }
}
