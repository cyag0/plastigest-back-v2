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
use App\Http\Controllers\PurchaseV2Controller;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CustomerNoteController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\InventoryCountDetailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitControllerV2;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductPackageController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ReminderController;

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
    // Ruta para servir archivos públicos con CORS
    Route::get('files/{path}', function ($path) {
        $fullPath = storage_path('app/public/' . $path);
        
        if (!file_exists($fullPath)) {
            abort(404);
        }
        
        return response()->file($fullPath);
    })->where('path', '.*');
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Ruta pública para descargar PDFs con URL firmada
Route::get('inventory-counts/{id}/pdf', [InventoryCountController::class, 'generatePdf'])
    ->name('inventory-counts.pdf')
    ->middleware('signed');

Route::get('sales-reports/{id}/pdf', [SalesReportController::class, 'generatePdf'])
    ->name('sales-reports.pdf')
    ->middleware('signed');

Route::get('products/{product}/labels/pdf', [ProductController::class, 'printLabels'])
    ->name('products.labels.pdf')
    ->middleware('signed');

// WhatsApp Webhook Routes (must be public, no auth)
Route::prefix('webhooks')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle']);
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

            Route::post("test", function (Request $request) {
                $data = $request->all();

                return response()->json([
                    'message' => json_encode($data),
                    'user' => $request->user(),
                ]);
            });

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
            Route::apiResource('customers', CustomerController::class);

            // Rutas de notificaciones
            Route::prefix('notifications')->group(function () {
                Route::get('unread-count', [NotificationController::class, 'unreadCount']);
                Route::post('mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
                Route::post('{id}/mark-as-read', [NotificationController::class, 'markAsRead']);
                Route::post('{id}/mark-as-unread', [NotificationController::class, 'markAsUnread']);
                Route::post('create-test-notifications', [NotificationController::class, 'createTestNotifications']);
            });
            Route::apiResource('notifications', NotificationController::class);

            // Rutas adicionales para imágenes de productos
            Route::prefix('products/{product}')->group(function () {
                Route::get('images', [ProductController::class, 'getProductImages']);
                Route::delete('images/{image}', [ProductController::class, 'deleteProductImage']);
                Route::patch('images/order', [ProductController::class, 'updateImageOrder']);
                Route::get('labels/pdf-url', [ProductController::class, 'generatePdfUrl']);
            });
            Route::apiResource("product-packages", ProductPackageController::class);


            // Purchase Status Management Routes
            Route::prefix('purchases')->group(function () {
                Route::get('stats', [PurchaseController::class, 'purchaseStats']);
                Route::get('products-list', [PurchaseController::class, 'getProductsList']);
                Route::post('{id}/advance', [PurchaseController::class, 'advance']);
                Route::post('{id}/revert', [PurchaseController::class, 'revert']);
                Route::post('{id}/transition', [PurchaseController::class, 'transitionTo']);
                Route::get('status/info', [PurchaseController::class, 'statusInfo']);
                Route::post('{id}/details', [PurchaseController::class, 'updateDetails']);
                Route::post('{id}/start-order', [PurchaseController::class, 'startOrder']);
                Route::post('{id}/receive', [PurchaseController::class, 'receivePurchase']);
            });

            // Purchase Management Routes
            Route::apiResource('purchases', PurchaseController::class);

            // Purchase V2 Management Routes (New System with Real-time Draft Sync)
            Route::prefix('purchases-v2')->group(function () {
                Route::post('upsert-draft', [PurchaseV2Controller::class, 'upsertDraft']);
                Route::get('draft', [PurchaseV2Controller::class, 'getDraft']);
                Route::post('add-detail', [PurchaseV2Controller::class, 'addDetail']);
                Route::post('details/{detailId}/update', [PurchaseV2Controller::class, 'updateDetail']);
                Route::delete('details/{detailId}', [PurchaseV2Controller::class, 'removeDetail']);
                Route::post('{id}/confirm', [PurchaseV2Controller::class, 'confirm']);
                Route::post('{id}/mark-in-transit', [PurchaseV2Controller::class, 'markInTransit']);
                Route::post('{id}/receive', [PurchaseV2Controller::class, 'receive']);
                Route::post('{id}/cancel', [PurchaseV2Controller::class, 'cancel']);
            });
            Route::apiResource('purchases-v2', PurchaseV2Controller::class)->except(['store', 'update']);


            // Production Management Routes
            Route::apiResource('productions', ProductionController::class);

            // Sales Management Routes
            // Sale Status Management Routes and Stats (must be before apiResource)
            Route::prefix('sales')->group(function () {
                Route::get('initial-data', [SaleController::class, 'getInitialData']);
                Route::get('stats', [SaleController::class, 'salesStats']);
                Route::get('cash-register', [SaleController::class, 'cashRegister']);
                Route::post('{id}/add-payment', [SaleController::class, 'addPayment']);
                Route::post('{id}/advance-status', [SaleController::class, 'advanceStatus']);
                Route::post('{id}/revert-status', [SaleController::class, 'revertStatus']);
                Route::post('{id}/cancel', [SaleController::class, 'cancel']);
            });

            Route::apiResource('sales', SaleController::class);

            // Expense Management Routes
            Route::prefix('expenses')->group(function () {
                Route::get('categories', [ExpenseController::class, 'categories']);
                Route::get('statistics', [ExpenseController::class, 'statistics']);
            });
            Route::apiResource('expenses', ExpenseController::class);

            // Reminder Management Routes
            Route::prefix('reminders')->group(function () {
                Route::get('types', [ReminderController::class, 'getTypes']);
                Route::get('recurrence-types', [ReminderController::class, 'getRecurrenceTypes']);
                Route::get('statistics', [ReminderController::class, 'statistics']);
                Route::get('users', [ReminderController::class, 'getUsers']);
                Route::post('{id}/complete', [ReminderController::class, 'markAsCompleted']);
            });
            Route::apiResource('reminders', ReminderController::class);

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

                // Reports
                Route::get('reports/inventory', [InventoryController::class, 'getInventoryReport']);
                Route::get('reports/kardex', [InventoryController::class, 'getKardexReport']);
                Route::get('reports/dashboard', [InventoryController::class, 'getDashboardStats']);
            });
            Route::apiResource('customers', CustomerController::class);

            // Units routes
            Route::prefix('units')->group(function () {
                Route::get('grouped-by-base', [UnitControllerV2::class, 'getGroupedByBase']);
                Route::get('grouped-by-type', [UnitControllerV2::class, 'getGroupedByType']);
            });
            Route::apiResource('units', UnitControllerV2::class);

            // Rutas de notas de clientes
            Route::apiResource('customer-notes', CustomerNoteController::class);
            Route::get('customer-notes/total-pending', [CustomerNoteController::class, 'getTotalPending']);

            // Rutas de consulta rápida (LEGACY - mantener temporalmente para compatibilidad)
            Route::get('inventory-transfers-pending-requests', [InventoryTransferController::class, 'pendingRequests']);
            Route::get('inventory-transfers-in-transit', [InventoryTransferController::class, 'inTransit']);

            // === NUEVO SISTEMA DE TRANSFERENCIAS BASADO EN MOVEMENTS ===
            // Rutas para los 4 módulos del frontend
            Route::get('movements/petitions', [MovementController::class, 'petitions']);
            Route::get('movements/shipments', [MovementController::class, 'shipments']);
            Route::get('movements/receipts', [MovementController::class, 'receipts']);
            Route::get('movements/transfers', [MovementController::class, 'transfers']);



            // Acciones de workflow de transferencias
            Route::prefix('movements/{id}')->group(function () {
                Route::post('approve', [MovementController::class, 'approve']);
                Route::post('reject', [MovementController::class, 'reject']);
                Route::post('ship', [MovementController::class, 'ship']);
                Route::post('receive', [MovementController::class, 'receive']);
            });

            // CRUD de movimientos/transferencias
            Route::apiResource('movements', MovementController::class);

            // === SALES REPORTS ===
            Route::apiResource('sales-reports', \App\Http\Controllers\SalesReportController::class);

            // === SISTEMA LEGACY DE INVENTORY TRANSFERS (mantener temporalmente) ===
            // Rutas para el flujo de módulos específicos (deben ir antes del apiResource)
            Route::get('inventory-transfers/petitions', [InventoryTransferController::class, 'petitions']);
            Route::get('inventory-transfers/shipments', [InventoryTransferController::class, 'shipments']);
            Route::get('inventory-transfers/receipts', [InventoryTransferController::class, 'receipts']);

            // Rutas de transferencias de inventario (apiResource debe ir después de las rutas específicas)
            Route::apiResource('inventory-transfers', InventoryTransferController::class);

            // Rutas de workflow
            Route::prefix('inventory-transfers/{id}')->group(function () {
                Route::post('approve', [InventoryTransferController::class, 'approve']);
                Route::post('reject', [InventoryTransferController::class, 'reject']);
                Route::post('ship', [InventoryTransferController::class, 'ship']);
                Route::post('receive', [InventoryTransferController::class, 'receive']);
            });

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
                Route::get('transfer-stats', [App\Http\Controllers\ReportController::class, 'transferStats']);
            });


            // Rutas de conteo de inventario
            Route::get('inventory-counts/{id}/pdf-url', [InventoryCountController::class, 'generatePdfUrl']);
            Route::apiResource('inventory-counts', InventoryCountController::class);
            Route::apiResource('inventory-counts-details', InventoryCountDetailController::class);

            // Rutas de reportes de ventas
            Route::get('sales-reports/{id}/pdf-url', [SalesReportController::class, 'generatePdfUrl']);

            // Rutas de tokens de dispositivos (notificaciones push)
            Route::prefix('device-tokens')->group(function () {
                Route::post('register', [DeviceTokenController::class, 'register']);
                Route::post('deactivate', [DeviceTokenController::class, 'deactivate']);
                Route::get('/', [DeviceTokenController::class, 'index']);
                Route::delete('{id}', [DeviceTokenController::class, 'destroy']);
            });

            // Rutas de tareas
            Route::prefix('tasks')->group(function () {
                Route::get('statistics', [TaskController::class, 'statistics']);
                Route::post('{task}/change-status', [TaskController::class, 'changeStatus']);
                Route::post('{task}/comments', [TaskController::class, 'addComment']);
            });
            Route::apiResource('tasks', TaskController::class);

            // Rutas de configuraciones de sucursal
            Route::prefix('locations/{location}/settings')->group(function () {
                Route::get('/', [SettingsController::class, 'show']);
                Route::put('/', [SettingsController::class, 'update']);
                Route::put('{section}', [SettingsController::class, 'updateSection']);
                Route::post('reset', [SettingsController::class, 'reset']);
            });
        });
    });

    // Ruta para obtener el usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
