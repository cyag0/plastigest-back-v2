<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\PurchaseV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Editar un renglón de compra (la app lo hace con cada cambio del carrito) o
 * un producto ya no deja la petición completa en el log: eran restos de
 * depuración a nivel info, que es el nivel de producción.
 */
class ComprasProduccionDebugLogTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    public function test_line_edits_and_product_updates_do_not_dump_the_request_into_the_log(): void
    {
        $this->createTenant();
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
        $coco = $this->createProduct(['name' => 'Coco'], 0);

        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'purchase_number' => PurchaseV2::generatePurchaseNumber(),
            'purchase_date' => now(),
            'status' => PurchaseV2::STATUS_DRAFT,
            'user_id' => $this->user->id,
        ]);
        $detail = $purchase->details()->create([
            'product_id' => $coco->id, 'quantity' => 10, 'unit_id' => $this->unitId('Pieza'), 'unit_price' => 15,
        ]);

        Log::spy();

        $this->withHeaders($this->tenantHeaders())
            ->postJson("/api/auth/admin/purchases-v2/details/{$detail->id}/update", ['quantity' => 3])
            ->assertOk();

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/products/{$coco->id}", ['name' => 'Coco verde'])
            ->assertOk();

        Log::shouldNotHaveReceived('info', ['UpdateDetail Request', Mockery::any()]);
        Log::shouldNotHaveReceived('info', ['message', Mockery::any()]);
        Log::shouldNotHaveReceived('info', ['Mensaje informativo', Mockery::any()]);
    }
}
