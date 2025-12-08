# Sistema de Tareas y Notificaciones

## Resumen del Sistema

El sistema de tareas y notificaciones de Plastigest está diseñado para automatizar la asignación de tareas y mantener informados a los usuarios sobre eventos importantes relacionados con inventario, compras, transferencias y operaciones.

---

## 📋 Sistema de Tareas

### Tipos de Tareas (TaskType)

1. **`inventory_count`** - Realizar conteo de inventario
2. **`receive_purchase`** - Recibir compra del proveedor
3. **`approve_transfer`** - Aprobar transferencia entre sucursales
4. **`send_transfer`** - Enviar transferencia
5. **`receive_transfer`** - Recibir transferencia
6. **`sales_report`** - Generar reporte de ventas
7. **`stock_check`** - Revisar discrepancias de stock
8. **`adjustment_review`** - Revisar ajustes de inventario
9. **`custom`** - Tarea personalizada

### Prioridades de Tareas (TaskPriority)

- **`urgent`** - Urgente (rojo)
- **`high`** - Alta (naranja/amarillo)
- **`medium`** - Media (azul)
- **`low`** - Baja (gris)

### Estados de Tareas (TaskStatus)

- **`pending`** - Pendiente
- **`in_progress`** - En proceso
- **`completed`** - Completada
- **`cancelled`** - Cancelada
- **`overdue`** - Vencida

### Estructura de una Tarea

```php
Task {
    id: int
    title: string
    description: string
    type: TaskType
    priority: TaskPriority
    status: TaskStatus
    due_date: datetime
    company_id: int
    location_id: int
    assigned_to: int (user_id) // Usuario individual asignado
    assigned_users: array // Usuarios adicionales asignados
    is_recurring: boolean
    recurrence_pattern: string
    completed_at: datetime
    completed_by: int
}
```

---

## 🔔 Sistema de Notificaciones

### Tipos de Notificaciones

1. **`task`** - Notificaciones de tareas
2. **`alert`** - Alertas importantes
3. **`reminder`** - Recordatorios
4. **`urgent`** - Notificaciones urgentes

### Estructura de una Notificación

```php
Notification {
    id: int
    user_id: int
    company_id: int
    title: string
    message: string
    type: string
    data: json // Datos adicionales contextuales
    read: boolean
    read_at: datetime
    expo_push_token: string
    expo_ticket_id: string
    expo_receipt_id: string
    delivered: boolean
    delivered_at: datetime
}
```

---

## 🔄 Flujos Actuales Implementados

### 1. Compras en Tránsito

**Trigger:** WhatsApp webhook actualiza compra a estado `in_transit`

**Flujo:**
1. Se recibe mensaje de WhatsApp del proveedor confirmando envío
2. Sistema actualiza compra a estado `in_transit`
3. Se crea tarea automática:
   - **Tipo:** `receive_purchase`
   - **Título:** "Recibir Compra del Proveedor [Nombre]"
   - **Prioridad:** `high`
   - **Asignado a:** Usuario de la ubicación destino
   - **Ubicación:** `location_origin_id` de la compra
4. Se envía notificación al usuario asignado:
   - **Tipo:** `task`
   - **Título:** "📦 Nueva Tarea: Recibir Compra"
   - **Mensaje:** "Se te ha asignado recibir la compra #[ID]"

**Archivo:** `app/Http/Controllers/WhatsAppWebhookController.php`

### 2. Recepción de Compra

**Trigger:** Usuario marca compra como recibida (estado `received`)

**Flujo:**
1. Usuario completa el proceso de recepción de compra
2. Sistema actualiza compra a estado `received`
3. Sistema marca la tarea asociada como `completed`:
   - Busca tarea por `location_origin_id` y tipo `receive_purchase`
   - Actualiza estado a `completed`
   - Registra `completed_at` y `completed_by`
4. Se envía notificación de compra recibida:
   - **Tipo:** `alert`
   - **Título:** "📦 Compra Recibida"
   - **Mensaje:** "La compra #[ID] del proveedor [Nombre] ha sido recibida"
   - **Destinatarios:** Usuarios con permiso `purchases_manage`

**Archivo:** `app/Http/Controllers/PurchaseController.php`

### 3. Discrepancias en Conteo de Inventario

**Trigger:** Usuario completa conteo de inventario con diferencias

