<?php

namespace Tests\Feature\DespliegueOperacion;

use App\Models\PurchaseV2;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Verificación del webhook de WhatsApp sin token por defecto público, y logs
 * sin teléfonos ni textos de los proveedores.
 */
class WhatsAppWebhookSecurityTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const APP_SECRET = 'test-whatsapp-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        // Está apagado por omisión (WHATSAPP_ENABLED): estas pruebas lo prenden.
        config(['services.whatsapp.enabled' => true]);
    }

    private function verify(?string $token): \Illuminate\Testing\TestResponse
    {
        return $this->get('/api/webhooks/whatsapp?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_challenge' => '1158201444',
            'hub_verify_token' => $token,
        ]));
    }

    public function test_verification_fails_closed_without_a_configured_token(): void
    {
        config(['services.whatsapp.verify_token' => null]);

        // El valor por defecto que antes estaba en config/services.php.
        $this->verify('plastigest_webhook_token')->assertForbidden();
        $this->verify('')->assertForbidden();
    }

    public function test_verification_echoes_the_challenge_only_with_the_configured_token(): void
    {
        config(['services.whatsapp.verify_token' => 'token-secreto-de-meta']);

        $this->verify('otro-token')->assertForbidden();
        $this->verify('plastigest_webhook_token')->assertForbidden();

        $this->verify('token-secreto-de-meta')
            ->assertOk()
            ->assertSee('1158201444', false);
    }

    public function test_webhook_logs_do_not_include_supplier_phones_or_texts(): void
    {
        $this->createTenant();
        config(['services.whatsapp.app_secret' => self::APP_SECRET]);

        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Huerteros',
            'phone' => '322 987 6543',
        ]);
        PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'PUR-WA-LOG',
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_ORDERED,
            'total' => 500,
            'user_id' => $this->user->id,
        ]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });

        $value = [
            'messages' => [[
                'id' => 'wamid.MENSAJE1',
                'from' => '5213229876543',
                'type' => 'text',
                'text' => ['body' => 'Mi dirección privada, ya lo envio'],
            ]],
        ];
        $this->postSigned(['entry' => [['changes' => [['value' => $value]]]]])->assertOk();

        $statuses = ['statuses' => [[
            'id' => 'wamid.MENSAJE2',
            'status' => 'failed',
            'recipient_id' => '5213229876543',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]];
        $this->postSigned(['entry' => [['changes' => [['value' => $statuses]]]]])->assertOk();

        $all = implode("\n", $logged);
        $this->assertStringContainsString('wamid.MENSAJE1', $all);
        $this->assertStringContainsString('wamid.MENSAJE2', $all);
        $this->assertStringNotContainsString('9876543', $all, 'sin el teléfono del proveedor');
        $this->assertStringNotContainsString('dirección privada', $all, 'sin el texto del mensaje');
    }

    private function postSigned(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }
}
