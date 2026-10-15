<?php

namespace Tests\Feature\AccesosUsuarios;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Las listas de CrudController no tenían tope: sin per_page devolvían la
 * tabla completa, per_page aceptaba cualquier número y sort_by iba directo a
 * orderBy (una columna inexistente respondía 500).
 */
class AccesosCrudListTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function categories(int $count, string $prefix = 'Categoría'): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'name' => sprintf('%s %04d', $prefix, $i), 'company_id' => $this->company->id, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('categories')->insert($chunk);
        }
    }

    private function list(string $query)
    {
        return $this->actingInBranch($this->user)->getJson('/api/auth/admin/categories' . $query)->assertOk();
    }

    public function test_per_page_is_capped(): void
    {
        $this->categories(120);

        $response = $this->list('?per_page=100000');
        $response->assertJsonPath('meta.per_page', 100)->assertJsonPath('meta.total', 120);
        $this->assertCount(100, $response->json('data'));

        $this->list('?paginated=true')->assertJsonPath('meta.per_page', 15);
        $this->list('?paginated=true&per_page=25&page=2')->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.current_page', 2);
    }

    public function test_selects_still_get_the_whole_list_without_pagination(): void
    {
        $this->categories(30);

        foreach (['?all=true', '', '?all=1&is_active=1'] as $query) {
            $response = $this->list($query);
            $this->assertCount(30, $response->json('data'), $query);
            $this->assertNull($response->json('meta'), "{$query} no debe paginarse");
        }
    }

    public function test_unpaginated_lists_have_a_ceiling(): void
    {
        $this->categories(1005);

        $this->assertCount(1000, $this->list('?all=true')->json('data'));
        $this->assertCount(1000, $this->list('')->json('data'));
    }

    public function test_sort_by_only_accepts_known_columns(): void
    {
        $this->categories(3);

        $names = $this->list('?sort_by=name&sort_direction=asc')->json('data.*.name');
        $this->assertSame(['Categoría 0001', 'Categoría 0002', 'Categoría 0003'], $names);

        // Columnas que no existen o no están permitidas: orden por id, sin 500.
        foreach (['no_existe', 'company_id', 'name`; DROP TABLE categories; --', 'name desc'] as $sortBy) {
            $ids = $this->list('?sort_by=' . urlencode($sortBy) . '&sort_direction=asc')->json('data.*.id');
            $this->assertSame(collect($ids)->sort()->values()->all(), $ids, $sortBy);
        }

        $ids = $this->list('?sort_by=name&sort_direction=sideways')->json('data.*.id');
        $this->assertSame(collect($ids)->sortDesc()->values()->all(), $ids);
        $this->assertSame(3, DB::table('categories')->count());
    }
}
