<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Models\InventoryTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Ticket de venta, corte de turno y remisión de transferencia.
 */
class PrintDocumentsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const CASHIER = ['sales_pos', 'products_list', 'products_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
    }

    /** Cajero con turno abierto que vende; devuelve [cajero, turno, venta]. */
    private function cashierSells(): array
    {
        $coca = $this->createProduct(['name' => 'Cocada', 'sale_price' => 18], 10);
        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'name' => 'Caja 1', 'code' => 'C1',
        ]);
        $cashier = $this->createUserWithRole($this->location, self::CASHIER);
        Sanctum::actingAs($cashier, ['*']);
        $h = $this->tenantHeaders();

        $sessionId = $this->withHeaders($h)->postJson('/api/auth/admin/cash-register-sessions/open', [
            'cash_register_id' => $register->id, 'opening_amount' => 500,
        ])->assertCreated()->json('data.id');
        $saleId = $this->withHeaders($h)->postJson('/api/auth/admin/pos/sales', [
            'details' => [['product_id' => $coca->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 36, 'tendered' => 50]],
        ])->assertCreated()->json('data.id');

        return [$cashier, $sessionId, $saleId];
    }

    private function pdf(string $url): \Illuminate\Testing\TestResponse
    {
        $response = $this->get($url)->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        return $response;
    }

    public function test_cashier_prints_the_ticket_of_their_sale_and_the_shift_cut(): void
    {
        [, $sessionId, $saleId] = $this->cashierSells();
        $h = $this->tenantHeaders();

        // Ticket (80 mm por defecto, 58 mm si se pide).
        $url = $this->withHeaders($h)->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->assertOk()->json('url');
        $this->pdf($url);
        $this->pdf($this->withHeaders($h)->getJson("/api/auth/admin/sales/{$saleId}/ticket-url?width=58")->json('url'));

        // Corte parcial con el turno abierto y corte final al cerrarlo.
        $this->pdf($this->withHeaders($h)->getJson("/api/auth/admin/cash-register-sessions/{$sessionId}/ticket-url")->json('url'));
        $this->withHeaders($h)->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 530])->assertOk();
        $this->pdf($this->withHeaders($h)->getJson("/api/auth/admin/cash-register-sessions/{$sessionId}/ticket-url")->json('url'));
    }

    public function test_a_cashier_cannot_print_other_peoples_documents(): void
    {
        [, $sessionId, $saleId] = $this->cashierSells();
        $h = $this->tenantHeaders();

        Sanctum::actingAs($this->createUserWithRole($this->location, self::CASHIER), ['*']);
        $this->withHeaders($h)->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->assertForbidden();
        $this->withHeaders($h)->getJson("/api/auth/admin/cash-register-sessions/{$sessionId}/ticket-url")->assertForbidden();

        // Un encargado de otra sucursal sin acceso a todas tampoco ve la venta.
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->createUserWithRole($other, ['sales_read']), ['*']);
        $this->withHeaders($this->tenantHeaders($other))->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->assertNotFound();

        // El administrador sí.
        Sanctum::actingAs($this->user, ['*']);
        $this->withHeaders($h)->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->assertOk();
    }

    public function test_signed_urls_cannot_be_reused_for_another_company(): void
    {
        [, , $saleId] = $this->cashierSells();
        Sanctum::actingAs($this->user, ['*']);
        $url = $this->withHeaders($this->tenantHeaders())->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->json('url');

        $other = Company::factory()->create();
        $this->get(str_replace("company_id={$this->company->id}", "company_id={$other->id}", $url))->assertForbidden();
        $this->get(preg_replace('/&signature=[^&]+/', '', $url))->assertForbidden();

        // Desde otra empresa la venta no existe.
        $otherBranch = $this->createBranch([], $other);
        $this->actingInBranch($this->createUserWithRole($otherBranch), $otherBranch)
            ->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")->assertNotFound();

        // Y la sucursal de una empresa no se acepta con el contexto de otra.
        $this->actingInBranch($this->user)->withHeaders(['X-Company-ID' => (string) $other->id])
            ->getJson("/api/auth/admin/sales/{$saleId}/ticket-url")
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');
    }

    public function test_transfer_remission(): void
    {
        $destination = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Guayabitos']);
        $coco = $this->createProduct(['name' => 'Coco entero'], 100);
        $transfer = InventoryTransfer::create([
            'company_id' => $this->company->id, 'from_location_id' => $this->location->id, 'to_location_id' => $destination->id,
            'requested_by' => $this->user->id, 'status' => 'pending', 'notes' => 'Surtido semanal',
        ]);
        $transfer->details()->create([
            'product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity_requested' => 50,
        ]);

        $h = $this->tenantHeaders();
        $this->pdf($this->withHeaders($h)->getJson("/api/auth/admin/inventory-transfers/{$transfer->id}/pdf-url")->assertOk()->json('url'));

        Sanctum::actingAs($this->createUserWithRole($this->location, self::CASHIER), ['*']);
        $this->withHeaders($h)->getJson("/api/auth/admin/inventory-transfers/{$transfer->id}/pdf-url")->assertForbidden();
    }
}
