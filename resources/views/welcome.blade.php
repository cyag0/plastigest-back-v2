<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{-- Este repositorio es solo la API: no hay build de assets (Vite) ni
             fuentes externas. La app (web y móvil) vive en plastigest-app-v3. --}}
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                background: #fdfdfc;
                color: #1b1b18;
            }
            main { text-align: center; padding: 1.5rem; }
            h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .5rem; }
            p { margin: 0; color: #706f6c; }
            @media (prefers-color-scheme: dark) {
                body { background: #0a0a0a; color: #ededec; }
                p { color: #a1a09a; }
            }
        </style>
    </head>
    <body>
        <main>
            <h1>{{ config('app.name', 'Laravel') }}</h1>
            <p>API en servicio.</p>
        </main>
    </body>
</html>
