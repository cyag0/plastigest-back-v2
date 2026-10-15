<?php

namespace Tests\Feature\DespliegueOperacion;

use Tests\TestCase;

/**
 * La raíz de la API es una página mínima sin build de assets. Nixpacks agrega
 * `npm i` y `npm run build` en cuanto ve un package.json: con Node 18 (el que
 * elige por defecto) Vite 7 no compila y el deploy completo fallaba.
 */
class RootPageTest extends TestCase
{
    public function test_root_page_renders_without_a_frontend_build(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertDontSee('/build/', false);

        $this->assertStringNotContainsString(
            '@vite',
            file_get_contents(resource_path('views/welcome.blade.php')),
            'La página de inicio no debe depender de un build de Vite.'
        );
    }

    public function test_repository_has_no_node_build_for_nixpacks(): void
    {
        $this->assertFileDoesNotExist(
            base_path('package.json'),
            'Con package.json Nixpacks corre npm i / npm run build en el deploy de la API.'
        );
    }
}
