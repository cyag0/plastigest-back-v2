<?php

namespace Tests\Feature\Revision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Las listas con index() propio no tenían el tope de per_page de
 * CrudController: per_page=1000000 traía todo el historial (gastos, turnos,
 * tareas, paquetes) con sus relaciones en una sola respuesta.
 */
class RevisionPerPageCapTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->user = $this->user->fresh();
    }

    public function test_custom_lists_cap_per_page_at_100(): void
    {
        $cases = [
            '/api/auth/admin/expenses?per_page=1000000' => 'meta.per_page',
            '/api/auth/admin/cash-register-sessions?per_page=1000000' => 'meta.per_page',
            '/api/auth/admin/tasks?per_page=1000000' => 'per_page',
            '/api/auth/admin/product-packages?paginated=1&per_page=1000000' => 'meta.per_page',
        ];

        foreach ($cases as $url => $path) {
            $response = $this->actingInBranch($this->user)->getJson($url)->assertOk();
            $this->assertSame(100, (int) $response->json($path), "{$url} no respetó el tope");
        }
    }

    public function test_invalid_per_page_falls_back_to_at_least_one_row(): void
    {
        $response = $this->actingInBranch($this->user)->getJson('/api/auth/admin/expenses?per_page=-5')->assertOk();
        $this->assertSame(1, (int) $response->json('meta.per_page'));

        $response = $this->actingInBranch($this->user)->getJson('/api/auth/admin/expenses')->assertOk();
        $this->assertSame(15, (int) $response->json('meta.per_page'));
    }
}
