# Push Notifications - Low Stock Alert System

## Overview

This system sends push notifications to users when products fall below their minimum stock levels after completing an inventory count.

## Architecture

### Backend Components

1. **FirebaseService** (`app/Services/FirebaseService.php`)
   - Handles FCM (Firebase Cloud Messaging) integration
   - Methods:
     - `sendToUser(userId, title, body, data)` - Send to all active devices of a user
     - `sendToTokens(tokens[], title, body, data)` - Send to multiple FCM tokens
     - `sendToToken(token, title, body, data)` - Send to a single token
   - Automatically deactivates invalid tokens
   - Logs all notification attempts

2. **InventoryCountController** (`app/Http/Controllers/InventoryCountController.php`)
   - Modified `completeInventory()` method to check low stock after completion
   - New `checkLowStock($locationId)` method:
     - Queries products where `current_stock < minimum_stock`
     - Sends notification to user ID 1
     - Includes product count in notification body
     - Non-blocking: doesn't fail inventory completion if notification fails

3. **DeviceToken Model** (`app/Models/DeviceToken.php`)
   - Stores FCM tokens for each user device
   - Fields: `user_id`, `token`, `device_type`, `device_name`, `app_version`, `is_active`, `last_used_at`
   - Scopes: `active()`, `forUser($userId)`

### Frontend Components

1. **usePushNotifications Hook** (`hooks/usePushNotifications.ts`)
   - Already implemented and working
   - Handles:
     - FCM token registration
     - Permission requests (iOS, Android 13+)
     - Foreground/background notification handling
     - Token refresh
     - Backend token registration

## Flow Diagram

```
Inventory Count Completed
    ↓
Update product stocks in product_location
    ↓
Query products with current_stock < minimum_stock
    ↓
Generate notification message
    ↓
FirebaseService.sendToUser(1, title, body, data)
    ↓
Retrieve active DeviceTokens for user
    ↓
Send FCM message to each token
    ↓
Update last_used_at / Deactivate invalid tokens
    ↓
User receives push notification on device
```

## Configuration

### Backend Setup

1. **Install Firebase Admin SDK**
   ```bash
   sail composer require kreait/firebase-php
   ```

2. **Configure Firebase Credentials**
   - Download service account JSON from Firebase Console
   - Place at: `storage/app/firebase/service-account.json`
   - Or set custom path in `.env`:
     ```env
     FIREBASE_CREDENTIALS=/path/to/service-account.json
     ```

3. **Services Configuration** (`config/services.php`)
   ```php
   'firebase' => [
       'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),
   ],
   ```

### Frontend Setup

The frontend is already configured with:
- React Native Firebase integration
- Push notification handlers
- Token registration endpoint: `/auth/admin/device-tokens/register`

## Notification Data Structure

### Notification Payload
```php
[
    'title' => '⚠️ Alerta de Stock Bajo',
    'body' => 'Hay X productos con stock por debajo del mínimo requerido',
    'data' => [
        'type' => 'low_stock',
        'location_id' => '123',
        'products_count' => '5',
        'timestamp' => '2024-11-28T10:30:00.000Z',
    ]
]
```

### Notification Trigger
- **When**: Inventory count status changes to "completed"
- **Condition**: Any product has `current_stock < minimum_stock` in the location
- **Recipients**: Currently hardcoded to user ID 1
- **Frequency**: Once per inventory count completion

## Database Schema

### device_tokens Table
```sql
- id (bigint, primary key)
- user_id (bigint, foreign key to users)
- token (text, unique FCM token)
- device_type (varchar, 'ios'|'android'|'web')
- device_name (varchar, device model/name)
- app_version (varchar, app version)
- is_active (boolean, default true)
- last_used_at (timestamp, last successful notification)
- created_at, updated_at
```

### Low Stock Query
```sql
SELECT 
    products.id,
    products.name,
    products.code,
    product_location.current_stock,
    product_location.minimum_stock
FROM product_location
JOIN products ON product_location.product_id = products.id
WHERE product_location.location_id = ?
  AND product_location.current_stock < product_location.minimum_stock
  AND product_location.active = true
```

