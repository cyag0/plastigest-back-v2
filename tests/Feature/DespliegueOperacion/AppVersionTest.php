<?php

namespace Tests\Feature\DespliegueOperacion;

use Tests\TestCase;

/**
 * GET app/version: versiones de la app nativa que acepta la API, sin sesión.
 */
class AppVersionTest extends TestCase
{
    public function test_versions_are_null_when_not_configured(): void
    {
        config(['app.native_app.min_version' => null, 'app.native_app.latest_version' => '']);

        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertExactJson(['min_native_version' => null, 'latest_native_version' => null]);
    }

    public function test_versions_come_from_config_without_authentication(): void
    {
        config(['app.native_app.min_version' => '1.2.0', 'app.native_app.latest_version' => ' 1.4.1 ']);

        $response = $this->getJson('/api/app/version')
            ->assertOk()
            ->assertExactJson(['min_native_version' => '1.2.0', 'latest_native_version' => '1.4.1']);

        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }
}
