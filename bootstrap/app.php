<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En producción la app corre detrás de un proxy que termina el TLS
        // (Traefik de Coolify). Sin confiar en él, Laravel ve http:// y genera
        // URLs http:// (imágenes, evidencias, PDFs firmados): contenido mixto
        // en la web y tráfico en claro bloqueado en Android. El contenedor solo
        // es accesible a través del proxy; si nginx da la cara directamente
        // (DEPLOY.md), nginx debe vaciar los X-Forwarded-* entrantes.
        $middleware->trustProxies(at: '*');

        // No hay pantalla de login web: un invitado en la API recibe 401 en
        // JSON en vez de un intento de redirigir a la ruta "login" (500).
        $middleware->redirectGuestsTo(fn(Request $request) => $request->is('api/*') ? null : '/');

        // Habilitar CORS como primera prioridad
        $middleware->web(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Sin EnsureFrontendRequestsAreStateful: la app (web y móvil) se
        // autentica solo con token Bearer, nunca con cookies. Con ese
        // middleware, las peticiones desde un dominio "stateful" pasan por la
        // validación CSRF y todo POST (incluido el login) respondía 419.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\SetCurrentCompany::class,
            \App\Http\Middleware\SetCurrentLocation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API siempre responde JSON, aunque el cliente no mande
        // "Accept: application/json". Sin esto una petición sin token
        // intentaba redirigir a la ruta "login" (que no existe) y daba 500
        // en lugar de 401.
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Personalizar la respuesta 429 (rate limit) en español y devolver
        // los headers X-RateLimit-* + Retry-After al cliente. El front
        // (login.tsx) usa retryAfter para mostrar el contador regresivo.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null; // dejar que el renderer default maneje web
            }

            $headers   = $e->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);
            $limit     = (int) ($headers['X-RateLimit-Limit'] ?? 0);

            return response()->json([
                'message'     => 'Demasiados intentos. Por favor espera un momento antes de intentar de nuevo.',
                'retry_after' => $retryAfter,
                'rate_limit'  => [
                    'limit'     => $limit,
                    'remaining' => 0,
                ],
            ], 429, $headers);
        });
    })->create();