**Flujo:**
1. Usuario finaliza conteo de inventario
2. Sistema compara `counted_quantity` vs `expected_quantity`
3. Si hay diferencias (discrepancias):
   
   **A. Notificación de Discrepancias:**
   - **Tipo:** `alert`
   - **Título:** "📊 Discrepancias en Conteo de Inventario"
   - **Mensaje:** Lista de productos con diferencias
   - **Destinatarios:** Usuarios con permiso `inventory_manage`
   
   **B. Creación de Tarea:**
   - **Tipo:** `stock_check`
   - **Título:** "Revisar Discrepancias - Conteo #[ID]"
   - **Prioridad:** `urgent` (si >10 discrepancias) o `high`
   - **Asignado a:** Usuario que realizó el conteo (`user_id`)
   - **Due date:** +1 día
   
   **C. Notificación de Tarea Asignada:**
   - **Tipo:** `task`
   - **Título:** "📋 Nueva Tarea: Revisar Discrepancias"
   - **Mensaje:** "Se te ha asignado revisar [N] discrepancia(s)"
   - **Destinatario:** Usuario asignado (`assigned_to`)

4. Sistema actualiza stock con `counted_quantity`

**Archivo:** `app/Http/Controllers/InventoryCountController.php`

### 4. Stock Bajo después de Conteo

**Trigger:** Se completa conteo de inventario

**Flujo:**
1. Después de completar conteo, sistema verifica productos con stock bajo
2. Consulta productos donde `current_stock < minimum_stock`
3. Si encuentra productos con stock bajo:
   - **Tipo:** `alert`
   - **Título:** "⚠️ Productos con Stock Bajo"
   - **Mensaje:** Lista de productos por debajo del mínimo
   - **Destinatarios:** Usuarios con permiso `inventory_manage`

**Archivo:** `app/Http/Controllers/InventoryCountController.php`

---

## 🔮 Notificaciones y Tareas Futuras

### 1. Transferencias entre Sucursales

#### Transferencia Pendiente de Aprobación
**Trigger:** Se crea transferencia con estado `pending`

**Notificación:**
- **Tipo:** `task`
- **Título:** "🔄 Aprobar Transferencia"
- **Mensaje:** "Transferencia #[ID] de [Origen] a [Destino] requiere aprobación"
- **Destinatarios:** Usuarios con permiso `transfers_approve`

**Tarea:**
- **Tipo:** `approve_transfer`
- **Prioridad:** `medium`
- **Asignado a:** Usuario con rol de aprobador en ubicación origen

#### Transferencia Aprobada - Envío
**Trigger:** Transferencia aprobada (estado `approved`)

**Notificación:**
- **Tipo:** `task`
- **Título:** "📤 Enviar Transferencia"
- **Mensaje:** "Preparar envío de transferencia #[ID] a [Destino]"
- **Destinatarios:** Personal de almacén en ubicación origen

**Tarea:**
- **Tipo:** `send_transfer`
- **Prioridad:** `high`
- **Asignado a:** Encargado de almacén ubicación origen

#### Transferencia en Tránsito - Recepción
**Trigger:** Transferencia enviada (estado `in_transit`)

**Notificación:**
- **Tipo:** `task`
- **Título:** "📥 Recibir Transferencia"
- **Mensaje:** "Transferencia #[ID] en camino desde [Origen]"
- **Destinatarios:** Personal de almacén en ubicación destino

**Tarea:**
- **Tipo:** `receive_transfer`
- **Prioridad:** `high`
- **Due date:** +2 días
- **Asignado a:** Encargado de almacén ubicación destino

#### Transferencia Recibida
**Trigger:** Transferencia completada (estado `received`)

**Notificación:**
- **Tipo:** `alert`
- **Título:** "✅ Transferencia Recibida"
- **Mensaje:** "Transferencia #[ID] recibida en [Destino]"
- **Destinatarios:** Usuario que creó la transferencia + usuarios con permiso `transfers_manage`

### 2. Producción

#### Orden de Producción Creada
**Trigger:** Se crea orden de producción

**Notificación:**
- **Tipo:** `task`
- **Título:** "🏭 Nueva Orden de Producción"
- **Mensaje:** "Producir [Cantidad] de [Producto]"
- **Destinatarios:** Personal de producción

**Tarea:**
- **Tipo:** `production_order`
- **Prioridad:** Según urgencia de la orden
- **Asignado a:** Supervisor de producción

