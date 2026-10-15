<?php

namespace Tests\Feature\Logic;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\PurchaseV2;
use App\Models\Sale;
use App\Support\DocumentSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Folios consecutivos con contador atómico (document_sequences).
 */
class DocumentSequenceTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function sale(array $attributes = []): Sale
    {
        return Sale::create($attributes + [
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'sale_date' => now()->toDateString(), 'status' => 'closed', 'subtotal' => 10, 'total' => 10,
            'payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => 10,
        ]);
    }

    public function test_sequence_is_consecutive_per_scope(): void
    {
        $this->assertSame(1, DocumentSequence::next('test:a', fn () => 0));
        $this->assertSame(2, DocumentSequence::next('test:a', fn () => 0));
        $this->assertSame(1, DocumentSequence::next('test:b', fn () => 0));
        $this->assertSame(3, DocumentSequence::next('test:a', fn () => 0));
    }

    public function test_seed_is_only_used_when_the_scope_is_new(): void
    {
        $calls = 0;
        $seed = function () use (&$calls) {
            $calls++;

            return 41;
        };

        $this->assertSame(42, DocumentSequence::next('test:seed', $seed));
        $this->assertSame(43, DocumentSequence::next('test:seed', $seed));
        $this->assertSame(1, $calls);
    }

    public function test_rolled_back_document_releases_its_number(): void
    {
        try {
            DB::transaction(function () {
                DocumentSequence::next('test:rollback', fn () => 0);
                DocumentSequence::next('test:rollback', fn () => 0);
                throw new \RuntimeException('falla la venta');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, DocumentSequence::next('test:rollback', fn () => 0));
    }

    public function test_sale_numbers_are_consecutive_and_continue_after_existing_folios(): void
    {
        $prefix = 'SAL-' . now()->format('Ymd') . '-';

        // Folio emitido antes de existir el contador (o por la lógica vieja).
        $this->sale(['sale_number' => $prefix . '0007']);

        $this->assertSame($prefix . '0008', $this->sale()->sale_number);
        $this->assertSame($prefix . '0009', $this->sale()->sale_number);
    }

    public function test_sale_numbers_are_independent_per_company(): void
    {
        $prefix = 'SAL-' . now()->format('Ymd') . '-';
        $this->assertSame($prefix . '0001', $this->sale()->sale_number);
        $this->assertSame($prefix . '0002', $this->sale()->sale_number);

        $other = Company::factory()->create();
        $otherLocation = Location::factory()->create(['company_id' => $other->id]);

        $this->assertSame(
            $prefix . '0001',
            $this->sale(['company_id' => $other->id, 'location_id' => $otherLocation->id])->sale_number,
        );
    }

    public function test_soft_deleted_sales_still_count_for_the_seed(): void
    {
        $prefix = 'SAL-' . now()->format('Ymd') . '-';
        $this->sale(['sale_number' => $prefix . '0004'])->delete();

        $this->assertSame($prefix . '0005', $this->sale()->sale_number);
    }

    public function test_purchase_numbers_continue_after_the_highest_existing_one(): void
    {
        foreach (['PUR000009', 'PUR000010'] as $number) {
            PurchaseV2::create([
                'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
                'purchase_number' => $number, 'purchase_date' => now(), 'status' => 'draft',
                'total' => 0, 'payment_method' => 'cash', 'paid_from_cash' => false,
            ]);
        }

        $this->assertSame('PUR000011', PurchaseV2::generatePurchaseNumber());
        $this->assertSame('PUR000012', PurchaseV2::generatePurchaseNumber());
    }
}
