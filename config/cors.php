<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    | Patterns that this package will apply CORS headers to.
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    | HTTP methods that are allowed for CORS requests.
    */
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    | Origins that are allowed to make CORS requests.
    | Para desarrollo, permitir localhost en cualquier puerto.
    */
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:8081'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    | Patterns for dynamic origins (useful for development).
    */
    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
        '/^http:\/\/192\.168\.\d+\.\d+:\d+$/', // Permite cualquier IP local con puerto
        '/^http:\/\/192\.168\.\d+\.\d+$/', // Permite cualquier IP local sin puerto
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    | Headers que el navegador puede enviar en requests CORS.
    | Para desarrollo, permitir todos los headers comunes.
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    | Headers que el navegador puede acceder en la respuesta.
    */
    'exposed_headers' => [
        'Authorization',
        'X-Total-Count',
        'X-Pagination-Count',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    | Tiempo en segundos que el navegador puede cachear la respuesta preflight.
    */
    'max_age' => 86400, // 24 horas

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    | Indica si las cookies/credenciales pueden ser enviadas con requests CORS.
    | Para Sanctum, debe ser true para que funcionen las cookies de sesión.
    */
    'supports_credentials' => true,

];
