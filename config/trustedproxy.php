<?php

/*
|--------------------------------------------------------------------------
| Proxies de confianza
|--------------------------------------------------------------------------
|
| Laravel (TrustProxies) lee esta lista en cada petición. REMOTE_ADDR es el
| salto inmediato: el Traefik de Coolify, que termina el TLS. Los rangos de
| Cloudflare cuentan cuando el dominio de la API pasa por Cloudflare (nube
| naranja): así request()->ip() es el cliente real y no un nodo de
| Cloudflare, que si no compartirían todos los usuarios en los límites de
| intentos del login. Con la API en "DNS only" no cambia nada.
|
| Los rangos son los publicados en https://www.cloudflare.com/ips/
| (consultados el 2026-10-15). Cloudflare los cambia muy rara vez; revísalos
| cada año o si su página anuncia cambios.
|
*/

$cloudflare = [
    // IPv4 (https://www.cloudflare.com/ips-v4)
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    // IPv6 (https://www.cloudflare.com/ips-v6)
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

return [

    'proxies' => array_merge(['REMOTE_ADDR'], $cloudflare),

    // Para RestoreCloudflareClientIp: CF-Connecting-IP solo se cree cuando
    // la petición llegó de verdad desde uno de estos rangos.
    'cloudflare' => $cloudflare,

];
