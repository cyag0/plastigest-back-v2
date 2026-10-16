<?php

namespace Tests\Feature\Foundation;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Task;
use App\Support\NotFoundMessage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Un registro o una ruta que no existe responde 404 con un mensaje en
 * español. Antes un findOrFail o un {task} de la ruta respondía "No query
 * results for model [App\Models\Task] 7" (con la traza si APP_DEBUG está
 * prendido) y la app lo mostraba tal cual.
 */
class NotFoundResponsesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Como en desarrollo: con APP_DEBUG la respuesta llevaba la traza.
        config(['app.debug' => true]);
    }

    private function assertFriendly404(TestResponse $response, string $message): void
    {
        $response->assertNotFound()
            ->assertExactJson(['message' => $message]);

        $this->assertStringNotContainsString('No query results', $response->getContent());
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
    }

    public function test_a_missing_task_responds_404_with_its_name_and_no_trace(): void
    {
        $this->assertFriendly404(
            $this->actingInBranch($this->user)->getJson('/api/auth/admin/tasks/999999'),
            'Tarea no encontrada'
        );

        // Igual en las demás acciones con la tarea en la ruta.
        $this->assertFriendly404(
            $this->actingInBranch($this->user)->postJson('/api/auth/admin/tasks/999999/change-status', ['status' => 'completed']),
            'Tarea no encontrada'
        );
        $this->assertFriendly404(
            $this->actingInBranch($this->user)->deleteJson('/api/auth/admin/tasks/999999'),
            'Tarea no encontrada'
        );
    }

    public function test_an_uncaught_find_or_fail_names_the_record(): void
    {
        $this->assertFriendly404(
            $this->actingInBranch($this->user)->getJson('/api/auth/admin/expenses/999999'),
            'Gasto no encontrado'
        );
    }

    public function test_marking_a_missing_notification_as_read_responds_404(): void
    {
        // Antes 400 con el texto de Laravel; la app lo pide al tocar un push.
        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/notifications/999999/mark-as-read')
            ->assertNotFound()
            ->assertJsonPath('message', 'Notificación no encontrada');
        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/notifications/999999/mark-as-unread')
            ->assertNotFound()
            ->assertJsonPath('message', 'Notificación no encontrada');
    }

    public function test_abort_404_keeps_its_own_message(): void
    {
        [, $foreignBranch] = $this->createCompanyWithBranch();

        $this->assertFriendly404(
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/inventory-adjustments?location_id={$foreignBranch->id}"),
            'Sucursal no encontrada'
        );
    }

    public function test_abort_404_without_message_gets_the_generic_one(): void
    {
        $this->assertFriendly404(
            $this->getJson('/api/auth/files/no-existe.png'),
            NotFoundMessage::GENERIC
        );
    }

    public function test_an_unknown_api_route_responds_404_in_spanish(): void
    {
        $this->assertFriendly404(
            $this->actingInBranch($this->user)->getJson('/api/auth/admin/no-existe'),
            NotFoundMessage::ROUTE
        );
    }

    public function test_web_routes_keep_the_default_404(): void
    {
        $response = $this->get('/no-existe')->assertNotFound();

        $this->assertStringNotContainsString(NotFoundMessage::ROUTE, $response->getContent());
    }

    public function test_caught_lookups_do_not_show_the_model_class(): void
    {
        // errorResponse() y errorDetail() son protegidos: se llaman como lo
        // haría un controlador en su catch.
        $controller = new class extends Controller {};
        $call = fn(string $method, ...$args) => (new \ReflectionMethod(Controller::class, $method))->invoke($controller, ...$args);
        $missing = (new ModelNotFoundException())->setModel(Sale::class, [5]);

        // Mismo estado que antes (quien llama decide si la operación pudo
        // haberse guardado); solo cambia el texto.
        $response = $call('errorResponse', $missing, 'Error al cobrar la venta');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Error al cobrar la venta', 'error' => 'Venta no encontrada'], $response->getData(true));

        $this->assertSame('Venta no encontrada', $call('errorDetail', $missing));
        $this->assertSame('Tarea no encontrada', $call('errorDetail', (new ModelNotFoundException())->setModel(Task::class, [1])));
        $this->assertSame(NotFoundMessage::GENERIC, $call('errorDetail', (new ModelNotFoundException())->setModel('App\\Models\\Otro', [1])));
    }
}
