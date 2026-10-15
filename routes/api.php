<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductMovementController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ReplenishmentController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PurchaseV2Controller;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CustomerNoteController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\InventoryCountDetailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitControllerV2;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ProductPackageController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\BusinessReportController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\CashClosingController;
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
        // Sólo archivos dentro de storage/app/public. realpath resuelve "../"
        // (también llegan codificados como %2F), así que cualquier ruta que se
        // salga de esa carpeta —por ejemplo hacia el .env— responde 404.
        $root = realpath(storage_path('app/public'));
        $fullPath = $root ? realpath($root . DIRECTORY_SEPARATOR . $path) : false;

        if (!$fullPath || !str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
            abort(404);
        }

        return response()->file($fullPath);
    })->where('path', '.*');
    // Rate limit: cada ruta tiene su propio límite por correo y por IP (ver
    // AppServiceProvider::configureAuthRateLimiters).
    // Laravel inyecta automaticamente los headers X-RateLimit-Limit / -Remaining
    // en TODAS las respuestas (exitosas o no) y devuelve 429 con Retry-After
    // cuando se excede. Ver bootstrap/app.php para personalizar la respuesta 429.
    // El registro público está deshabilitado: las cuentas las crea un
    // administrador desde /auth/admin/users. Dejar /auth/register abierto
    // permitía que cualquiera creara un usuario con token válido.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');

    // Recuperación de contraseña vía código OTP enviado por correo
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:auth-forgot-password');
    Route::post('verify-reset-code', [PasswordResetController::class, 'verifyResetCode'])
        ->middleware('throttle:auth-verify-reset-code');
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:auth-reset-password');
});

// Ruta pública para descargar PDFs con URL firmada
Route::get('inventory-counts/{id}/pdf', [InventoryCountController::class, 'generatePdf'])
    ->name('inventory-counts.pdf')
    ->middleware('signed');

Route::get('sales-reports/{id}/pdf', [SalesReportController::class, 'generatePdf'])
    ->name('sales-reports.pdf')
    ->middleware('signed');

Route::get('purchases-v2/{purchases_v2}/pdf', [PurchaseV2Controller::class, 'generatePdf'])
    ->name('purchases-v2.pdf')
    ->middleware('signed');

Route::get('sales/{sale}/pdf', [SaleController::class, 'generatePdf'])
    ->name('sales.pdf')
    ->middleware('signed');

Route::get('cash-closings/{id}/pdf', [CashClosingController::class, 'generatePdf'])
    ->name('cash-closings.pdf')
    ->middleware('signed');

Route::get('products/{product}/labels/pdf', [ProductController::class, 'printLabels'])
    ->name('products.labels.pdf')
    ->middleware('signed');

// Documentos para imprimir (URL firmada; ver PrintController).
Route::get('print/sales/{sale}/ticket', [PrintController::class, 'saleTicket'])
    ->name('print.sale-ticket')->middleware('signed');
Route::get('print/cash-register-sessions/{session}/ticket', [PrintController::class, 'shiftTicket'])
    ->name('print.shift-ticket')->middleware('signed');
Route::get('print/inventory-transfers/{transfer}/pdf', [PrintController::class, 'transferPdf'])
    ->name('print.transfer')->middleware('signed');
Route::get('print/production-orders/{order}/sheet', [PrintController::class, 'productionSheet'])
    ->name('print.production-sheet')->middleware('signed');

// Reportes del dueño en PDF o Excel (URL firmada; ver BusinessReportController).
Route::get('business-reports/{report}/download', [BusinessReportController::class, 'download'])
    ->name('business-reports.download')->middleware('signed');

Route::get('inventory-movements/export', [InventoryMovementController::class, 'export'])
    ->name('inventory-movements.export')
    ->middleware('signed');

Route::get('product-packages/{package}/labels/pdf', [ProductPackageController::class, 'printLabels'])
    ->name('product-packages.labels.pdf')
    ->middleware('signed');

