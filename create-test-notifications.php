<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Notification;
use App\Models\User;
use App\Models\Company;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Crear Notificaciones de Prueba ===\n\n";

// Obtener el primer usuario
$user = User::first();
if (!$user) {
    echo "❌ No hay usuarios en la base de datos\n";
    exit(1);
}

echo "Usuario: {$user->name} (ID: {$user->id})\n";

// Obtener la primera empresa
$company = Company::first();
if (!$company) {
    echo "❌ No hay empresas en la base de datos\n";
    exit(1);
}

echo "Empresa: {$company->name} (ID: {$company->id})\n\n";

// Crear notificaciones de diferentes tipos
$notificationTypes = [
    [
        'title' => 'Bienvenido a PlastiGest',
        'message' => 'Gracias por usar nuestra plataforma de gestión',
        'type' => 'info',
        'data' => ['welcome' => true]
    ],
    [
        'title' => 'Nueva venta registrada',
        'message' => 'Se ha registrado una nueva venta por $1,250.00',
        'type' => 'success',
        'data' => ['sale_id' => 1, 'amount' => 1250]
    ],
    [
        'title' => 'Stock bajo detectado',
        'message' => 'El producto "Bolsa de plástico 20x30" tiene stock bajo (5 unidades)',
        'type' => 'warning',
        'data' => ['product_id' => 1, 'stock' => 5]
    ],
    [
        'title' => 'Tarea pendiente',
        'message' => 'Tienes una tarea pendiente: "Inventario mensual". Vence en 2 días',
        'type' => 'alert',
        'data' => ['task_id' => 1]
    ],
    [
        'title' => 'Pago de internet vencido',
        'message' => 'El recordatorio "Pago de internet" venció hace 1 día',
        'type' => 'error',
        'data' => ['reminder_id' => 1]
    ],
];

foreach ($notificationTypes as $notificationData) {
    $notification = Notification::create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'title' => $notificationData['title'],
        'message' => $notificationData['message'],
        'type' => $notificationData['type'],
        'data' => $notificationData['data'],
        'is_read' => false,
    ]);
    
    echo "✅ Creada: {$notification->title} ({$notification->type})\n";
}

echo "\n✅ Se crearon " . count($notificationTypes) . " notificaciones de prueba\n";
echo "Ahora puedes verlas en la app\n";
