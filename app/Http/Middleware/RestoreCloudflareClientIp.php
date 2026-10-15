<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP real del cliente cuando la API pasa por Cloudflare.
 *
 * El Traefik de Coolify, con su configuración por defecto, descarta el
 * X-Forwarded-For que manda Cloudflare y pone el suyo con la IP del nodo de
 * Cloudflare: request()->ip() sería esa IP para todos los usuarios (y el
 * límite de intentos del login, compartido). Cloudflare manda la IP real en
 * CF-Connecting-IP; solo se usa si la petición viene de un rango de
 * Cloudflare (config/trustedproxy.php), así nadie la falsifica pegándole
 * directo al servidor. Si el proxy sí conserva el X-Forwarded-For,
 * TrustProxies ya resolvió el cliente real y esto no hace nada.
 */
class RestoreCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $connectingIp = $request->headers->get('CF-Connecting-IP');

        if ($connectingIp !== null
            && filter_var($connectingIp, FILTER_VALIDATE_IP)
            && IpUtils::checkIp((string) $request->ip(), (array) config('trustedproxy.cloudflare', []))
        ) {
            // Todos los saltos de X-Forwarded-For son proxies de confianza
            // (si no, ip() no sería de Cloudflare): el cliente va al inicio
            // de la cadena y TrustProxies lo resuelve. No se toca
            // REMOTE_ADDR: es el proxy del que se aceptan X-Forwarded-Proto
            // y Host (las URLs https://).
            $chain = $request->headers->get('X-Forwarded-For');
            $request->headers->set('X-Forwarded-For', $chain ? "{$connectingIp}, {$chain}" : $connectingIp);
        }

        return $next($request);
    }
}
