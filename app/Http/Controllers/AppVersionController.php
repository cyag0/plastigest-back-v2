<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    /**
     * Versiones de la app nativa que acepta la API (config/app.php,
     * native_app). La app compara su versión con min_native_version y, si es
     * menor, muestra la pantalla bloqueante "Actualiza la app". Pública: se
     * consulta antes del login.
     *
     * GET /api/app/version → {min_native_version, latest_native_version}
     */
    public function show(): JsonResponse
    {
        return response()
            ->json([
                'min_native_version' => $this->version('min_version'),
                'latest_native_version' => $this->version('latest_version'),
            ])
            ->header('Cache-Control', 'no-cache, private');
    }

    private function version(string $key): ?string
    {
        $value = trim((string) config("app.native_app.{$key}"));

        return $value === '' ? null : $value;
    }
}
