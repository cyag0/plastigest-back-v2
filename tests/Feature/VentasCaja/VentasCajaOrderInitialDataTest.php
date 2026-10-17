<?php

namespace Tests\Feature\VentasCaja;

use App\Enums\SalesOrderChannel;
use App\Enums\SalesOrderServiceMode;
use App\Enums\SalesOrderStatus;
use App\Models\Admin\Customer;

/**
 * sales-orders/initial-data trae los clientes de la empresa (RFC, teléfono,
 * correo) y los productos con su costo: solo para quien arma pedidos
 * (sales_orders_create). Quien solo ve la lista de Pedidos recibe los
 * catálogos (canales, modos, estados y la sucursal) sin esos datos. Antes
 * cualquier usuario con sesión lo podía bajar.
 */
class VentasCajaOrderInitialDataTest extends VentasCajaTestCase
{
    public function test_customers_and_costs_need_sales_orders_create(): void
    {
        $this->createProduct(['sale_price' => 30, 'purchase_price' => 7.77], 10);
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente', 'rfc' => 'XAXX010101000', 'is_active' => true]);
        $url = '/api/auth/admin/sales-orders/initial-data';

        // Solo la lista de Pedidos: los catálogos, sin clientes ni productos.
        $lister = $this->createBranchUser($this->location, ['sales_orders_list', 'sales_orders_read', 'products_list', 'customers_list'])->fresh();
        $response = $this->actingInBranch($lister)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.customers', [])
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.location.id', $this->location->id)
            ->assertJsonCount(count(SalesOrderChannel::cases()), 'data.channels')
            ->assertJsonCount(count(SalesOrderServiceMode::cases()), 'data.service_modes')
            ->assertJsonCount(count(SalesOrderStatus::cases()), 'data.statuses')
            ->assertJsonPath('data.statuses.0', ['value' => 'pending', 'label' => 'Pendiente', 'color' => '#6B7280']);
        foreach (['XAXX010101000', 'purchase_price', '7.77'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        // Ni la lista ni el alta (la cuenta del kiosko): 403.
        $kiosk = $this->createBranchUser($this->location, ['sales_kiosk'])->fresh();
        $this->actingInBranch($kiosk)->getJson($url)->assertForbidden();

        // Quien arma pedidos: todo.
        $creator = $this->createBranchUser($this->location, ['sales_orders_create'])->fresh();
        $this->actingInBranch($creator)->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonCount(count(SalesOrderStatus::cases()), 'data.statuses');
    }
}
