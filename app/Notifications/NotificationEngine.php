<?php

namespace App\Notifications;

use App\Jobs\SendNotificationJob;

/**
 * Main entry point for the notification system.
 *
 * Usage:
 *   NotificationEngine::dispatch('low_stock', $companyId, ['product' => $product, ...]);
 *   NotificationEngine::dispatch('task_event', $companyId, ['task' => $task, 'sub_type' => 'assigned'], userId: $userId);
 */
class NotificationEngine
{
    /**
     * Dispatch a notification job asynchronously.
     *
     * @param string   $eventType      One of: low_stock | inventory_adjustment | inventory_count_discrepancy | purchase_update | task_event
     * @param int      $companyId      The company this notification belongs to
     * @param array    $contextData    Event-specific data passed to the template
     * @param int|null $userId         When set, only this user receives the notification (e.g. task assignments)
     */
    public static function dispatch(
        string $eventType,
        int    $companyId,
        array  $contextData,
        ?int   $userId = null,
    ): void {
        SendNotificationJob::dispatch($eventType, $companyId, $contextData, $userId)
            ->onQueue('notifications');
    }
}
