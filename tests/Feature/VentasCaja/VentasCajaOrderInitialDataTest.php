<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Customer;

/**
 * sales-orders/initial-data trae los clientes de la empresa (RFC, teléfono,
 * correo) y los productos con su costo: solo para quien arma pedidos
 * (sales_orders_create). Antes cualquier usuario con sesión lo podía bajar.
 */
class VentasCajaOrderInitialDataTest extends VentasCajaTestCase
{
    public function test_it_needs_sales_orders_create(): void
    {
        $this->createProduct(['sale_price' => 30], 10);
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente', 'rfc' => 'XAXX010101000', 'is_active' => true]);
        $url = '/api/auth/admin/sales-orders/initial-data';

        $lister = $this->createBranchUser($this->location, ['sales_orders_list', 'sales_orders_read', 'products_list', 'customers_list'])->fresh();
        $this->actingInBranch($lister)->getJson($url)->assertForbidden();

        $kiosk = $this->createBranchUser($this->location, ['sales_kiosk'])->fresh();
        $this->actingInBranch($kiosk)->getJson($url)->assertForbidden();

        $creator = $this->createBranchUser($this->location, ['sales_orders_create'])->fresh();
        $this->actingInBranch($creator)->getJson($url)->assertOk()->assertJsonCount(1, 'data.customers');
    }
}
