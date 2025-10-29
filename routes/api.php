<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\WorkerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
            Route::apiResource('users', RolesController::class);
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

            // Purchase Management Routes
            Route::apiResource('purchases', PurchaseController::class);

            // Purchase Status Management Routes
            Route::prefix('purchases')->group(function () {
                Route::post('{id}/advance', [PurchaseController::class, 'advance']);
                Route::post('{id}/revert', [PurchaseController::class, 'revert']);
                Route::post('{id}/transition', [PurchaseController::class, 'transitionTo']);
                Route::get('status/info', [PurchaseController::class, 'statusInfo']);
            });

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

                // Reports
                Route::get('reports/inventory', [InventoryController::class, 'getInventoryReport']);
                Route::get('reports/kardex', [InventoryController::class, 'getKardexReport']);
                Route::get('reports/dashboard', [InventoryController::class, 'getDashboardStats']);
            });
        });
    });

    // Ruta para obtener el usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Aquí puedes agregar más rutas protegidas
    // Ejemplo:
    // Route::apiResource('products', ProductController::class);
});
