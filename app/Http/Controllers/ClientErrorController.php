<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClientErrorController extends Controller
{
    /**
     * Error de la app (web o móvil) que reportan los error boundaries y los
     * manejadores globales. Solo se registra en el log del servidor (Coolify)
     * con el usuario, la empresa y la sucursal: sin esto, un error en la
     * pantalla de un cajero nunca llegaba a nadie.
     *
     * POST /api/auth/admin/client-errors → 204
     * Cuerpo: message (obligatorio, máx. 2000), stack, route, platform,
     * app_version, extra (objeto). Los opcionales se recortan en vez de
     * rechazarse: un reporte nunca debe perderse por largo.
     */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string',
            'route' => 'nullable|string',
            'platform' => 'nullable|string',
            'app_version' => 'nullable|string',
            'extra' => 'nullable|array',
        ]);

        // El mensaje va en una sola línea (sin saltos que imiten otra
        // entrada del log); el detalle completo va en el contexto (JSON).
        Log::error('Error en la app: ' . Str::limit(Str::squish($data['message']), 300), [
            'client_error' => [
                'message' => $data['message'],
                'stack' => $this->limit($data['stack'] ?? null, 8000),
                'route' => $this->limit($data['route'] ?? null, 500),
                'platform' => $this->limit($data['platform'] ?? null, 30),
                'app_version' => $this->limit($data['app_version'] ?? null, 50),
                'extra' => $this->extra($data['extra'] ?? null),
            ],
            'user_id' => $request->user()?->id,
            'company_id' => CurrentCompany::id(),
            'location_id' => CurrentLocation::id(),
            'user_agent' => $this->limit($request->userAgent(), 300),
        ]);

        return response()->noContent();
    }

    private function limit(?string $value, int $max): ?string
    {
        return $value === null || $value === '' ? null : Str::limit($value, $max);
    }

    /**
     * Datos extra tal cual si son chicos; si no, recortados como texto.
     */
    private function extra(?array $extra): array|string|null
    {
        if (empty($extra)) {
            return null;
        }

        $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen((string) $json) <= 4000 ? $extra : Str::limit((string) $json, 4000);
    }
}