## API Endpoints

### Device Token Management

**Register Token**
```
POST /auth/admin/device-tokens/register
Content-Type: application/json
Authorization: Bearer {token}

{
    "token": "FCM_TOKEN_HERE",
    "device_type": "android",
    "device_name": "Samsung Galaxy S21",
    "app_version": "1.0.0"
}

Response: 201 Created
{
    "message": "Token registrado exitosamente",
    "data": { DeviceToken object }
}
```

**Deactivate Token**
```
POST /auth/admin/device-tokens/deactivate
{
    "token": "FCM_TOKEN_HERE"
}
```

**List User Tokens**
```
GET /auth/admin/device-tokens
```

**Delete Token**
```
DELETE /auth/admin/device-tokens/{id}
```

## Testing

### Test Scenario 1: Low Stock After Inventory Count

1. Create products with minimum stock levels
2. Start inventory count
3. Enter counted quantities below minimum
4. Complete inventory count
5. User ID 1 should receive push notification

### Test Scenario 2: No Low Stock

1. Complete inventory with all stock above minimum
2. No notification should be sent

### Test Scenario 3: Multiple Devices

1. User ID 1 logs in on multiple devices
2. Each device registers its FCM token
3. Complete inventory with low stock
4. All active devices receive notification

## Logging

All notification activities are logged:

```php
Log::info('Low stock notification sent', [
    'location_id' => 123,
    'products_count' => 5,
    'result' => [
        'success' => true,
        'sent_count' => 2,
        'failed_count' => 0,
    ],
]);
```

Check logs at: `storage/logs/laravel.log`

## Error Handling

### Invalid FCM Tokens
- Automatically detected via `Kreait\Firebase\Exception\Messaging\NotFound`
- Invalid tokens are deactivated (`is_active = false`)
- Won't be used in future notifications

### Firebase Not Configured
- Service gracefully degrades
- Logs warning: "Firebase not configured, skipping notification"
- Inventory completion continues normally

### Network Failures
- Logged but don't block inventory completion
- Individual token failures are tracked
- Returns summary with success/failure counts

## Future Enhancements

1. **Configurable Recipients**
   - Send to multiple users
   - Role-based notifications (managers, warehouse staff)
   - Per-location notification settings

2. **Notification Types**
   - Low stock alerts
   - Order confirmations
   - Inventory reminders
   - Price changes

3. **Scheduling**
   - Daily stock reports
   - Weekly summaries
   - Custom schedules per user

4. **Notification History**
   - Store sent notifications in database
   - Mark as read/unread
   - In-app notification center

5. **Product-Specific Settings**
   - Critical products with higher priority
   - Custom notification thresholds
   - Silence notifications for specific products

## Security Considerations

1. **Service Account JSON**
   - Never commit to version control
   - Stored in `storage/app/firebase/` (gitignored)
   - Secure file permissions (600)

2. **FCM Token Security**
   - Tokens belong to specific users
   - Can't send to other users' tokens
   - Automatic cleanup of invalid tokens

3. **Rate Limiting**
   - Consider implementing rate limits
   - Prevent notification spam
   - FCM has built-in quotas

## Troubleshooting

### Notifications Not Received

1. **Check Firebase credentials**
   ```bash
   ls -la storage/app/firebase/service-account.json
   ```

2. **Verify user has active tokens**
   ```sql
   SELECT * FROM device_tokens WHERE user_id = 1 AND is_active = true;
   ```

3. **Check Laravel logs**
   ```bash
   tail -f storage/logs/laravel.log
   ```

4. **Test FCM directly**
   ```php
   $firebase = app(FirebaseService::class);
   $firebase->sendToUser(1, 'Test', 'Test notification', []);
   ```

### Permission Issues

- **iOS**: Check Info.plist for notification permissions
- **Android 13+**: Requires POST_NOTIFICATIONS permission
- **Web**: Browser must support notifications

### Token Registration Fails

- Ensure Firebase app is properly configured
- Check google-services.json (Android) / GoogleService-Info.plist (iOS)
- Verify API endpoint is accessible
- Check authentication token

