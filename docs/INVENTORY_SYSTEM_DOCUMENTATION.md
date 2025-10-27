# Inventory Management System Documentation

## Overview
This document describes the comprehensive inventory management system implemented for the Plastigest application. The system provides full inventory control including stock movements, transfers between locations, and detailed reporting.

## Database Structure

### Core Tables

#### 1. product_location_stock
Enhanced table for tracking stock levels by product and location:
- `current_stock`: Current available stock
- `reserved_stock`: Stock reserved for pending orders
- `available_stock`: Calculated available stock (current - reserved)
- `minimum_stock`: Minimum stock level for alerts
- `maximum_stock`: Maximum stock capacity
- `average_cost`: Running average cost of the product

#### 2. movements
Main table for all inventory movements:
- `movement_type`: entry, exit, adjustment
- `movement_reason`: Detailed reason (purchase, sale, transfer_in, etc.)
- `document_number`: Reference document
- `reference_id`/`reference_type`: Links to related records
- `total_amount`: Total value of the movement

#### 3. movements_details
Detailed information for each product in a movement:
- `quantity`: Quantity moved
- `unit_cost`: Cost per unit
- `previous_stock`/`new_stock`: Stock levels before and after
- `batch_number`: For batch tracking
- `expiry_date`: For perishable items

#### 4. inventory_transfers
Transfer requests between locations:
- `origin_location_id`/`destination_location_id`: Transfer endpoints
- `status`: pending, approved, completed, cancelled
- `transfer_number`: Unique identifier
- Approval workflow with timestamps

#### 5. inventory_transfer_details
Products included in transfers:
- `requested_quantity`: Initial request
- `approved_quantity`: After approval
- `confirmed_quantity`: Actually transferred

#### 6. product_kardex
Complete audit trail of all inventory movements:
- Tracks every stock change
- Maintains running average cost
- Links to originating movement
- Provides complete transaction history

## API Endpoints

### Base URL: `/api/auth/admin/inventory`

### Movements

#### POST `/movements`
Process inventory movement (entry, exit, adjustment)

**Request Body:**
```json
{
  "company_id": 1,
  "location_id": 1,
  "movement_type": "entry",
  "movement_reason": "purchase",
  "document_number": "PO-2024-001",
  "movement_date": "2024-10-24T10:00:00Z",
  "notes": "Monthly inventory purchase",
  "products": [
    {
      "product_id": 1,
      "quantity": 100,
      "unit_cost": 15.50,
      "batch_number": "BATCH-001",
      "expiry_date": "2025-12-31",
      "notes": "Premium quality"
    }
  ]
}
```

#### GET `/movements`
Get list of movements with filters

**Query Parameters:**
- `company_id`: Filter by company
- `location_id`: Filter by location
- `movement_type`: Filter by type
- `start_date`: Date range start
- `end_date`: Date range end
- `per_page`: Pagination size

#### GET `/movements/{id}`
Get specific movement details

### Transfers

#### POST `/transfers`
Create inventory transfer request

**Request Body:**
```json
{
  "company_id": 1,
  "origin_location_id": 1,
  "destination_location_id": 2,
  "transfer_type": "internal",
  "reason": "Stock balancing",
  "notes": "Move excess stock to warehouse",
  "products": [
    {
      "product_id": 1,
      "quantity": 50,
      "unit_cost": 15.50,
      "notes": "Handle with care"
    }
  ]
}
```

#### GET `/transfers`
Get list of transfers with filters

#### GET `/transfers/{id}`
Get specific transfer details

#### PATCH `/transfers/{id}/approve`
Approve transfer with quantities

**Request Body:**
```json
{
  "approvals": {
    "1": 45,  // detail_id: approved_quantity
    "2": 30
  }
}
```

#### PATCH `/transfers/{id}/confirm`
Confirm transfer and process stock movements

**Request Body:**
```json
{
  "confirmations": {
    "1": 45,  // detail_id: confirmed_quantity
    "2": 28
  }
}
```

### Stock Queries

#### GET `/stock/current`
Get current stock for a product at location

**Query Parameters:**
- `product_id`: Product ID
- `location_id`: Location ID
- `company_id`: Company ID

### Reports

#### GET `/reports/inventory`
Comprehensive inventory report by location

**Query Parameters:**
- `location_id`: Required
- `company_id`: Required
- `category_id`: Optional filter
- `low_stock`: Boolean, show low stock items
- `out_of_stock`: Boolean, show out of stock items

#### GET `/reports/kardex`
Product kardex (movement history)

**Query Parameters:**
- `product_id`: Required
- `location_id`: Required
- `company_id`: Required
- `start_date`: Optional
- `end_date`: Optional
- `operation_type`: Optional filter

#### GET `/reports/dashboard`
Dashboard statistics

## Business Logic

### Movement Processing
1. **Validation**: Check product exists, sufficient stock for exits
2. **Stock Calculation**: Update based on movement type
3. **Kardex Entry**: Create audit trail record
4. **Average Cost**: Calculate weighted average for entries
5. **Stock Update**: Update product_location_stock table

### Transfer Workflow
1. **Request**: User creates transfer request (pending status)
2. **Approval**: Manager reviews and approves quantities
3. **Confirmation**: Physical transfer confirmed, stock moved
4. **Movement Creation**: Automatic exit/entry movements generated

