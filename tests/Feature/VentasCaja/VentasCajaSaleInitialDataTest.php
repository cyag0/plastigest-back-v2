<?php

namespace Tests\Feature\VentasCaja;

/**
 * sales/initial-data trae los productos con su costo de compra y existencias:
 * solo para quien arma ventas o transferencias (sus dos formularios). Antes
 * cualquier sesión lo podía bajar, también la cuenta del kiosko.
 */
class VentasCajaSaleInitialDataTest extends VentasCajaTestCase
{
    public function test_it_needs_a_sale_or_transfer_form_permission(): void
    {
        $this->createProduct(['sale_price' => 30, 'purchase_price' => 12], 10);
        $url = '/api/auth/admin/sales/initial-data';

        $kiosk = $this->createBranchUser($this->location, ['sales_kiosk'])->fresh();
        $this->actingInBranch($kiosk)->getJson($url)->assertForbidden();

        $reader = $this->createBranchUser($this->location, ['sales_list', 'sales_read', 'products_list'])->fresh();
        $this->actingInBranch($reader)->getJson($url)->assertForbidden();

        foreach (['sales_create', 'sales_update', 'transfers_create', 'transfers_update'] as $permission) {
            $user = $this->createBranchUser($this->location, [$permission])->fresh();
            $this->actingInBranch($user)->getJson($url)->assertOk();
        }
    }
}
