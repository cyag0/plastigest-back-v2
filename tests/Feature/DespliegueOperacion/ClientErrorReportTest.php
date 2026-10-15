<?php

namespace Tests\Feature\DespliegueOperacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * POST auth/admin/client-errors: la app reporta sus errores y quedan en el
 * log del servidor con el usuario, la empresa y la sucursal.
 */
class ClientErrorReportTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const URL = '/api/auth/admin/client-errors';

    /** @var MessageLogged[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant(['sales_pos']);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    public function test_report_is_logged_with_user_and_branch_context(): void
    {
        $this->actingInBranch($this->user)->postJson(self::URL, [
            'message' => "TypeError: Cannot read properties of undefined (reading 'total')\nsegunda línea",
            'stack' => 'at CheckoutModal (CheckoutModal.tsx:120:15)',
            'route' => '/(tabs)/pos',
            'platform' => 'web',
            'app_version' => '1.0.3',
            'extra' => ['component' => 'CheckoutModal', 'cart_items' => 3],
        ])->assertNoContent();

        $this->assertCount(1, $this->logged);
        $entry = $this->logged[0];
        $this->assertSame('error', $entry->level);
        $this->assertStringStartsWith('Error en la app: TypeError', $entry->message);
        $this->assertStringNotContainsString("\n", $entry->message, 'una sola línea en el log');

        $this->assertSame($this->user->id, $entry->context['user_id']);
        $this->assertSame($this->company->id, $entry->context['company_id']);
        $this->assertSame($this->location->id, $entry->context['location_id']);
        $this->assertSame('web', $entry->context['client_error']['platform']);
        $this->assertSame('1.0.3', $entry->context['client_error']['app_version']);
        $this->assertSame('/(tabs)/pos', $entry->context['client_error']['route']);
        $this->assertSame(['component' => 'CheckoutModal', 'cart_items' => 3], $entry->context['client_error']['extra']);
        $this->assertStringContainsString('segunda línea', $entry->context['client_error']['message']);
    }

    public function test_only_the_message_is_required_and_long_optional_fields_are_trimmed(): void
    {
        $this->actingInBranch($this->user)->postJson(self::URL, [
            'message' => 'Algo falló',
            'stack' => str_repeat('a', 20000),
        ])->assertNoContent();

        $this->assertLessThanOrEqual(8003, mb_strlen($this->logged[0]->context['client_error']['stack']));
    }

    public function test_invalid_reports_are_rejected(): void
    {
        $this->actingInBranch($this->user)->postJson(self::URL, [])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->actingInBranch($this->user)->postJson(self::URL, ['message' => str_repeat('x', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->actingInBranch($this->user)->postJson(self::URL, ['message' => 'ok', 'extra' => 'no es objeto'])
            ->assertStatus(422)->assertJsonValidationErrors('extra');

        $this->assertCount(0, $this->logged);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::URL, ['message' => 'sin sesión'], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_reports_are_throttled_per_user(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->actingInBranch($this->user)->postJson(self::URL, ['message' => "Error {$i}"])->assertNoContent();
        }

        $this->actingInBranch($this->user)->postJson(self::URL, ['message' => 'Uno más'])->assertStatus(429);

        // Otro usuario tiene su propio límite.
        $other = $this->createBranchUser($this->location, ['sales_pos']);
        $this->actingInBranch($other)->postJson(self::URL, ['message' => 'Otro usuario'])->assertNoContent();
    }
}
