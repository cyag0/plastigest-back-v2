<?php

namespace Tests\Feature\Flows;

use App\Models\PurchaseV2;
use App\Models\Supplier;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El proveedor avisa por WhatsApp que ya envió: la compra (v2) pasa a
 * tránsito y se crea la tarea de recibirla. Antes el webhook buscaba en las
 * compras del sistema anterior y nunca encontraba nada.
 */
class WhatsAppPurchaseTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const APP_SECRET = 'test-whatsapp-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();

        // Con app_secret configurado, el webhook exige firma válida.
        config(['services.whatsapp.app_secret' => self::APP_SECRET]);
    }

    private function message(string $from, string $text): array
    {
        return ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => $from,
            'type' => 'text',
            'text' => ['body' => $text],
        ]]]]]]]];
    }

    /**
     * Envía el webhook firmado con X-Hub-Signature-256, como lo hace Meta.
     */
    private function postSignedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET);

        return $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body
        );
    }

    private function orderedPurchase(): PurchaseV2
    {
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Huerteros',
            'phone' => '322 123 4567',
        ]);

        return PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'PUR-WA-1',
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_ORDERED,
            'total' => 500,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_supplier_message_marks_purchase_in_transit_and_creates_task(): void
    {
        $purchase = $this->orderedPurchase();

        $this->postSignedWebhook($this->message('5213221234567', 'Buenas, ya lo envio'))
            ->assertOk();

        $this->assertSame(PurchaseV2::STATUS_IN_TRANSIT, $purchase->fresh()->status);
        $this->assertTrue(
            Task::where('related_type', PurchaseV2::class)->where('related_id', $purchase->id)->exists(),
            'se crea la tarea de recibir la compra'
        );
    }

    public function test_unrelated_message_does_not_change_the_purchase(): void
    {
        $purchase = $this->orderedPurchase();

        $this->postSignedWebhook($this->message('5213221234567', 'Hola, ¿cómo están?'))
            ->assertOk();

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);
    }

    public function test_unsigned_webhook_is_rejected_and_does_not_change_the_purchase(): void
    {
        $purchase = $this->orderedPurchase();

        // Sin firma: rechazado (403) y la compra no cambia de estado.
        $this->postJson('/api/webhooks/whatsapp', $this->message('5213221234567', 'ya lo envio'))
            ->assertStatus(403);

        // Con firma inválida: también rechazado.
        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => 'sha256=deadbeef', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($this->message('5213221234567', 'ya lo envio'))
        )->assertStatus(403);

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);
    }

    private function draftWithProduct(): PurchaseV2
    {
        $purchase = $this->orderedPurchase();
        $purchase->update(['status' => PurchaseV2::STATUS_DRAFT]);
        $product = $this->createProduct();
        \App\Models\PurchaseDetailV2::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id, 'unit_id' => $this->unitId('Pieza'),
            'quantity' => 10, 'unit_price' => 50, 'total' => 500,
        ]);

        return $purchase;
    }

    public function test_confirming_a_purchase_sends_the_order_to_the_supplier(): void
    {
        config(['services.whatsapp.access_token' => 'token', 'services.whatsapp.phone_number_id' => '123']);
        $purchase = $this->draftWithProduct();

        $this->mock(\App\Services\WhatsAppService::class, function ($mock) use ($purchase) {
            $mock->shouldReceive('sendPurchaseOrder')
                ->once()
                ->withArgs(fn($phone, $p) => $phone === '5213221234567' && $p->id === $purchase->id)
                ->andReturn(true);
        });

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm")
            ->assertOk()
            ->assertJsonPath('whatsapp_sent', true);

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);
    }

    public function test_purchase_is_confirmed_even_without_whatsapp(): void
    {
        config(['services.whatsapp.access_token' => null]);
        $purchase = $this->draftWithProduct();

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm")
            ->assertOk()
            ->assertJsonPath('whatsapp_sent', false);

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);
    }
}
