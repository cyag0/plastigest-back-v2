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
        // Habilitar CORS como primera prioridad
        $middleware->web(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\SetCurrentCompany::class,
            \App\Http\Middleware\SetCurrentLocation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
