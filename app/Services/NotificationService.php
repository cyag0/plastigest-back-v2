<?php

namespace App\Services;

use App\Models\Admin\Worker;
use App\Models\Notification;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Formatear los datos de notificación para que sean legibles
     *
     * @param array|null $data
     * @return array|null
     */
    private static function formatNotificationData(?array $data): ?array
    {
        if (!$data) {
            return null;
        }

        $formatted = [];

        foreach ($data as $key => $value) {
            // Mapear claves a labels legibles
            $label = self::getFieldLabel($key);

            // Formatear el valor según el tipo de dato
            $formattedValue = self::formatValue($key, $value);

            $formatted[] = [
                'label' => $label,
                'value' => $formattedValue,
            ];
        }

        return $formatted;
    }

    /**
     * Obtener el label legible para un campo
     *
     * @param string $key
     * @return string
     */
    private static function getFieldLabel(string $key): string
    {
        $labels = [
            // Producto
            'product_id' => 'ID del Producto',
            'product_name' => 'Producto',
            'product_code' => 'Código',

            // Stock
            'current_stock' => 'Stock Actual',
            'minimum_stock' => 'Stock Mínimo',

            // Ubicación
            'location_id' => 'ID de Sucursal',
            'location_name' => 'Sucursal',

            // Inventario
            'inventory_count_id' => 'ID de Conteo',
            'inventory_name' => 'Nombre del Conteo',
            'discrepancies_count' => 'Discrepancias',
            'products_count' => 'Productos',
            'expected_quantity' => 'Cantidad Esperada',
            'counted_quantity' => 'Cantidad Contada',
            'difference' => 'Diferencia',

            // Compras
            'purchase_id' => 'ID de Compra',
            'supplier_name' => 'Proveedor',
            'reference' => 'Referencia',
            'purchase_date' => 'Fecha de Compra',

            // Tarea
            'task_id' => 'ID de Tarea',
            'task_type' => 'Tipo de Tarea',
            'priority' => 'Prioridad',
            'due_date' => 'Fecha de Vencimiento',

            // General
            'type' => 'Tipo',
            'created_at' => 'Fecha de Creación',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Formatear el valor según el tipo
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private static function formatValue(string $key, $value): string
    {
        if (is_null($value)) {
            return 'N/A';
        }

        // Fechas
        if (str_ends_with($key, '_date') || str_ends_with($key, '_at')) {
            try {
                return \Carbon\Carbon::parse($value)->format('d/m/Y H:i');
            } catch (\Exception $e) {
                return $value;
            }
        }

        // Stock/Cantidades
        if (str_contains($key, 'stock') || str_contains($key, 'quantity')) {
            return number_format((float)$value, 2) . ' unidades';
        }

        // IDs - solo mostrar el número
        if (str_ends_with($key, '_id')) {
            return (string)$value;
        }

        // Prioridad
        if ($key === 'priority') {
            $priorities = [
                'low' => 'Baja',
                'medium' => 'Media',
                'high' => 'Alta',
                'urgent' => 'Urgente',
            ];
            return $priorities[$value] ?? ucfirst($value);
        }

        // Tipo de tarea
        if ($key === 'task_type') {
            $types = [
                'inventory_count' => 'Conteo de Inventario',
                'sales_report' => 'Reporte de Ventas',
                'stock_check' => 'Verificación de Stock',
            ];
            return $types[$value] ?? ucfirst(str_replace('_', ' ', $value));
        }

        // Por defecto, convertir a string
        return is_array($value) ? json_encode($value) : (string)$value;
    }

    /**
     * Crear notificación y enviar push a usuarios con un permiso específico
     *
     * @param int $companyId
     * @param string $permissionName
     * @param string $title
     * @param string $message
     * @param string $type
     * @param array|null $data
     * @return void
     */
    public static function notifyUsersWithPermission(
        int $companyId,
        string $permissionName,
        string $title,
        string $message,
        string $type = 'alert',
        ?array $data = null
    ): void {
        try {
            // Obtener todos los workers activos de la empresa
            $workers = Worker::where('company_id', $companyId)
                ->where('is_active', true)
                ->with(['user', 'role.permissions'])
                ->get();

            // Filtrar workers que tengan el permiso específico
            $workersWithPermission = $workers->filter(function ($worker) use ($permissionName) {
                if (!$worker->role) {
                    return false;
                }

                return $worker->role->permissions()
                    ->where('name', $permissionName)
                    ->exists();
            });

            if ($workersWithPermission->isEmpty()) {
                Log::info("No se encontraron usuarios con el permiso {$permissionName} en la empresa {$companyId}");
                return;
            }

            // Crear notificación para cada usuario y enviar push
            foreach ($workersWithPermission as $worker) {
                $user = $worker->user;

                // Crear notificación en DB con datos formateados
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'title' => $title,
                    'message' => $message,
                    'type' => $type,
                    'data' => self::formatNotificationData($data),
                ]);

                // Enviar notificación push con datos originales
                self::sendPushNotification($user->id, $title, $message, $data);
            }

            Log::info("Notificaciones enviadas a {$workersWithPermission->count()} usuarios con permiso {$permissionName}");
        } catch (\Exception $e) {
            Log::error('Error al enviar notificaciones: ' . $e->getMessage(), [
                'exception' => $e,
                'company_id' => $companyId,
                'permission' => $permissionName,
            ]);
        }
    }

    /**
     * Enviar notificación push a todos los dispositivos de un usuario
     *
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array|null $data
     * @return void
     */
    public static function sendPushNotification(
        int $userId,
        string $title,
        string $body,
        ?array $data = null
    ): void {
        try {
            // Obtener todos los tokens activos del usuario
            $deviceTokens = DeviceToken::where('user_id', $userId)
                ->where('is_active', true)
                ->get();

            if ($deviceTokens->isEmpty()) {
                Log::info("Usuario {$userId} no tiene tokens de dispositivo activos");
                return;
            }

            $tokens = $deviceTokens->pluck('token')->toArray();

            // Enviar a Expo Push Notifications
            $response = Http::post('https://exp.host/--/api/v2/push/send', [
                'to' => $tokens,
                'title' => $title,
                'body' => $body,
                'data' => $data ?? [],
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
            ]);

            if ($response->successful()) {
                Log::info("Push notification enviada a usuario {$userId}", [
                    'tokens_count' => count($tokens),
                    'title' => $title,
                ]);
            } else {
                Log::error("Error al enviar push notification", [
                    'user_id' => $userId,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error al enviar push notification: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Notificar sobre stock bajo de un producto
     *
     * @param int $companyId
     * @param int $locationId
     * @param object $product
     * @param float $currentStock
     * @param float $minimumStock
     * @return void
     */
    public static function notifyLowStock(
        int $companyId,
        int $locationId,
        object $product,
        float $currentStock,
        float $minimumStock
    ): void {
        $title = "⚠️ Stock Bajo: {$product->name}";
        $message = "El producto '{$product->name}' tiene stock bajo.\n" .
            "Stock actual: {$currentStock}\n" .
            "Stock mínimo: {$minimumStock}";

        $data = [
            'type' => 'low_stock',
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'location_id' => $locationId,
            'current_stock' => $currentStock,
            'minimum_stock' => $minimumStock,
        ];

        // Notificar a usuarios con permiso de gestión de inventario
        self::notifyUsersWithPermission(
            $companyId,
            'inventory_manage',
            $title,
            $message,
            'warning',
            $data
        );
    }

    /**
     * Crear notificación individual
     *
     * @param int $userId
     * @param int $companyId
     * @param string $title
     * @param string $message
     * @param string $type
     * @param array|null $data
     * @return Notification
     */
    public static function create(
        int $userId,
        int $companyId,
        string $title,
        string $message,
        string $type = 'info',
        ?array $data = null
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => self::formatNotificationData($data),
        ]);

        // Enviar push notification con datos originales
        self::sendPushNotification($userId, $title, $message, $data);

        return $notification;
    }

    /**
     * Notificar asignación de tarea
     */
    public static function sendTaskAssigned($task, $user): void
    {
        $title = "📋 Nueva tarea asignada";
        $message = "Se te ha asignado: {$task->title}";

        $data = [
            'type' => 'task_assigned',
            'task_id' => $task->id,
            'task_type' => $task->type,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toISOString(),
        ];

        self::create(
            $user->id,
            $task->company_id,
            $title,
            $message,
            'task',
            $data
        );
    }

    /**
     * Notificar recordatorio de tarea
     */
    public static function sendTaskReminder($task, $user): void
    {
        $title = "⏰ Recordatorio de tarea";
        $message = "La tarea '{$task->title}' vence pronto";

        $data = [
            'type' => 'task_reminder',
            'task_id' => $task->id,
            'task_type' => $task->type,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toISOString(),
        ];

        self::create(
            $user->id,
            $task->company_id,
            $title,
            $message,
            'reminder',
            $data
        );
    }

    /**
     * Notificar tarea vencida
     */
    public static function sendTaskOverdue($task, $user): void
    {
        $title = "🚨 Tarea vencida";
        $message = "La tarea '{$task->title}' está vencida";

        $data = [
            'type' => 'task_overdue',
            'task_id' => $task->id,
            'task_type' => $task->type,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toISOString(),
        ];

        self::create(
            $user->id,
            $task->company_id,
            $title,
            $message,
            'urgent',
            $data
        );
    }

    /**
     * Notificar discrepancias en el conteo de inventario
     */
    public static function notifyInventoryDiscrepancies(
        int $companyId,
        int $locationId,
        string $locationName,
        int $inventoryCountId,
        string $inventoryName,
        string $countDate,
        array $discrepancies
    ): void {
        try {
            $discrepanciesCount = count($discrepancies);

            $discrepanciesList = collect($discrepancies)->map(function ($disc) {
                $sign = $disc['difference'] > 0 ? '+' : '';
                return "• {$disc['product_name']}: {$sign}{$disc['difference']} (esperado: {$disc['expected_quantity']}, contado: {$disc['counted_quantity']})";
            })->take(5)->join("\n");

            $moreDiscrepancies = $discrepanciesCount > 5 ? "\n\n... y " . ($discrepanciesCount - 5) . " discrepancia(s) más" : "";

            $title = "📊 Discrepancias en Conteo de Inventario";
            $message = "Se encontraron {$discrepanciesCount} discrepancia(s) en el conteo '{$inventoryName}':\n\n{$discrepanciesList}{$moreDiscrepancies}";

            $data = [
                'type' => 'inventory_discrepancies',
                'inventory_count_id' => $inventoryCountId,
                'inventory_name' => $inventoryName,
                'location_id' => $locationId,
                'location_name' => $locationName,
                'discrepancies_count' => $discrepanciesCount,
                'discrepancies' => $discrepancies,
                'count_date' => $countDate,
            ];

            // Notificar a usuarios con permiso de inventario (user ID 1 incluido si tiene el permiso)
            self::notifyUsersWithPermission(
                $companyId,
                'inventory_manage',
                $title,
                $message,
                'alert',
                $data
            );

            Log::info('Inventory discrepancies notification sent', [
                'inventory_count_id' => $inventoryCountId,
                'discrepancies_count' => $discrepanciesCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending inventory discrepancies notification', [
                'inventory_count_id' => $inventoryCountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notificar productos con stock bajo después del conteo
     */
    public static function notifyLowStockAfterCount(
        int $companyId,
        int $locationId,
        string $locationName,
        array $lowStockProducts
    ): void {
        try {
            $lowStockCount = count($lowStockProducts);

            if ($lowStockCount === 0) {
                return;
            }

            $productsList = collect($lowStockProducts)->map(function ($product) {
                return "• {$product['name']}: {$product['current_stock']} (mínimo: {$product['minimum_stock']})";
            })->take(5)->join("\n");

            $moreProducts = $lowStockCount > 5 ? "\n\n... y " . ($lowStockCount - 5) . " producto(s) más" : "";

            $title = "⚠️ Productos con Stock Bajo";
            $message = "Se detectaron {$lowStockCount} producto(s) con stock por debajo del mínimo después del conteo de inventario:\n\n{$productsList}{$moreProducts}";

            $data = [
                'type' => 'low_stock_alert',
                'location_id' => $locationId,
                'location_name' => $locationName,
                'products_count' => $lowStockCount,
                'products' => $lowStockProducts,
            ];

            // Notificar a usuarios con permiso de inventario (incluyendo user ID 1 si tiene el permiso)
            self::notifyUsersWithPermission(
                $companyId,
                'inventory_manage',
                $title,
                $message,
                'warning',
                $data
            );

            Log::info('Low stock notification sent', [
                'location_id' => $locationId,
                'products_count' => $lowStockCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending low stock notification', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notificar que una compra está en tránsito
     */
    public static function notifyPurchaseInTransit(
        int $companyId,
        int $purchaseId,
        string $supplierName,
        string $reference,
        string $purchaseDate,
        array $products
    ): void {
        try {
            $productsList = collect($products)->map(function ($product) {
                return "• {$product['name']} (x{$product['quantity']})";
            })->join("\n");

            $title = "📦 Pedido en Camino";
            $message = "El proveedor {$supplierName} confirmó el envío de:\n\n{$productsList}";

            $data = [
                'type' => 'purchase_in_transit',
                'purchase_id' => $purchaseId,
                'supplier_name' => $supplierName,
                'reference' => $reference,
                'purchase_date' => $purchaseDate,
            ];

            // Enviar a usuarios con permiso de compras
            self::notifyUsersWithPermission(
                $companyId,
                'purchases_manage',
                $title,
                $message,
                'info',
                $data
            );

            // Enviar también al usuario con ID 1 para pruebas
            self::create(
                1,
                $companyId,
                $title,
                $message,
                'info',
                $data
            );

            Log::info('Purchase in transit notification sent', [
                'purchase_id' => $purchaseId
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending purchase in transit notification', [
                'purchase_id' => $purchaseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notificar que una compra fue recibida
     */
    public static function notifyPurchaseReceived(
        int $companyId,
        int $purchaseId,
        string $supplierName,
        string $reference,
        string $purchaseDate,
        array $products
    ): void {
        try {
            $productsList = collect($products)->map(function ($product) {
                return "• {$product['name']} (x{$product['quantity']})";
            })->join("\n");

            $title = "✅ Pedido Recibido";
            $message = "Se confirmó la recepción del pedido de {$supplierName}:\n\n{$productsList}";

            $data = [
                'type' => 'purchase_received',
                'purchase_id' => $purchaseId,
                'supplier_name' => $supplierName,
                'reference' => $reference,
                'purchase_date' => $purchaseDate,
            ];

            // Enviar a usuarios con permiso de compras
            self::notifyUsersWithPermission(
                $companyId,
                'purchases_manage',
                $title,
                $message,
                'success',
                $data
            );

            // Enviar también al usuario con ID 1 para pruebas
            self::create(
                1,
                $companyId,
                $title,
                $message,
                'success',
                $data
            );

            Log::info('Purchase received notification sent', [
                'purchase_id' => $purchaseId
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending purchase received notification', [
                'purchase_id' => $purchaseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notificar cuando se asigna una tarea
     */
    public static function notifyTaskAssigned(
        int $userId,
        int $companyId,
        int $taskId,
        string $taskTitle,
        string $priority,
        ?string $dueDate = null
    ): void {
        try {
            $priorityEmoji = match($priority) {
                'urgent' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '📌'
            };

            $priorityLabel = match($priority) {
                'urgent' => 'Urgente',
                'high' => 'Alta',
                'medium' => 'Media',
                'low' => 'Baja',
                default => $priority
            };

            $dueDateText = $dueDate 
                ? "\nVence: " . \Carbon\Carbon::parse($dueDate)->format('d/m/Y H:i')
                : '';

            $title = "{$priorityEmoji} Nueva Tarea Asignada";
            $message = "Se te ha asignado: \"{$taskTitle}\"\nPrioridad: {$priorityLabel}{$dueDateText}";

            $type = match($priority) {
                'urgent' => 'error',
                'high' => 'warning',
                default => 'info'
            };

            $data = [
                'type' => 'task_assigned',
                'task_id' => $taskId,
                'priority' => $priority,
            ];

            // Crear notificación en DB
            $notification = Notification::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'data' => self::formatNotificationData($data),
                'is_read' => false,
            ]);

            // Enviar push notification
            self::sendPushNotification($userId, $title, $message, $data);

            Log::info('Task assigned notification sent', [
                'user_id' => $userId,
                'task_id' => $taskId,
                'notification_id' => $notification->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending task assigned notification', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notificar cuando se completa una tarea
     */
    public static function notifyTaskCompleted(
        int $userId,
        int $companyId,
        int $taskId,
        string $taskTitle,
        string $completedByName
    ): void {
        try {
            $title = "✅ Tarea Completada";
            $message = "La tarea \"{$taskTitle}\" fue completada por {$completedByName}";

            $data = [
                'type' => 'task_completed',
                'task_id' => $taskId,
            ];

            // Crear notificación en DB
            $notification = Notification::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'title' => $title,
                'message' => $message,
                'type' => 'success',
                'data' => self::formatNotificationData($data),
                'is_read' => false,
            ]);

            // Enviar push notification
            self::sendPushNotification($userId, $title, $message, $data);

            Log::info('Task completed notification sent', [
                'user_id' => $userId,
                'task_id' => $taskId,
                'notification_id' => $notification->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending task completed notification', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notificar cuando se agrega un comentario a una tarea
     */
    public static function notifyTaskComment(
        int $userId,
        int $companyId,
        int $taskId,
        string $taskTitle,
        string $commentByName
    ): void {
        try {
            $title = "💬 Nuevo Comentario en Tarea";
            $message = "{$commentByName} comentó en la tarea \"{$taskTitle}\"";

            $data = [
                'type' => 'task_comment',
                'task_id' => $taskId,
            ];

            // Crear notificación en DB
            $notification = Notification::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'title' => $title,
                'message' => $message,
                'type' => 'info',
                'data' => self::formatNotificationData($data),
                'is_read' => false,
            ]);

            // Enviar push notification
            self::sendPushNotification($userId, $title, $message, $data);

            Log::info('Task comment notification sent', [
                'user_id' => $userId,
                'task_id' => $taskId,
                'notification_id' => $notification->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending task comment notification', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
