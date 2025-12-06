<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * Adjustment Model - Wrapper para movements con movement_reason = 'adjustment'
 * Representa ajustes de inventario (mermas, extravíos, ajustes, etc.)
 *
 * @mixin IdeHelperAdjustment
 * @property int $id
 * @property int $company_id
 * @property string $movement_type
 * @property string|null $movement_reason
 * @property string|null $reference_type
 * @property int|null $location_origin_id
 * @property int|null $location_destination_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int $user_id
 * @property string $movement_date
 * @property string|null $total_cost
 * @property string|null $status
 * @property array<array-key, mixed>|null $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AdjustmentDetail> $details
 * @property-read int|null $details_count
 * @property-read string $adjustment_date
 * @property-read string $adjustment_number
 * @property-read string|null $adjustment_type
 * @property-read string|null $comments
 * @property-read string|null $reason
 * @property-read float $total_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment betweenDates($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereLocationDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereLocationOriginId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereMovementReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereMovementType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjustment whereUserId($value)
 */
	class Adjustment extends \Eloquent {}
}

namespace App\Models{
/**
 * AdjustmentDetail Model - Detalles de ajustes de inventario
 * Usa la tabla movements_details
 *
 * @mixin IdeHelperAdjustmentDetail
 * @property int $id
 * @property int $movement_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property string|null $content
 * @property numeric $quantity
 * @property string $previous_stock
 * @property string $new_stock
 * @property numeric|null $unit_cost
 * @property numeric|null $total_cost
 * @property string|null $batch_number
 * @property string|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Adjustment $adjustment
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereNewStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail wherePreviousStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdjustmentDetail whereUpdatedAt($value)
 */
	class AdjustmentDetail extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperCompany
 * @property int $id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $rfc
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereRfc($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
 */
	class Company extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperCustomer
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $social_reason
 * @property string|null $rfc
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read float $total_pending
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CustomerNote> $notes
 * @property-read int|null $notes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CustomerNote> $pendingNotes
 * @property-read int|null $pending_notes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereRfc($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSocialReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUpdatedAt($value)
 */
	class Customer extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperLocation
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property int $is_main
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereIsMain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Location whereUpdatedAt($value)
 */
	class Location extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperPermission
 * @property int $id
 * @property string $name
 * @property string|null $resource
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereResource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 */
	class Permission extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperRole
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $is_system
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Worker> $workers
 * @property-read int|null $workers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 */
	class Role extends \Eloquent {}
}

namespace App\Models\Admin{
/**
 * @mixin IdeHelperWorker
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string|null $position
 * @property string|null $department
 * @property \Illuminate\Support\Carbon|null $hire_date
 * @property numeric|null $salary
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Company> $companies
 * @property-read int|null $companies_count
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Location> $locations
 * @property-read int|null $locations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereDepartment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereHireDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereSalary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Worker whereUserId($value)
 */
	class Worker extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperCategory
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $company_id
 * @property bool $is_active
 * @property-read \App\Models\Admin\Company $company
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereUpdatedAt($value)
 */
	class Category extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperCompany
 * @property int $id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $rfc
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Unit> $units
 * @property-read int|null $units_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereRfc($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
 */
	class Company extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperCustomerNote
 * @property int $id
 * @property int $customer_id
 * @property string $description
 * @property numeric $amount
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int $company_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\Admin\Customer $customer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereDueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerNote whereUpdatedAt($value)
 */
	class CustomerNote extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property \Illuminate\Support\Carbon $count_date
 * @property int|null $location_id
 * @property string $status
 * @property int $user_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $content
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryCountDetail> $details
 * @property-read int|null $details_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount byStatus($status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereCountDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCount whereUserId($value)
 */
	class InventoryCount extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $inventory_count_id
 * @property int $product_id
 * @property int $location_id
 * @property numeric $system_quantity
 * @property numeric|null $counted_quantity
 * @property numeric|null $difference
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryCount $inventoryCount
 * @property-read \App\Models\Admin\Location $location
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereCountedQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereDifference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereInventoryCountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereSystemQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCountDetail whereUpdatedAt($value)
 */
	class InventoryCountDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperInventoryTransfer
 * @property int $id
 * @property int $company_id
 * @property int $from_location_id
 * @property int $to_location_id
 * @property string $transfer_number
 * @property string $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property int|null $shipped_by
 * @property int|null $received_by
 * @property string $total_cost
 * @property string|null $notes
 * @property string|null $rejection_reason
 * @property string $requested_at
 * @property string|null $approved_at
 * @property string|null $shipped_at
 * @property string|null $received_at
 * @property string|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $approvedBy
 * @property-read \App\Models\Admin\Company $company
 * @property-read \App\Models\User|null $confirmedBy
 * @property-read \App\Models\Admin\Location|null $destinationLocation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryTransferDetail> $details
 * @property-read int|null $details_count
 * @property-read \App\Models\Admin\Location|null $originLocation
 * @property-read \App\Models\User $requestedBy
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer approved()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer byStatus($status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer completed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereCancelledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereFromLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereReceivedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereRequestedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereShippedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereShippedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereToLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereTransferNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransfer whereUpdatedAt($value)
 */
	class InventoryTransfer extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperInventoryTransferDetail
 * @property int $id
 * @property int $transfer_id
 * @property int $product_id
 * @property string $quantity_requested
 * @property string $quantity_shipped
 * @property string $quantity_received
 * @property numeric $unit_cost
 * @property numeric $total_cost
 * @property string|null $batch_number
 * @property string|null $expiry_date
 * @property string|null $notes
 * @property string|null $damage_report
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryTransfer|null $inventoryTransfer
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail approved()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail byStatus($status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail completed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereDamageReport($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereQuantityReceived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereQuantityRequested($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereQuantityShipped($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryTransferDetail whereUpdatedAt($value)
 */
	class InventoryTransferDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperMovement
 * @property int $id
 * @property int $company_id
 * @property string $movement_type
 * @property string|null $movement_reason
 * @property string|null $reference_type
 * @property int|null $location_origin_id
 * @property int|null $location_destination_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $movement_date
 * @property string|null $total_cost
 * @property string|null $status
 * @property array<array-key, mixed>|null $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MovementDetail> $details
 * @property-read int|null $details_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereLocationDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereLocationOriginId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereMovementReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereMovementType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Movement whereUserId($value)
 */
	class Movement extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperMovementDetail
 * @property int $id
 * @property int $movement_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property string|null $content
 * @property numeric $quantity
 * @property numeric $previous_stock
 * @property numeric $new_stock
 * @property numeric|null $unit_cost
 * @property numeric|null $total_cost
 * @property string|null $batch_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Movement $movement
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail byBatch($batchNumber)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail byProduct($productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereNewStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail wherePreviousStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementDetail withStock()
 */
	class MovementDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperProduct
 * @property int $id
 * @property int $company_id
 * @property int|null $category_id
 * @property int|null $unit_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property numeric|null $purchase_price
 * @property numeric|null $sale_price
 * @property bool $is_active
 * @property bool $for_sale
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $supplier_id
 * @property string $product_type Tipo de producto: raw_material=Materia Prima, processed=Producto Procesado, commercial=Producto Comercial
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Location> $activeLocations
 * @property-read int|null $active_locations_count
 * @property-read \App\Models\Category|null $category
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductImage> $galleryImages
 * @property-read int|null $gallery_images_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductImage> $images
 * @property-read int|null $images_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $ingredients
 * @property-read int|null $ingredients_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Admin\Location> $locations
 * @property-read int|null $locations_count
 * @property-read \App\Models\ProductImage|null $mainImage
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductIngredient> $productIngredients
 * @property-read int|null $product_ingredients_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductImage> $publicImages
 * @property-read int|null $public_images_count
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \App\Models\Unit|null $unit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $usedInProducts
 * @property-read int|null $used_in_products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereForSale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereProductType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product wherePurchasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSalePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 */
	class Product extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperProductImage
 * @property int $id
 * @property int $product_id
 * @property string $image_path Ruta relativa de la imagen
 * @property string|null $original_name Nombre original del archivo
 * @property string|null $alt_text Texto alternativo
 * @property string $image_type
 * @property int $sort_order Orden de visualización
 * @property string|null $size Dimensiones: 1920x1080
 * @property int|null $file_size Tamaño en bytes
 * @property string|null $mime_type image/jpeg, image/png
 * @property bool $is_public Visible públicamente
 * @property bool $show_in_catalog Mostrar en catálogo
 * @property array<array-key, mixed>|null $metadata Metadatos adicionales
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $file_size_formatted
 * @property-read string $full_url
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage forCatalog()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage gallery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage mainImage()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage public()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereAltText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereFileSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereImagePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereImageType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereShowInCatalog($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereUpdatedAt($value)
 */
	class ProductImage extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperProductIngredient
 * @property int $id
 * @property int $product_id
 * @property int $ingredient_id
 * @property numeric $quantity
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $ingredient
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereIngredientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductIngredient whereUpdatedAt($value)
 */
	class ProductIngredient extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperProductKardex
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property int $product_id
 * @property int $movement_id
 * @property int $movement_detail_id
 * @property string $operation_type
 * @property string $operation_reason
 * @property numeric $quantity
 * @property numeric $unit_cost
 * @property numeric $total_cost
 * @property numeric $previous_stock
 * @property numeric $new_stock
 * @property numeric $running_average_cost
 * @property string|null $document_number
 * @property string|null $batch_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $operation_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \App\Models\Admin\Location $location
 * @property-read \App\Models\Movement $movement
 * @property-read \App\Models\MovementDetail $movementDetail
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex betweenDates($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex byOperationType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex byProduct($productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereDocumentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereMovementDetailId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereNewStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereOperationDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereOperationReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereOperationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex wherePreviousStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereRunningAverageCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductKardex whereUserId($value)
 */
	class ProductKardex extends \Eloquent {}
}

namespace App\Models{
/**
 * Production Model - Wrapper para movements con movement_type = 'production' y movement_reason = 'production'
 *
 * @mixin IdeHelperProduction
 * @property int $id
 * @property int $company_id
 * @property string $movement_type
 * @property string|null $movement_reason
 * @property string|null $reference_type
 * @property int|null $location_origin_id
 * @property int|null $location_destination_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int $user_id
 * @property string $movement_date
 * @property string|null $total_cost
 * @property string|null $status
 * @property string|null $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MovementDetail> $details
 * @property-read int|null $details_count
 * @property-read string $production_date
 * @property-read string $production_number
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\Admin\Location|null $locationDestination
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production betweenDates($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereLocationDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereLocationOriginId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereMovementReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereMovementType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Production whereUserId($value)
 */
	class Production extends \Eloquent {}
}

namespace App\Models{
/**
 * Purchase Model - Wrapper para movements con movement_type = 'entry' y movement_reason = 'purchase'
 *
 * @mixin IdeHelperPurchase
 * @property int $id
 * @property int $company_id
 * @property string $movement_type
 * @property string|null $movement_reason
 * @property string|null $reference_type
 * @property int|null $location_origin_id
 * @property int|null $location_destination_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int $user_id
 * @property string $movement_date
 * @property string|null $total_cost
 * @property \App\Enums\PurchaseStatus|null $status
 * @property string|null $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseDetail> $details
 * @property-read int|null $details_count
 * @property-read string $purchase_date
 * @property-read string $purchase_number
 * @property array|null $supplier_info
 * @property-read float $total_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase betweenDates($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase byStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase completed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase draft()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase inTransit()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase received()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereLocationDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereLocationOriginId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereMovementReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereMovementType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Purchase whereUserId($value)
 */
	class Purchase extends \Eloquent {}
}

namespace App\Models{
/**
 * PurchaseDetail Model - Wrapper para movements_details relacionados con compras
 *
 * @mixin IdeHelperPurchaseDetail
 * @property int $id
 * @property int $movement_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property string|null $content
 * @property numeric $quantity
 * @property numeric $previous_stock
 * @property numeric $new_stock
 * @property numeric|null $unit_cost
 * @property numeric|null $total_cost
 * @property string|null $batch_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float $purchase_quantity
 * @property-read float $subtotal
 * @property-read float $unit_price
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Movement $movement
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Purchase $purchase
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail byBatch($batchNumber)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail byProduct($productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereNewStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail wherePreviousStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseDetail withStock()
 */
	class PurchaseDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperRole
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $is_system
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company|null $company
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 */
	class Role extends \Eloquent {}
}

namespace App\Models{
/**
 * Sale Model - Wrapper para movements con movement_type = 'exit' y movement_reason = 'sale'
 *
 * @mixin IdeHelperSale
 * @property int $id
 * @property int $company_id
 * @property string $movement_type
 * @property string|null $movement_reason
 * @property string|null $reference_type
 * @property int|null $location_origin_id
 * @property int|null $location_destination_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int $user_id
 * @property string $movement_date
 * @property string|null $total_cost
 * @property \App\Enums\SaleStatus|null $status
 * @property array<array-key, mixed>|null $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleDetail> $details
 * @property-read int|null $details_count
 * @property-read float|null $change_amount
 * @property-read array|null $customer_info
 * @property-read string|null $payment_method
 * @property-read float|null $received_amount
 * @property-read string $sale_date
 * @property-read string $sale_number
 * @property-read float $total_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Admin\Location|null $location
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale betweenDates($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale byCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale byLocation($locationId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale byStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale byType($type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale cLOSEDSales()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale cancelled()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale closed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale draft()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale processed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereLocationDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereLocationOriginId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereMovementReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereMovementType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sale whereUserId($value)
 */
	class Sale extends \Eloquent {}
}

namespace App\Models{
/**
 * SaleDetail Model - Wrapper para movement_details relacionados con ventas
 *
 * @mixin IdeHelperSaleDetail
 * @property int $id
 * @property int $movement_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property string|null $content
 * @property numeric $quantity
 * @property numeric $previous_stock
 * @property numeric $new_stock
 * @property numeric|null $unit_cost
 * @property numeric|null $total_cost
 * @property string|null $batch_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float $subtotal
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductKardex> $kardexRecords
 * @property-read int|null $kardex_records_count
 * @property-read \App\Models\Movement $movement
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Sale $sale
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail byBatch($batchNumber)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail byProduct($productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereNewStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail wherePreviousStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SaleDetail withStock()
 */
	class SaleDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperSupplier
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $social_reason
 * @property string|null $rfc
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Admin\Company $company
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier forCompany($companyId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereRfc($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereSocialReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereUpdatedAt($value)
 */
	class Supplier extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperUnit
 * @property int $id
 * @property string $name
 * @property string $abbreviation
 * @property int|null $company_id
 * @property int|null $base_unit_id
 * @property numeric $factor_to_base
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Unit|null $baseUnit
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Unit> $derivedUnits
 * @property-read int|null $derived_units_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereAbbreviation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereBaseUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereFactorToBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit whereUpdatedAt($value)
 */
	class Unit extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperUnitConversion
 * @property-read \App\Models\Unit|null $fromUnit
 * @property-read \App\Models\Unit|null $toUnit
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitConversion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitConversion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitConversion query()
 */
	class UnitConversion extends \Eloquent {}
}

namespace App\Models{
/**
 * @mixin IdeHelperUser
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

