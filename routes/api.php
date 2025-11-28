<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\WorkerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CustomerNoteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitControllerV2;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\InventoryCountDetailController;
use App\Http\Controllers\DeviceTokenController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rutas públicas (sin autenticación)
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Ruta pública para descargar PDFs con URL firmada
Route::get('inventory-counts/{id}/pdf', [InventoryCountController::class, 'generatePdf'])
    ->name('inventory-counts.pdf')
    ->middleware('signed');

// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    // Rutas de autenticación
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);

        Route::prefix('admin')->group(function () {
            // Controlador de permisos
            Route::get('permissions/by-resource', [PermissionsController::class, 'getPermissionsByResource']);
            Route::apiResource('permissions', PermissionsController::class);
            Route::apiResource('roles', RolesController::class);
            Route::apiResource('users', UserController::class);
            Route::apiResource('companies', CompanyController::class);
            Route::apiResource('locations', LocationController::class);
            Route::apiResource('workers', WorkerController::class);
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('suppliers', SupplierController::class);

            // Rutas adicionales para imágenes de productos
            Route::prefix('products/{product}')->group(function () {
                Route::get('images', [ProductController::class, 'getProductImages']);
                Route::delete('images/{image}', [ProductController::class, 'deleteProductImage']);
                Route::patch('images/order', [ProductController::class, 'updateImageOrder']);
            });

            // Product Packages Routes
            Route::apiResource('product-packages', \App\Http\Controllers\ProductPackageController::class);
            Route::post('product-packages/search-barcode', [\App\Http\Controllers\ProductPackageController::class, 'searchByBarcode']);
            Route::post('product-packages/generate-barcode', [\App\Http\Controllers\ProductPackageController::class, 'generateBarcode']);

            Route::get('purchases/stats', [PurchaseController::class, 'purchaseStats']);
            // Purchase Management Routes
            Route::apiResource('purchases', PurchaseController::class);

            // Purchase Status Management Routes
            Route::prefix('purchases')->group(function () {
                Route::post('{id}/advance', [PurchaseController::class, 'advance']);
                Route::post('{id}/revert', [PurchaseController::class, 'revert']);
                Route::post('{id}/transition', [PurchaseController::class, 'transitionTo']);
                Route::get('status/info', [PurchaseController::class, 'statusInfo']);
            });

            // Production Management Routes
            Route::apiResource('productions', ProductionController::class);

            // Sales Management Routes
            Route::apiResource('sales', SaleController::class);

            // Sale Status Management Routes
            Route::prefix('sales')->group(function () {
                Route::post('{id}/advance-status', [SaleController::class, 'advanceStatus']);
                Route::post('{id}/revert-status', [SaleController::class, 'revertStatus']);
                Route::post('{id}/cancel', [SaleController::class, 'cancel']);
            });

            // Adjustment Management Routes (Ajustes de inventario: mermas, extravíos, etc.)
            Route::apiResource('adjustments', AdjustmentController::class);

            // Inventory Management Routes
            Route::prefix('inventory')->group(function () {
                // Movements
                Route::post('movements', [InventoryController::class, 'processMovement']);
                Route::get('movements', [InventoryController::class, 'getMovements']);
                Route::get('movements/{id}', [InventoryController::class, 'getMovement']);

                // Transfers
                Route::post('transfers', [InventoryController::class, 'createTransfer']);
                Route::get('transfers', [InventoryController::class, 'getTransfers']);
                Route::get('transfers/{id}', [InventoryController::class, 'getTransfer']);
                Route::patch('transfers/{id}/approve', [InventoryController::class, 'approveTransfer']);
                Route::patch('transfers/{id}/confirm', [InventoryController::class, 'confirmTransfer']);

                // Stock queries
                Route::get('stock/current', [InventoryController::class, 'getCurrentStock']);

                // Reports (Legacy - deprecated, use /reports instead)
                Route::get('reports/inventory', [InventoryController::class, 'getInventoryReport']);
                Route::get('reports/kardex', [InventoryController::class, 'getKardexReport']);
                Route::get('reports/dashboard', [InventoryController::class, 'getDashboardStats']);
            });

            // Reports Routes (New consolidated reports)
            Route::prefix('reports')->group(function () {
                Route::get('dashboard', [App\Http\Controllers\ReportController::class, 'dashboard']);
                Route::get('inventory-stats', [App\Http\Controllers\ReportController::class, 'inventoryStats']);
                Route::get('recent-movements', [App\Http\Controllers\ReportController::class, 'recentMovements']);
                Route::get('movements-by-type', [App\Http\Controllers\ReportController::class, 'movementsByType']);
                Route::get('top-products', [App\Http\Controllers\ReportController::class, 'topProducts']);
                Route::get('sales-trend', [App\Http\Controllers\ReportController::class, 'salesTrend']);
                Route::get('sales-by-location', [App\Http\Controllers\ReportController::class, 'salesByLocation']);
                Route::get('low-stock-products', [App\Http\Controllers\ReportController::class, 'lowStockProducts']);
                Route::get('payment-methods', [App\Http\Controllers\ReportController::class, 'paymentMethods']);
            });

            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('units', UnitControllerV2::class);

            // Rutas de notas de clientes
            Route::apiResource('customer-notes', CustomerNoteController::class);
            Route::get('customer-notes/total-pending', [CustomerNoteController::class, 'getTotalPending']);

            // Rutas de conteo de inventario
            Route::get('inventory-counts/{id}/pdf-url', [InventoryCountController::class, 'generatePdfUrl']);
            Route::apiResource('inventory-counts', InventoryCountController::class);
            Route::apiResource('inventory-counts-details', InventoryCountDetailController::class);
        });
    });

    // Ruta para obtener el usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Rutas de tokens de dispositivos (notificaciones push)
    Route::prefix('device-tokens')->group(function () {
        Route::post('register', [DeviceTokenController::class, 'register']);
        Route::post('deactivate', [DeviceTokenController::class, 'deactivate']);
        Route::get('/', [DeviceTokenController::class, 'index']);
        Route::delete('{id}', [DeviceTokenController::class, 'destroy']);
    });




    // Aquí puedes agregar más rutas protegidas
    // Ejemplo:
    // Route::apiResource('products', ProductController::class);


});
