<?php

namespace Tests\Feature\DespliegueOperacion;

use Tests\TestCase;

/**
 * Límites de PHP en producción (public/.user.ini, que PHP-FPM lee por
 * directorio) alineados con nginx y con los formularios más grandes de la app.
 */
class RuntimeLimitsTest extends TestCase
{
    private function userIni(): array
    {
        $ini = parse_ini_file(public_path('.user.ini'));
        $this->assertIsArray($ini, 'public/.user.ini no se pudo leer');

        return $ini;
    }

    public function test_completar_un_conteo_grande_no_pierde_campos(): void
    {
        // 7 campos por producto en "Completar conteo" (multipart): al menos
        // 1000 productos sin tocar el default de 1000 variables.
        $this->assertGreaterThanOrEqual(7 * 1000, (int) $this->userIni()['max_input_vars']);
    }

    public function test_el_tamano_de_peticion_coincide_con_nginx(): void
    {
        $ini = $this->userIni();
        preg_match('/client_max_body_size\s+(\w+);/', file_get_contents(base_path('nginx.template.conf')), $nginx);

        $this->assertSame(strtoupper($nginx[1] ?? ''), strtoupper($ini['post_max_size']));
        $this->assertLessThanOrEqual((int) $ini['post_max_size'], (int) $ini['upload_max_filesize']);
    }
}
