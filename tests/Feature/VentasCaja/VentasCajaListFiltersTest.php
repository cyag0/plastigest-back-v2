<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Category;
use App\Models\ProductPackage;
use App\Models\Sale;
use App\Models\Unit;

/**
 * Filtros de las listas que no tenían: Ventas (estado como lo muestra la
 * lista), Cajas (libre, en turno, inactiva), Paquetes (estado y categoría) y
 * Unidades (tipo y origen).
 */
class VentasCajaListFiltersTest extends VentasCajaTestCase
{
    private function sale(string $status, string $paymentStatus): Sale
    {
        return Sale::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'sale_date' => now()->toDateString(), 'status' => $status,
            'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10,
            'paid_amount' => $paymentStatus === 'paid' ? 10 : 0,
            'payment_method' => 'cash', 'payment_status' => $paymentStatus,
        ]);
    }

    private function idsOf(string $url): array
    {
        return collect($this->actingInBranch($this->user)->getJson($url)->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_the_sales_status_filter_matches_the_status_the_list_shows(): void
    {
        $paid = $this->sale('closed', 'paid');
        $partial = $this->sale('closed', 'partial');
        $pending = $this->sale('closed', 'pending');
        // Cobrada y luego cancelada: la lista dice «Cancelada», no «Pagado».
        $cancelled = $this->sale('cancelled', 'paid');

        $this->assertSame([$paid->id], $this->idsOf('/api/auth/admin/sales?status=paid'));
        $this->assertSame([$partial->id], $this->idsOf('/api/auth/admin/sales?status=partial'));
        $this->assertSame([$pending->id], $this->idsOf('/api/auth/admin/sales?status=pending'));
        $this->assertSame([$cancelled->id], $this->idsOf('/api/auth/admin/sales?status=cancelled'));
        // Los estados de la venta siguen igual.
        $this->assertCount(3, $this->idsOf('/api/auth/admin/sales?status=closed'));
        // «Todos» llega vacío y no filtra.
        $this->assertCount(4, $this->idsOf('/api/auth/admin/sales?status='));
    }

    public function test_cash_registers_filter_by_free_in_session_or_inactive(): void
    {
        $free = $this->register();
        $busy = $this->openShiftFor($this->user)->cash_register_id;
        $off = $this->register();
        $off->update(['is_active' => false]);

        $this->assertSame([$free->id], $this->idsOf('/api/auth/admin/cash-registers?status=free'));
        $this->assertSame([$busy], $this->idsOf('/api/auth/admin/cash-registers?status=in_session'));
        $this->assertSame([$off->id], $this->idsOf('/api/auth/admin/cash-registers?status=inactive'));
        $this->assertCount(3, $this->idsOf('/api/auth/admin/cash-registers?status='));
    }

    public function test_packages_filter_by_status_and_product_category(): void
    {
        $drinks = Category::create(['company_id' => $this->company->id, 'name' => 'Bebidas', 'is_active' => true]);
        $soda = $this->createProduct(['name' => 'Refresco', 'category_id' => $drinks->id]);
        $cookie = $this->createProduct(['name' => 'Galleta']);
        $package = fn($product, string $barcode, bool $active) => ProductPackage::create([
            'product_id' => $product->id, 'company_id' => $this->company->id, 'unit_id' => $product->unit_id,
            'package_name' => "Paquete {$barcode}", 'barcode' => $barcode, 'quantity_per_package' => 6,
            'is_active' => $active,
        ]);
        $sixPack = $package($soda, 'P1', true);
        $oldPack = $package($soda, 'P2', false);
        $cookiePack = $package($cookie, 'P3', true);

        $ids = fn(string $query) => collect(
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/product-packages?{$query}")->assertOk()->json()
        )->pluck('id')->sort()->values()->all();

        $this->assertSame([$oldPack->id], $ids('is_active=0'));
        $this->assertSame([$sixPack->id, $cookiePack->id], $ids('is_active=1'));
        $this->assertSame([$sixPack->id, $oldPack->id], $ids("category_id={$drinks->id}"));
        // Un product_id vacío ya no deja la lista vacía.
        $this->assertCount(3, $ids('product_id='));
    }

    public function test_units_filter_by_type_and_origin(): void
    {
        $own = Unit::create([
            'name' => 'Costal', 'abbreviation' => 'cos', 'unit_type' => 'mass',
            'company_id' => $this->company->id, 'factor_to_base' => 25,
        ]);

        $units = fn(string $query) => collect(
            $this->actingInBranch($this->user)->getJson("/api/auth/admin/units?{$query}")->assertOk()->json('data')
        );

        $this->assertSame([$own->id], $units('origin=company')->pluck('id')->all());
        $system = $units('origin=system');
        $this->assertNotEmpty($system);
        $this->assertTrue($system->every(fn($u) => $u['company_id'] === null));

        $mass = $units('unit_type=mass');
        $this->assertContains($own->id, $mass->pluck('id')->all());
        $this->assertTrue($mass->every(fn($u) => $u['unit_type'] === 'mass'));
        $this->assertSame([$own->id], $units('unit_type=mass&origin=company')->pluck('id')->all());
    }
}
