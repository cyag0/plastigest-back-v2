<?php

namespace Tests\Feature\Flows;

use App\Models\PurchaseV2;
use App\Models\Supplier;
use App\Models\Task;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_purchase_registered_as_received_does_not_send_the_order(): void
    {
        config(['services.whatsapp.access_token' => 'token', 'services.whatsapp.phone_number_id' => '123']);
        $purchase = $this->draftWithProduct();

        // Ya llegó: no hay pedido que mandarle al proveedor.
        $this->mock(\App\Services\WhatsAppService::class, function ($mock) {
            $mock->shouldNotReceive('sendPurchaseOrder');
        });

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm", ['receive_now' => true])
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('whatsapp_sent', false);

        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->fresh()->status);
    }

    private function receiveTasks(PurchaseV2 $purchase): int
    {
        return Task::where('related_type', PurchaseV2::class)
            ->where('related_id', $purchase->id)
            ->where('type', 'receive_purchase')
            ->count();
    }

    public function test_a_late_reply_does_not_reopen_a_purchase_received_without_transit(): void
    {
        $purchase = $this->orderedPurchase();
        $line = \App\Models\PurchaseDetailV2::create([
            'purchase_id' => $purchase->id, 'product_id' => $this->createProduct()->id, 'unit_id' => $this->unitId('Pieza'),
            'quantity' => 10, 'unit_price' => 50,
        ]);

        // Se recibió directo, sin marcarla en tránsito; después el proveedor
        // contesta que ya lo envió.
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/receive", [
                'details' => [['id' => $line->id, 'quantity_received' => 10]],
            ])
            ->assertOk();

        $this->postSignedWebhook($this->message('5213221234567', 'Buenas, ya lo envio'))->assertOk();

        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->fresh()->status);
        $this->assertSame(0, $this->receiveTasks($purchase), 'no queda una tarea de recibir lo ya recibido');
    }

    public function test_a_purchase_received_while_the_reply_is_processed_stays_received(): void
    {
        $purchase = $this->orderedPurchase();

        // Se recibe justo después de que el webhook la encontró pedida (la
        // búsqueda sin bloqueo), antes de que la cambie.
        $received = false;
        DB::listen(function (QueryExecuted $query) use (&$received, $purchase) {
            $sql = strtolower(ltrim($query->sql));
            if ($received || !str_starts_with($sql, 'select') || !str_contains($sql, 'from `purchases`')
                || str_contains($sql, 'for update') || !in_array(PurchaseV2::STATUS_ORDERED, $query->bindings, true)) {
                return;
            }

            $received = true;
            DB::table('purchases')->where('id', $purchase->id)->update(['status' => PurchaseV2::STATUS_RECEIVED]);
        });

        $this->postSignedWebhook($this->message('5213221234567', 'ya lo envio'))->assertOk();

        $this->assertTrue($received, 'el webhook ya no busca así la compra: ajusta la simulación');
        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->fresh()->status, 'no regresa a en tránsito');
        $this->assertSame([], $purchase->fresh()->metadata ?? [], 'ni deja «en tránsito» en el historial');
        $this->assertSame(0, $this->receiveTasks($purchase));
    }

    /**
     * Borrador del proveedor con un renglón de 10 × $50, creado hace
     * $daysAgo días.
     */
    private function draftOf(Supplier $supplier, int $daysAgo = 0): PurchaseV2
    {
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => PurchaseV2::generatePurchaseNumber(),
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_DRAFT,
            'user_id' => $this->user->id,
        ]);
        \App\Models\PurchaseDetailV2::create([
            'purchase_id' => $purchase->id, 'product_id' => $this->createProduct()->id, 'unit_id' => $this->unitId('Pieza'),
            'quantity' => 10, 'unit_price' => 50,
        ]);
        if ($daysAgo > 0) {
            $purchase->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }

        return $purchase->fresh('details');
    }

    private function confirm(PurchaseV2 $purchase, array $payload = []): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm", $payload)
            ->assertOk();
    }

    public function test_a_late_reply_to_an_order_received_directly_does_not_move_an_older_order(): void
    {
        config(['services.whatsapp.access_token' => null]);
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerteros', 'phone' => '322 123 4567']);

        // Un pedido viejo que se quedó pedido, y el último, que ya llegó y se
        // recibió directo (sin «en tránsito»).
        $forgotten = $this->draftOf($supplier, 10);
        $this->confirm($forgotten);
        $latest = $this->draftOf($supplier);
        $this->confirm($latest);
        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/{$latest->id}/receive", [
                'details' => [['id' => $latest->details->first()->id, 'quantity_received' => 10]],
            ])
            ->assertOk();

        // El proveedor contesta tarde al mensaje del pedido que ya llegó.
        $this->postSignedWebhook($this->message('5213221234567', 'Buenas, ya lo envio'))->assertOk();

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $forgotten->fresh()->status, 'el pedido viejo no pasa a en tránsito');
        $this->assertSame(0, $this->receiveTasks($forgotten), 'ni le crea la tarea de recibirlo');
        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $latest->fresh()->status);
    }

    public function test_a_purchase_registered_as_received_does_not_take_the_reply_to_an_open_order(): void
    {
        config(['services.whatsapp.access_token' => null]);
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerteros', 'phone' => '322 123 4567']);

        $order = $this->draftOf($supplier, 2);
        $this->confirm($order);
        // Después se compró en su tienda y se registró ya recibida: nunca se
        // le pidió, así que la respuesta sigue siendo del pedido.
        $bought = $this->draftOf($supplier);
        $this->confirm($bought, ['receive_now' => true]);

        $this->postSignedWebhook($this->message('5213221234567', 'ya lo envio'))->assertOk();

        $this->assertSame(PurchaseV2::STATUS_IN_TRANSIT, $order->fresh()->status);
        $this->assertSame(1, $this->receiveTasks($order));
        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $bought->fresh()->status);
    }
}
