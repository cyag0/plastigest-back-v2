<?php

namespace Tests\Feature\Revision;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Services\SalesOrderStockService;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * Editar un pedido no lo bloqueaba ni releía su estado: un "preparar" que
 * entraba entre la revisión de validateUpdateData y el reemplazo de los
 * renglones dejaba stock apartado que nadie liberaba (cancelar y cobrar
 * liberan lo que dicen los renglones), y el punto de venta se negaba a vender
 * ese stock. Ahora la edición bloquea el pedido y relee su estado.
 */
class RevisionOrderEditRaceTest extends VentasCajaTestCase
{
    /** Apartado del producto en la sucursal = lo que dicen los renglones del pedido. */
    private function assertReservationsMatchLines(SalesOrder $order, $product): void
    {
        $lines = (float) SalesOrderDetail::where('sales_order_id', $order->id)->sum('reserved_quantity_base');
        $this->assertEqualsWithDelta($lines, $this->reservedStock($product), 0.0001, 'quedó stock apartado que ningún renglón explica');
    }

    public function test_an_edit_racing_with_prepare_is_rejected_and_leaves_no_phantom_reservation(): void
    {
        $product = $this->createProduct(['sale_price' => 20], 20);
        $order = $this->makeOrder($product, 4, 20, 'pending', 'delivery');

        // "Preparar" de otra persona que entra justo después de la revisión
        // sin bloqueo (validateUpdateData) y antes de que la edición escriba.
        $race = new class extends SalesOrderStockService {
            public ?SalesOrder $prepare = null;

            public function validateDraftDetails(int $locationId, array $details, bool $forUpdate = false): void
            {
                if ($order = $this->prepare) {
                    $this->prepare = null;
                    // Lo que hace SalesOrderController::transition al preparar.
                    $this->reserveOrder($order);
                    $order->transitionTo(SalesOrderStatus::PREPARING);
                }

                parent::validateDraftDetails($locationId, $details, $forUpdate);
            }
        };
        $race->prepare = $order;
        $this->app->instance(SalesOrderStockService::class, $race);

        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/sales-orders/{$order->id}", [
                'details' => [$this->orderLine($product, 6, 20)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertReservationsMatchLines($order, $product);
    }

    public function test_a_pending_order_is_still_editable(): void
    {
        $product = $this->createProduct(['sale_price' => 20], 20);
        $order = $this->makeOrder($product, 4, 20, 'pending', 'delivery');

        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/sales-orders/{$order->id}", [
                'details' => [$this->orderLine($product, 6, 20)],
            ])
            ->assertOk();

        $this->assertEquals(6, (float) SalesOrderDetail::where('sales_order_id', $order->id)->value('requested_quantity'));
        $this->assertEquals(120, (float) $order->fresh()->total_amount);

        // Y preparar después aparta lo nuevo.
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/prepare")->assertOk();
        $this->assertEquals(6, $this->reservedStock($product));
        $this->assertReservationsMatchLines($order, $product);
    }
}
