<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Movement;
use App\Models\MovementDetail;
use App\Models\ProductKardex;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\Admin\Location;
use App\Models\Admin\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class InventoryService
{
    /**
     * Process an inventory movement (entry, exit, adjustment)
     */
    public function processMovement(array $data): Movement
    {
        return DB::transaction(function () use ($data) {
            // Create main movement record
            $movement = Movement::create([
                'company_id' => $data['company_id'],
                'location_id' => $data['location_id'],
                'movement_type' => $data['movement_type'],
                'movement_reason' => $data['movement_reason'],
                'document_number' => $data['document_number'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'total_amount' => 0, // Will be calculated from details
                'user_id' => $data['user_id'] ?? Auth::id(),
                'movement_date' => $data['movement_date'] ?? now(),
                'notes' => $data['notes'] ?? null
            ]);

            $totalAmount = 0;

            // Process each product in the movement
            foreach ($data['products'] as $productData) {
                $detail = $this->processMovementDetail($movement, $productData);
                $totalAmount += $detail->total_cost;
            }

            // Update total amount
            $movement->update(['total_amount' => $totalAmount]);

            return $movement->load('details.product', 'location', 'user');
        });
    }

    /**
     * Process individual movement detail and update stock/kardex
     */
    private function processMovementDetail(Movement $movement, array $productData): MovementDetail
    {
        $product = Product::findOrFail($productData['product_id']);

        // Get current stock
        $currentStock = $this->getCurrentStock(
            $product->id,
            $movement->location_id,
            $movement->company_id
        );

        // Calculate new stock based on movement type
        $quantity = $productData['quantity'];
        $unitCost = $productData['unit_cost'] ?? 0;

        switch ($movement->movement_type) {
            case 'entry':
                $newStock = $currentStock + $quantity;
                break;
            case 'exit':
                if ($currentStock < $quantity) {
                    throw new Exception("Insufficient stock for product: {$product->name}. Available: {$currentStock}, Required: {$quantity}");
                }
                $newStock = $currentStock - $quantity;
                break;
            case 'adjustment':
                $newStock = $quantity; // For adjustments, quantity is the final stock level
                $quantity = $newStock - $currentStock; // Adjust quantity to reflect the difference
                break;
            default:
                throw new Exception("Invalid movement type: {$movement->movement_type}");
        }

        // Create movement detail
        $movementDetail = MovementDetail::create([
            'movement_id' => $movement->id,
            'product_id' => $product->id,
            'quantity' => abs($quantity),
            'unit_cost' => $unitCost,
            'total_cost' => abs($quantity) * $unitCost,
            'previous_stock' => $currentStock,
            'new_stock' => $newStock,
            'batch_number' => $productData['batch_number'] ?? null,
            'expiry_date' => $productData['expiry_date'] ?? null,
            'notes' => $productData['notes'] ?? null
        ]);

        // Update or create product location stock record
        $this->updateProductLocationStock(
            $product->id,
            $movement->location_id,
            $movement->company_id,
            $newStock,
            $unitCost
        );

        // Create kardex record
        $this->createKardexRecord($movement, $movementDetail, $quantity);

        return $movementDetail;
    }

    /**
     * Create inventory transfer
     */
    public function createTransfer(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data) {
            $transfer = InventoryTransfer::create([
                'company_id' => $data['company_id'],
                'origin_location_id' => $data['origin_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'transfer_number' => $this->generateTransferNumber($data['company_id']),
                'transfer_type' => $data['transfer_type'] ?? 'internal',
                'status' => 'pending',
                'reason' => $data['reason'] ?? null,
                'total_quantity' => 0, // Will be calculated
                'requested_by' => $data['requested_by'] ?? Auth::id(),
                'requested_date' => now(),
                'notes' => $data['notes'] ?? null
            ]);

            $totalQuantity = 0;

            // Create transfer details
            foreach ($data['products'] as $productData) {
                InventoryTransferDetail::create([
                    'inventory_transfer_id' => $transfer->id,
                    'product_id' => $productData['product_id'],
                    'requested_quantity' => $productData['quantity'],
                    'approved_quantity' => null,
                    'confirmed_quantity' => null,
                    'unit_cost' => $productData['unit_cost'] ?? 0,
                    'total_cost' => $productData['quantity'] * ($productData['unit_cost'] ?? 0),
                    'status' => 'pending',
                    'notes' => $productData['notes'] ?? null
                ]);

                $totalQuantity += $productData['quantity'];
            }

            $transfer->update(['total_quantity' => $totalQuantity]);

            return $transfer->load('details.product', 'originLocation', 'destinationLocation');
        });
    }

    /**
     * Approve transfer
     */
    public function approveTransfer(int $transferId, array $approvals): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $approvals) {
            $transfer = InventoryTransfer::findOrFail($transferId);

            if ($transfer->status !== 'pending') {
                throw new Exception("Transfer is not in pending status");
            }

            // Update transfer details with approved quantities
            foreach ($approvals as $detailId => $approvedQuantity) {
                $detail = InventoryTransferDetail::findOrFail($detailId);
                $detail->update([
                    'approved_quantity' => $approvedQuantity,
                    'status' => $approvedQuantity > 0 ? 'approved' : 'rejected'
                ]);
            }

            // Update transfer status
            $transfer->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_date' => now()
            ]);

            return $transfer->load('details.product');
        });
    }

    /**
     * Confirm transfer and process inventory movements
     */
    public function confirmTransfer(int $transferId, array $confirmations): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $confirmations) {
            $transfer = InventoryTransfer::findOrFail($transferId);

            if ($transfer->status !== 'approved') {
                throw new Exception("Transfer must be approved before confirmation");
            }

            // Process exit from origin location
            $exitMovementData = [
                'company_id' => $transfer->company_id,
                'location_id' => $transfer->origin_location_id,
                'movement_type' => 'exit',
                'movement_reason' => 'transfer_out',
                'document_number' => $transfer->transfer_number,
                'reference_id' => $transfer->id,
                'reference_type' => 'inventory_transfer',
                'movement_date' => now(),
                'notes' => "Transfer to location: {$transfer->destinationLocation->name}",
                'products' => []
            ];

            // Process entry to destination location
            $entryMovementData = [
                'company_id' => $transfer->company_id,
                'location_id' => $transfer->destination_location_id,
                'movement_type' => 'entry',
                'movement_reason' => 'transfer_in',
                'document_number' => $transfer->transfer_number,
                'reference_id' => $transfer->id,
                'reference_type' => 'inventory_transfer',
                'movement_date' => now(),
                'notes' => "Transfer from location: {$transfer->originLocation->name}",
                'products' => []
            ];

            // Update transfer details with confirmed quantities and prepare movement data
            foreach ($confirmations as $detailId => $confirmedQuantity) {
                $detail = InventoryTransferDetail::findOrFail($detailId);

                if ($confirmedQuantity > $detail->approved_quantity) {
                    throw new Exception("Confirmed quantity cannot exceed approved quantity");
                }

                $detail->update([
                    'confirmed_quantity' => $confirmedQuantity,
                    'status' => $confirmedQuantity > 0 ? 'completed' : 'cancelled'
                ]);

                if ($confirmedQuantity > 0) {
                    // Add to exit movement
                    $exitMovementData['products'][] = [
                        'product_id' => $detail->product_id,
                        'quantity' => $confirmedQuantity,
                        'unit_cost' => $detail->unit_cost,
                        'notes' => $detail->notes
                    ];

                    // Add to entry movement
                    $entryMovementData['products'][] = [
                        'product_id' => $detail->product_id,
                        'quantity' => $confirmedQuantity,
                        'unit_cost' => $detail->unit_cost,
                        'notes' => $detail->notes
                    ];
                }
            }

            // Process movements if there are products to transfer
            if (!empty($exitMovementData['products'])) {
                $this->processMovement($exitMovementData);
                $this->processMovement($entryMovementData);
            }

            // Update transfer status
            $transfer->update([
                'status' => 'completed',
                'confirmed_by' => Auth::id(),
                'confirmed_date' => now()
            ]);

            return $transfer->load('details.product');
        });
    }

    /**
     * Get current stock for a product at a location
     */
    public function getCurrentStock(int $productId, int $locationId, int $companyId): float
    {
        // First check if there's a product_location_stock record
        $stockRecord = DB::table('product_location_stock')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->first();

        if ($stockRecord) {
            return $stockRecord->current_stock ?? 0;
        }

        // If no record exists, calculate from kardex
        $lastKardex = ProductKardex::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->orderBy('operation_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastKardex ? $lastKardex->new_stock : 0;
    }

    /**
     * Update or create product location stock record
     */
    private function updateProductLocationStock(int $productId, int $locationId, int $companyId, float $newStock, float $unitCost): void
    {
        DB::table('product_location_stock')->updateOrInsert(
            [
                'product_id' => $productId,
                'location_id' => $locationId,
                'company_id' => $companyId
            ],
            [
                'current_stock' => $newStock,
                'average_cost' => $this->calculateNewAverageCost($productId, $locationId, $companyId, $unitCost, $newStock),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Create kardex record
     */
    private function createKardexRecord(Movement $movement, MovementDetail $detail, float $quantity): ProductKardex
    {
        return ProductKardex::create([
            'company_id' => $movement->company_id,
            'location_id' => $movement->location_id,
            'product_id' => $detail->product_id,
            'movement_id' => $movement->id,
            'movement_detail_id' => $detail->id,
            'operation_type' => $movement->movement_type,
            'operation_reason' => $movement->movement_reason,
            'quantity' => abs($quantity),
            'unit_cost' => $detail->unit_cost,
            'total_cost' => abs($quantity) * $detail->unit_cost,
            'previous_stock' => $detail->previous_stock,
            'new_stock' => $detail->new_stock,
            'running_average_cost' => $this->calculateNewAverageCost(
                $detail->product_id,
                $movement->location_id,
                $movement->company_id,
                $detail->unit_cost,
                $detail->new_stock
            ),
            'document_number' => $movement->document_number,
            'batch_number' => $detail->batch_number,
            'expiry_date' => $detail->expiry_date,
            'user_id' => $movement->user_id,
            'operation_date' => $movement->movement_date
        ]);
    }

    /**
     * Calculate new average cost using weighted average method
     */
    private function calculateNewAverageCost(int $productId, int $locationId, int $companyId, float $unitCost, float $newStock): float
    {
        if ($newStock <= 0) {
            return 0;
        }

        // Get the last average cost
        $lastKardex = ProductKardex::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->orderBy('operation_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$lastKardex) {
            return $unitCost;
        }

        // If it's an entry, calculate weighted average
        // If it's an exit or adjustment, maintain the previous average
        $previousStock = $lastKardex->previous_stock;
        $previousAverageCost = $lastKardex->running_average_cost;

        if ($newStock > $previousStock) { // Entry
            $previousValue = $previousStock * $previousAverageCost;
            $newValue = ($newStock - $previousStock) * $unitCost;
            $totalValue = $previousValue + $newValue;

            return $totalValue / $newStock;
        }

        // For exits and adjustments, keep the same average cost
        return $previousAverageCost;
    }

    /**
     * Generate unique transfer number
     */
    private function generateTransferNumber(int $companyId): string
    {
        $prefix = 'TRF';
        $year = date('Y');
        $month = date('m');

        $lastTransfer = InventoryTransfer::where('company_id', $companyId)
            ->where('transfer_number', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastTransfer) {
            $lastNumber = (int) substr($lastTransfer->transfer_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $newNumber);
    }

    /**
     * Get inventory report for a location
     */
    public function getInventoryReport(int $locationId, int $companyId, array $filters = []): array
    {
        $query = DB::table('product_location_stock as pls')
            ->join('products as p', 'pls.product_id', '=', 'p.id')
            ->join('categories as c', 'p.category_id', '=', 'c.id')
            ->where('pls.location_id', $locationId)
            ->where('pls.company_id', $companyId)
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.code as product_code',
                'c.name as category_name',
                'pls.current_stock',
                'pls.reserved_stock',
                'pls.available_stock',
                'pls.minimum_stock',
                'pls.maximum_stock',
                'pls.average_cost',
                DB::raw('(pls.current_stock * pls.average_cost) as total_value')
            ]);

        // Apply filters
        if (!empty($filters['category_id'])) {
            $query->where('p.category_id', $filters['category_id']);
        }

        if (!empty($filters['low_stock'])) {
            $query->where('pls.current_stock', '<', DB::raw('pls.minimum_stock'));
        }

        if (!empty($filters['out_of_stock'])) {
            $query->where('pls.current_stock', '<=', 0);
        }

        return $query->orderBy('p.name')->get()->toArray();
    }

    /**
     * Get kardex report for a product
     */
    public function getKardexReport(int $productId, int $locationId, int $companyId, array $filters = []): array
    {
        $query = ProductKardex::with(['product', 'movement', 'user'])
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId);

        // Apply date filters
        if (!empty($filters['start_date'])) {
            $query->where('operation_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('operation_date', '<=', $filters['end_date']);
        }

        if (!empty($filters['operation_type'])) {
            $query->where('operation_type', $filters['operation_type']);
        }

        return $query->orderBy('operation_date')
            ->orderBy('id')
            ->get()
            ->toArray();
    }
}