### Stock Tracking
- **Current Stock**: Physical inventory count
- **Reserved Stock**: Allocated for pending orders
- **Available Stock**: Current - Reserved
- **Average Cost**: Weighted average using FIFO method

## Error Handling

### Common Errors
- **Insufficient Stock**: Cannot exit more than available
- **Invalid Movement Type**: Only entry/exit/adjustment allowed
- **Transfer Status**: Cannot approve/confirm transfers in wrong status
- **Duplicate Batch**: Batch numbers must be unique per product

### Response Format
```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field": ["Validation error message"]
  }
}
```

## Models and Relationships

### Movement Model
```php
// Relationships
public function details(): HasMany
public function location(): BelongsTo
public function user(): BelongsTo
public function kardexRecords(): HasMany

// Scopes
public function scopeByCompany($query, $companyId)
public function scopeByType($query, $type)
public function scopeEntries($query)
```

### InventoryTransfer Model
```php
// Relationships
public function originLocation(): BelongsTo
public function destinationLocation(): BelongsTo
public function details(): HasMany
public function requestedBy(): BelongsTo

// Scopes
public function scopePending($query)
public function scopeApproved($query)
```

## Service Layer

### InventoryService
Main service class handling all inventory operations:

#### Key Methods
- `processMovement(array $data)`: Process any type of movement
- `createTransfer(array $data)`: Create transfer request
- `approveTransfer(int $id, array $approvals)`: Approve with quantities
- `confirmTransfer(int $id, array $confirmations)`: Complete transfer
- `getCurrentStock(int $productId, int $locationId, int $companyId)`: Get current stock
- `getInventoryReport(int $locationId, int $companyId, array $filters)`: Generate reports
- `getKardexReport(int $productId, int $locationId, int $companyId, array $filters)`: Kardex history

#### Private Helper Methods
- `processMovementDetail()`: Handle individual product movements
- `updateProductLocationStock()`: Update stock records
- `createKardexRecord()`: Create audit trail
- `calculateNewAverageCost()`: Weighted average calculation
- `generateTransferNumber()`: Unique transfer numbering

## Usage Examples

### Process Stock Entry
```php
$inventoryService = new InventoryService();

$movementData = [
    'company_id' => 1,
    'location_id' => 1,
    'movement_type' => 'entry',
    'movement_reason' => 'purchase',
    'document_number' => 'PO-2024-001',
    'products' => [
        [
            'product_id' => 1,
            'quantity' => 100,
            'unit_cost' => 15.50
        ]
    ]
];

$movement = $inventoryService->processMovement($movementData);
```

### Create Transfer
```php
$transferData = [
    'company_id' => 1,
    'origin_location_id' => 1,
    'destination_location_id' => 2,
    'products' => [
        [
            'product_id' => 1,
            'quantity' => 50,
            'unit_cost' => 15.50
        ]
    ]
];

$transfer = $inventoryService->createTransfer($transferData);
```

### Check Current Stock
```php
$stock = $inventoryService->getCurrentStock(
    productId: 1,
    locationId: 1,
    companyId: 1
);
```

## Security Considerations

### Authentication
- All endpoints require authentication via Sanctum
- User context automatically added to movements

### Authorization
- Company-based data isolation
- Location access controls
- Role-based permissions for approvals

### Data Integrity
- Transaction-wrapped operations
- Foreign key constraints
- Validation at multiple levels

## Performance Considerations

### Database Indexing
- Optimized indexes on frequently queried fields
- Composite indexes for multi-field queries
- Date range indexes for reporting

### Caching Strategy
- Stock levels cached for frequently accessed products
- Report results cached for dashboard views
- Cache invalidation on stock changes

### Pagination
- All list endpoints support pagination
- Configurable page sizes
- Efficient offset-based pagination

## Future Enhancements

### Planned Features
1. **Barcode Integration**: Scan products for movements
2. **Automated Reordering**: Auto-generate purchase orders
3. **Advanced Reporting**: Custom report builder
4. **Mobile App**: Warehouse management mobile interface
5. **Integration APIs**: ERP system integration
6. **Audit Trails**: Enhanced logging and audit capabilities

### Scalability Improvements
1. **Queue Processing**: Async movement processing
2. **Database Sharding**: Partition by company/location
3. **Microservices**: Split inventory into separate service
4. **Event Sourcing**: Event-based architecture for movements

## Testing

### Unit Tests
- Service layer methods
- Model relationships
- Validation rules
- Calculation logic

### Integration Tests
- API endpoint functionality
- Database transactions
- Error scenarios
- Workflow completeness

### Performance Tests
- Large dataset operations
- Concurrent user scenarios
- Report generation speed
- Database query optimization

## Troubleshooting

### Common Issues
1. **Stock Discrepancies**: Check kardex for complete history
2. **Transfer Stuck**: Verify status and approval workflow
3. **Performance Issues**: Review query patterns and indexes
4. **Data Inconsistency**: Run stock reconciliation reports

### Monitoring
- Set up alerts for negative stock
- Monitor movement processing times
- Track transfer completion rates
- Watch for validation failures