#### Orden de Producción Completada
**Trigger:** Orden de producción finalizada

**Notificación:**
- **Tipo:** `alert`
- **Título:** "✅ Producción Completada"
- **Mensaje:** "Orden #[ID] completada: [Cantidad] [Producto]"
- **Destinatarios:** Usuario que creó la orden + inventario

### 3. Ventas

#### Venta con Stock Insuficiente
**Trigger:** Intento de venta sin stock suficiente

**Notificación:**
- **Tipo:** `alert`
- **Título:** "⚠️ Stock Insuficiente para Venta"
- **Mensaje:** "Producto [Nombre] solicitado: [Cantidad], disponible: [Stock]"
- **Destinatarios:** Usuarios con permiso `sales_manage` + `inventory_manage`

#### Reporte de Ventas Semanal
**Trigger:** Cron job - Lunes 9:00 AM

**Notificación:**
- **Tipo:** `reminder`
- **Título:** "📊 Reporte de Ventas Semanal"
- **Mensaje:** "Revisar ventas de la semana anterior"
- **Destinatarios:** Gerentes y administradores

**Tarea:**
- **Tipo:** `sales_report`
- **Prioridad:** `medium`
- **Recurrente:** Semanal
- **Asignado a:** Gerente de ventas

### 4. Ajustes de Inventario

#### Ajuste Creado
**Trigger:** Se crea ajuste de inventario (merma, corrección)

**Notificación:**
- **Tipo:** `alert`
- **Título:** "📝 Ajuste de Inventario Registrado"
- **Mensaje:** "Ajuste [Tipo]: [Productos afectados]"
- **Destinatarios:** Usuarios con permiso `inventory_manage` + supervisores

#### Ajuste Requiere Aprobación (>10 productos o >$1000)
**Trigger:** Ajuste con valores significativos

**Notificación:**
- **Tipo:** `task`
- **Título:** "⚠️ Aprobar Ajuste de Inventario"
- **Mensaje:** "Ajuste #[ID] requiere aprobación: [Monto/Cantidad]"
- **Destinatarios:** Gerentes con permiso `adjustments_approve`

**Tarea:**
- **Tipo:** `adjustment_review`
- **Prioridad:** `high`
- **Asignado a:** Gerente de inventario

### 5. Recordatorios de Tareas

#### Tarea Próxima a Vencer (24 horas)
**Trigger:** Cron job diario

**Notificación:**
- **Tipo:** `reminder`
- **Título:** "⏰ Tarea Vence Mañana"
- **Mensaje:** "Recordatorio: [Tarea] vence en 24 horas"
- **Destinatarios:** Usuario asignado

#### Tarea Vencida
**Trigger:** Cron job - Tarea pasa de due_date

**Notificación:**
- **Tipo:** `urgent`
- **Título:** "🚨 Tarea Vencida"
- **Mensaje:** "URGENTE: [Tarea] está vencida"
- **Destinatarios:** Usuario asignado + supervisor

**Actualización:**
- Estado de tarea cambia a `overdue`

### 6. Stock Bajo (Proactivo)

#### Stock Alcanza Nivel Mínimo
**Trigger:** Movimiento de inventario que reduce stock a nivel mínimo

**Notificación:**
- **Tipo:** `alert`
- **Título:** "⚠️ Stock en Nivel Mínimo"
- **Mensaje:** "[Producto] alcanzó stock mínimo: [Cantidad]"
- **Destinatarios:** Usuarios con permiso `inventory_manage` + `purchases_manage`

#### Stock por Debajo del Mínimo
**Trigger:** Movimiento de inventario que reduce stock bajo el mínimo

**Notificación:**
- **Tipo:** `urgent`
- **Título:** "🚨 Stock Crítico"
- **Mensaje:** "[Producto] BAJO MÍNIMO: [Cantidad] (mínimo: [Min])"
- **Destinatarios:** Usuarios con permiso `inventory_manage` + `purchases_manage`

**Tarea (Opcional):**
- **Tipo:** `create_purchase_order`
- **Prioridad:** `urgent`
- **Asignado a:** Encargado de compras

### 7. Proveedores

#### Compra Retrasada
**Trigger:** Compra en estado `pending` o `in_transit` después de fecha estimada