// WhatsApp Webhook Routes (must be public, no auth)
Route::prefix('webhooks')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle']);
});

// Versión mínima de la app nativa (pública: la app la consulta antes del login).
Route::get('app/version', [\App\Http\Controllers\AppVersionController::class, 'show']);

// Errores que reporta la app (error boundaries y manejadores globales): solo
// van al log del servidor. Límite por usuario en OperationsServiceProvider.
Route::post('auth/admin/client-errors', [\App\Http\Controllers\ClientErrorController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:client-errors']);

// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    // Rutas de autenticación
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('my-permissions', [AuthController::class, 'myPermissions']);
        Route::post('change-password', [AuthController::class, 'changePassword']);

        Route::prefix('admin')->group(function () {

            // Controlador de permisos
            Route::get('permissions/by-resource', [PermissionsController::class, 'getPermissionsByResource']);
            Route::apiResource('permissions', PermissionsController::class);
            Route::apiResource('roles', RolesController::class);
            // Quitar al usuario de la sucursal actual (Administración › Esta sucursal).
            Route::delete('users/{user}/current-location', [UserController::class, 'removeFromCurrentLocation']);
            Route::apiResource('users', UserController::class);
            Route::apiResource('companies', CompanyController::class);
            Route::apiResource('locations', LocationController::class);
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('inventory-types', InventoryTypeController::class);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('suppliers', SupplierController::class);
            Route::apiResource('customers', CustomerController::class);

            // Rutas de notificaciones
            Route::prefix('notifications')->group(function () {
                Route::get('unread-count', [NotificationController::class, 'unreadCount']);
                Route::post('mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
                Route::post('{id}/mark-as-read', [NotificationController::class, 'markAsRead']);
                Route::post('{id}/mark-as-unread', [NotificationController::class, 'markAsUnread']);
            });
            Route::apiResource('notifications', NotificationController::class);

            // Preferencias de notificaciones por empresa (con scope=location,
            // las de la sucursal actual)
            Route::prefix('notification-preferences')->group(function () {
                Route::get('/', [NotificationPreferenceController::class, 'index']);
                Route::patch('{eventType}', [NotificationPreferenceController::class, 'update']);
                Route::delete('{eventType}', [NotificationPreferenceController::class, 'destroy']);
                Route::post('reset', [NotificationPreferenceController::class, 'reset']);
                Route::get('{eventType}/eligible-users', [NotificationPreferenceController::class, 'eligibleUsers']);
            });

            // Rutas adicionales para imágenes de productos
            Route::prefix('products/{product}')->group(function () {
                // Trazabilidad: todo lo que ha pasado con las existencias del producto
                Route::get('movements', [ProductMovementController::class, 'index']);
                Route::get('images', [ProductController::class, 'getProductImages']);
                Route::delete('images/{image}', [ProductController::class, 'deleteProductImage']);
                Route::patch('images/order', [ProductController::class, 'updateImageOrder']);
                Route::get('labels/pdf-url', [ProductController::class, 'generatePdfUrl']);
            });
            Route::get('product-packages/{id}/labels/pdf-url', [ProductPackageController::class, 'generateLabelsUrl']);
            Route::apiResource("product-packages", ProductPackageController::class);


            // Purchase V2 Management Routes (New System with Real-time Draft Sync)
            Route::prefix('purchases-v2')->group(function () {
                Route::get('stats', [PurchaseV2Controller::class, 'stats']);
                Route::post('upsert-draft', [PurchaseV2Controller::class, 'upsertDraft']);
                Route::get('draft', [PurchaseV2Controller::class, 'getDraft']);
                Route::post('add-detail', [PurchaseV2Controller::class, 'addDetail']);
                Route::post('details/{detailId}/update', [PurchaseV2Controller::class, 'updateDetail']);
                Route::delete('details/{detailId}', [PurchaseV2Controller::class, 'removeDetail']);
                Route::post('{id}/confirm', [PurchaseV2Controller::class, 'confirm']);
                Route::post('{id}/mark-in-transit', [PurchaseV2Controller::class, 'markInTransit']);
                Route::post('{id}/receive', [PurchaseV2Controller::class, 'receive']);
                Route::post('{id}/cancel', [PurchaseV2Controller::class, 'cancel']);
                Route::post('{id}/resolve-discrepancy', [PurchaseV2Controller::class, 'resolveDiscrepancy']);
                Route::get('{id}/pdf-url', [PurchaseV2Controller::class, 'generatePdfUrl']);
            });
            Route::apiResource('purchases-v2', PurchaseV2Controller::class)->except(['store', 'update']);


            // Production Management Routes (Órdenes de Producción)
            Route::prefix('production-orders')->group(function () {
                Route::get('initial-data', [ProductionOrderController::class, 'getInitialData']);
                Route::get('today-stats', [ProductionOrderController::class, 'todayStats']);
                Route::post('{id}/complete', [ProductionOrderController::class, 'complete']);
                Route::post('{id}/cancel', [ProductionOrderController::class, 'cancel']);
                Route::get('{id}/variance', [ProductionOrderController::class, 'variance']);

                // Empaque: paso posterior, sólo disponible con la producción ya completada
                Route::get('{id}/packaging', [ProductionOrderController::class, 'packagingPlan']);
                Route::post('{id}/packaging', [ProductionOrderController::class, 'pack']);
                Route::post('{id}/packaging/finish', [ProductionOrderController::class, 'finishPackaging']);
                Route::delete('{id}/packaging/{packagingId}', [ProductionOrderController::class, 'revertPackaging']);
            });
            Route::apiResource('production-orders', ProductionOrderController::class);

            // Formulas Management Routes
            Route::prefix('formulas')->group(function () {
                Route::post('{id}/clone', [FormulaController::class, 'clone']);
            });
            Route::apiResource('formulas', FormulaController::class);

            // Sales Management Routes
            // Sale Status Management Routes and Stats (must be before apiResource)
            Route::prefix('sales')->group(function () {
                Route::get('initial-data', [SaleController::class, 'getInitialData']);
                Route::get('stats', [SaleController::class, 'salesStats']);
                Route::get('cash-register', [SaleController::class, 'cashRegister']);
                Route::post('{id}/add-payment', [SaleController::class, 'addPayment']);
                Route::post('{id}/cancel', [SaleController::class, 'cancel']);
                Route::get('{id}/pdf-url', [SaleController::class, 'generatePdfUrl']);
            });

            Route::apiResource('sales', SaleController::class);

            Route::prefix('sales-orders')->group(function () {
                Route::get('initial-data', [SalesOrderController::class, 'getInitialData']);
                Route::post('{id}/prepare', [SalesOrderController::class, 'prepare']);
                Route::post('{id}/ship', [SalesOrderController::class, 'ship']);
                Route::post('{id}/deliver', [SalesOrderController::class, 'deliver']);
                Route::post('{id}/cancel', [SalesOrderController::class, 'cancel']);
                Route::post('{id}/checkout', [SalesOrderController::class, 'checkout']);
            });

            Route::apiResource('sales-orders', SalesOrderController::class);

            // Cash Movement Routes
            // Cajas y turnos
            Route::apiResource('cash-registers', \App\Http\Controllers\CashRegisterController::class);
            // Punto de venta para cajeros
            Route::get('pos/catalog', [\App\Http\Controllers\PosController::class, 'catalog']);
            Route::post('pos/sales', [\App\Http\Controllers\PosController::class, 'store']);
            Route::prefix('cash-register-sessions')->controller(\App\Http\Controllers\CashRegisterSessionController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('current', 'current');
                Route::post('open', 'open');
                Route::get('{id}', 'show')->whereNumber('id');
                Route::post('{id}/close', 'close')->whereNumber('id');
            });

            Route::get('cash-movements/stats', [CashMovementController::class, 'stats']);
            Route::apiResource('cash-movements', CashMovementController::class);
            Route::get('cash-closings/{id}/pdf-url', [CashClosingController::class, 'generatePdfUrl']);
            Route::apiResource('cash-closings', CashClosingController::class);

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
            Route::apiResource('inventory-adjustments', InventoryAdjustmentController::class);

            // Existencia puntual (la usa la tabla editable de la app)
            Route::get('inventory/stock/current', [InventoryController::class, 'getCurrentStock']);

            // Kárdex general (todos los movimientos de inventario) y bitácora.
            Route::get('inventory-movements', [InventoryMovementController::class, 'index']);
            Route::get('inventory-movements/export-url', [InventoryMovementController::class, 'exportUrl']);
            Route::get('activity', [ActivityLogController::class, 'index']);
            // Qué mandar a cada sucursal y qué comprar o producir para mañana.
            Route::get('replenishment', [ReplenishmentController::class, 'index']);
            Route::get('home/summary', [HomeController::class, 'summary']);

            // URL firmadas de los documentos para imprimir.
            Route::get('sales/{id}/ticket-url', [PrintController::class, 'saleTicketUrl'])->whereNumber('id');
            Route::get('cash-register-sessions/{id}/ticket-url', [PrintController::class, 'shiftTicketUrl'])->whereNumber('id');
            Route::get('inventory-transfers/{id}/pdf-url', [PrintController::class, 'transferPdfUrl'])->whereNumber('id');
            Route::get('production-orders/{id}/sheet-url', [PrintController::class, 'productionSheetUrl'])->whereNumber('id');

            // Reportes del dueño: qué hay y la URL firmada de cada uno.
            Route::get('business-reports/options', [BusinessReportController::class, 'options']);
            Route::get('business-reports/{report}/url', [BusinessReportController::class, 'url']);
            Route::apiResource('customers', CustomerController::class);

            // Units routes
            Route::prefix('units')->group(function () {
                Route::get('grouped-by-base', [UnitControllerV2::class, 'getGroupedByBase']);
                Route::get('grouped-by-type', [UnitControllerV2::class, 'getGroupedByType']);
            });
            Route::apiResource('units', UnitControllerV2::class);

            // Rutas de notas de clientes. total-pending va antes del apiResource:
            // si no, {customer-note} del resource captura "total-pending" como id.
            Route::get('customer-notes/total-pending', [CustomerNoteController::class, 'getTotalPending']);
            Route::apiResource('customer-notes', CustomerNoteController::class);

            // === SALES REPORTS ===
            Route::apiResource('sales-reports', \App\Http\Controllers\SalesReportController::class);

            // Sistema legacy de inventory transfers (solo CRUD + workflow)
            // "Pedir a" de la solicitud; antes del apiResource para que
            // {inventory_transfer} no capture "origins".
            Route::get('inventory-transfers/origins', [InventoryTransferController::class, 'origins']);
            Route::apiResource('inventory-transfers', InventoryTransferController::class);

            // Rutas de workflow
            Route::prefix('inventory-transfers/{id}')->group(function () {
                Route::post('approve', [InventoryTransferController::class, 'approve']);
                Route::post('reject', [InventoryTransferController::class, 'reject']);
                Route::post('ship', [InventoryTransferController::class, 'ship']);
                Route::post('receive', [InventoryTransferController::class, 'receive']);
            });

            Route::prefix('reports')->group(function () {
                // Los demás reportes (dashboard, tendencia, métodos de pago…) leían
                // el esquema anterior y respondían 500, y la app no los usa; el
                // dashboard vigente es reports-v2.
                Route::get('inventory-stats', [App\Http\Controllers\ReportController::class, 'inventoryStats']);
                // Lo usa el menú de transferencias (TransferStats).
                Route::get('transfer-stats', [App\Http\Controllers\ReportController::class, 'transferStats']);
            });

            Route::prefix('reports-v2')->group(function () {
                Route::get('dashboard', [App\Http\Controllers\ReportController::class, 'dashboardV2']);
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
                Route::get('eligible-users', [TaskController::class, 'eligibleUsers']);
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
});
