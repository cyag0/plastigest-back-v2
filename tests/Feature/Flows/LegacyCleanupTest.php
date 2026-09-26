<?php

namespace Tests\Feature\Flows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Registros que apuntan a clases del sistema anterior (ya borradas) no deben
 * tumbar los listados.
 */
class LegacyCleanupTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    public function test_tasks_linked_to_removed_models_do_not_break_the_task_list(): void
    {
        DB::table('tasks')->insert([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'title' => 'Recibir compra (sistema anterior)',
            'type' => 'receive_purchase',
            'status' => 'pending',
            'priority' => 'medium',
            'assigned_to' => $this->user->id,
            'related_type' => 'App\\Models\\Purchase',
            'related_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sin la migración, cargar `related` revienta con "Class not found".
        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/tasks?assigned_to=me')
            ->assertStatus(500);

        (require database_path('migrations/2026_09_26_000001_detach_records_from_removed_legacy_models.php'))->up();

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/tasks?assigned_to=me')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Recibir compra (sistema anterior)');
    }
}