**Notificación:**
- **Tipo:** `alert`
- **Título:** "⏱️ Compra Retrasada"
- **Mensaje:** "Compra #[ID] del proveedor [Nombre] está retrasada"
- **Destinatarios:** Usuario que creó la compra + usuarios con permiso `purchases_manage`

### 8. Clientes

#### Cliente con Saldo Pendiente
**Trigger:** Cron job semanal + nueva venta a crédito

**Notificación:**
- **Tipo:** `reminder`
- **Título:** "💰 Saldo Pendiente de Cliente"
- **Mensaje:** "Cliente [Nombre] debe: $[Monto]"
- **Destinatarios:** Usuarios con permiso `sales_manage`

---

## 🎯 Servicios Centralizados

### NotificationService

**Ubicación:** `app/Services/NotificationService.php`

**Métodos Principales:**
- `create()` - Crear notificación individual
- `notifyUsersWithPermission()` - Notificar por permiso
- `notifyInventoryDiscrepancies()` - Notificar discrepancias
- `notifyLowStockAfterCount()` - Notificar stock bajo
- `notifyPurchaseInTransit()` - Notificar compra en tránsito
- `notifyPurchaseReceived()` - Notificar compra recibida

### TaskService

**Ubicación:** `app/Services/TaskService.php`

**Métodos Principales:**
- `notifyPurchaseTaskCreated()` - Notificar tarea de recibir compra
- `notifyDiscrepanciesTaskCreated()` - Notificar tarea de revisar discrepancias
- `sendTaskReminder()` - Enviar recordatorio de tarea
- `sendTaskOverdue()` - Enviar notificación de tarea vencida

---

## 📱 Integración con Expo Push Notifications

Todas las notificaciones se envían a través de Expo Push Notifications cuando el usuario tiene un `expo_push_token` registrado.

**Flujo:**
1. Usuario registra token al iniciar sesión (app móvil)
2. Sistema crea notificación en BD
3. Sistema envía push notification vía Expo
4. Se registra `expo_ticket_id` y posteriormente `expo_receipt_id`
5. Se marca como `delivered` cuando Expo confirma entrega

---

## 🔐 Sistema de Permisos

Las notificaciones respetan el sistema de permisos de Laravel (Spatie):

- `inventory_manage` - Gestionar inventario
- `purchases_manage` - Gestionar compras
- `sales_manage` - Gestionar ventas
- `transfers_manage` - Gestionar transferencias
- `transfers_approve` - Aprobar transferencias
- `adjustments_approve` - Aprobar ajustes

---

## 📊 Resumen de Implementación

### ✅ Implementado Actualmente

1. ✅ Tareas automáticas para recibir compras
2. ✅ Notificaciones de compras en tránsito
3. ✅ Notificaciones de compras recibidas
4. ✅ Tareas automáticas para revisar discrepancias
5. ✅ Notificaciones de discrepancias en inventario
6. ✅ Notificaciones de stock bajo después de conteo
7. ✅ Completado automático de tareas al recibir compra
8. ✅ Sistema de permisos para notificaciones
9. ✅ Integración con Expo Push Notifications
10. ✅ UI de lista de tareas con filtros
11. ✅ UI de detalle de tarea con completar
12. ✅ Botón de refresh en lista de tareas

### 🔄 Pendientes de Implementar

1. ⏳ Tareas y notificaciones de transferencias
2. ⏳ Tareas de producción
3. ⏳ Reportes de ventas automáticos
4. ⏳ Recordatorios de tareas (24h antes)
5. ⏳ Notificaciones de tareas vencidas
6. ⏳ Stock bajo proactivo (durante movimientos)
7. ⏳ Alertas de compras retrasadas
8. ⏳ Recordatorios de saldos pendientes
9. ⏳ Aprobaciones de ajustes significativos
10. ⏳ Tareas recurrentes automáticas

---

## 🚀 Próximos Pasos Recomendados

1. **Implementar recordatorios de tareas** (cron job diario)
2. **Agregar notificaciones de transferencias** (alta prioridad)
3. **Stock bajo proactivo** durante movimientos de inventario
4. **Tareas recurrentes** para reportes semanales/mensuales
5. **Dashboard de tareas vencidas** para supervisores
6. **Estadísticas de cumplimiento** de tareas por usuario
7. **Notificaciones configurables** por usuario (preferencias)
8. **Templates personalizables** de notificaciones
