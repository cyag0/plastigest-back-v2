<?php

namespace Tests\Feature\DespliegueOperacion;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * request()->ip() es el cliente real detrás del Traefik de Coolify, con o sin
 * Cloudflare delante, y no se puede falsificar con cabeceras (de esa IP
 * dependen los límites de intentos del login y del OTP).
 */
class ClientIpTest extends TestCase
{
    /** IP con la que Traefik le llega al contenedor. */
    private const TRAEFIK = '172.18.0.5';

    /** Un nodo de Cloudflare (rango 172.64.0.0/13). */
    private const CLOUDFLARE_EDGE = '172.70.1.1';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/client-ip', fn(Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    }

    private function ipFor(string $remoteAddr, array $headers): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->getJson('/_test/client-ip', $headers)
            ->assertOk()
            ->json();
    }

    public function test_sin_cloudflare_la_ip_es_la_que_agrega_traefik(): void
    {
        $result = $this->ipFor(self::TRAEFIK, ['X-Forwarded-For' => '201.141.10.20', 'X-Forwarded-Proto' => 'https']);

        $this->assertSame('201.141.10.20', $result['ip']);
        $this->assertTrue($result['secure']);
    }

    public function test_un_x_forwarded_for_inventado_por_el_cliente_no_cuenta(): void
    {
        // Traefik agrega la IP real al final de lo que mandó el cliente.
        $result = $this->ipFor(self::TRAEFIK, ['X-Forwarded-For' => '1.2.3.4, 201.141.10.20']);

        $this->assertSame('201.141.10.20', $result['ip']);
    }

    public function test_con_cloudflare_y_la_cadena_completa_la_ip_es_la_del_cliente(): void
    {
        $result = $this->ipFor(self::TRAEFIK, ['X-Forwarded-For' => '201.141.10.20, ' . self::CLOUDFLARE_EDGE]);

        $this->assertSame('201.141.10.20', $result['ip']);
    }

    public function test_con_cloudflare_y_traefik_reescribiendo_la_cadena_se_usa_cf_connecting_ip(): void
    {
        // Traefik por defecto descarta el X-Forwarded-For de Cloudflare y deja
        // solo la IP del nodo de Cloudflare.
        $result = $this->ipFor(self::TRAEFIK, [
            'X-Forwarded-For' => self::CLOUDFLARE_EDGE,
            'X-Forwarded-Proto' => 'https',
            'CF-Connecting-IP' => '201.141.10.20',
        ]);

        $this->assertSame('201.141.10.20', $result['ip']);
        // Las URLs siguen saliendo https://.
        $this->assertTrue($result['secure']);

        // IPv6 también.
        $result = $this->ipFor(self::TRAEFIK, [
            'X-Forwarded-For' => '2606:4700:10::6816:1',
            'CF-Connecting-IP' => '2806:2f0:9020::1',
        ]);
        $this->assertSame('2806:2f0:9020::1', $result['ip']);
    }

    public function test_cf_connecting_ip_falsificado_sin_pasar_por_cloudflare_no_cuenta(): void
    {
        $result = $this->ipFor(self::TRAEFIK, ['X-Forwarded-For' => '201.141.10.20', 'CF-Connecting-IP' => '8.8.8.8']);
        $this->assertSame('201.141.10.20', $result['ip']);

        // Ni con un nodo de Cloudflare inventado al inicio de la cadena.
        $result = $this->ipFor(self::TRAEFIK, [
            'X-Forwarded-For' => self::CLOUDFLARE_EDGE . ', 201.141.10.20',
            'CF-Connecting-IP' => '8.8.8.8',
        ]);
        $this->assertSame('201.141.10.20', $result['ip']);
    }

    public function test_un_cf_connecting_ip_invalido_se_ignora(): void
    {
        $result = $this->ipFor(self::TRAEFIK, ['X-Forwarded-For' => self::CLOUDFLARE_EDGE, 'CF-Connecting-IP' => 'no-es-ip']);

        $this->assertSame(self::CLOUDFLARE_EDGE, $result['ip']);
    }

    public function test_sin_proxy_la_ip_es_la_conexion_directa(): void
    {
        $this->assertSame('201.141.10.20', $this->ipFor('201.141.10.20', [])['ip']);
    }
}
