# Resumen de Módulos Backend - Plastigest

> Documentación completa de todos los módulos, controladores, servicios y funcionalidades especiales del backend Laravel
> 
> **Fecha de actualización**: Diciembre 12, 2025

## Índice

1. [Arquitectura General](#arquitectura-general)
2. [Módulos de Operaciones](#módulos-de-operaciones)
3. [Módulos de Inventario](#módulos-de-inventario)
4. [Módulos de Administración](#módulos-de-administración)
5. [Servicios e Integraciones](#servicios-e-integraciones)
6. [Funcionalidades Especiales](#funcionalidades-especiales)
7. [Sistema de Tareas y Notificaciones](#sistema-de-tareas-y-notificaciones)
8. [Modelos y Base de Datos](#modelos-y-base-de-datos)

---

## Arquitectura General

### Stack Tecnológico
- **Framework**: Laravel 10.x
- **Base de datos**: MySQL
- **Autenticación**: Laravel Sanctum (API tokens)
- **Arquitectura**: MVC con Services Layer

### Estructura de Carpetas
```
app/
├── Console/Commands/          # Comandos artisan personalizados
├── Constants/                 # Constantes de la aplicación
├── Enums/                     # Enumeraciones (estados, tipos, etc.)
├── Http/Controllers/          # Controladores de API
│   ├── Admin/                 # Controladores administrativos
│   └── CrudController.php     # Controlador base CRUD
├── Models/                    # Modelos Eloquent
│   └── Admin/                 # Modelos administrativos
├── Policies/                  # Políticas de autorización
├── Services/                  # Lógica de negocio
├── Support/                   # Helpers y utilidades
└── Utils/                     # Funciones utilitarias
```

### Patrón de Diseño
Todos los controladores CRUD heredan de `CrudController` que proporciona:
- Operaciones CRUD estándar (index, store, show, update, destroy)
- Paginación automática
- Filtros y búsqueda
- Transformación con Resources
- Validaciones
- Soft deletes

---

## Módulos de Operaciones

### 1. Compras (Purchases)

**Controlador**: `PurchaseController.php`  
**Modelo**: `Purchase.php`, `PurchaseDetail.php`  
**Endpoints**: `/api/purchases`

#### Funcionalidad
Gestión completa de compras a proveedores con flujo de estados.

#### Estados del Flujo
1. **draft** (Borrador): Compra en edición
2. **ordered** (Ordenada): Compra confirmada y enviada al proveedor
3. **in_transit** (En tránsito): Productos en camino
4. **received** (Recibida): Productos recibidos, stock actualizado

#### 🔥 Funcionalidades Especiales

##### Envío de WhatsApp al Proveedor
**Cuándo se activa**: Al cambiar el estado de `draft` a `ordered`

```php
// Ubicación: PurchaseController::transitionTo()
// Línea ~603

if ($newStatus->value === 'ordered' && $previousStatus->value === 'draft') {
    $phone = $purchase->supplier->phone;
    if ($phone) {
        $whatsappService = new WhatsAppService();
        $whatsappService->sendPurchaseOrder($phone, $purchase);
    }
}
```

**Contenido del mensaje**:
- Número de orden de compra
- Nombre de la empresa/ubicación
- Lista de productos con cantidades y precios
- Total de la compra
- Fecha estimada de entrega
- Información de contacto

**Servicio**: `WhatsAppService::sendPurchaseOrder()`

##### Creación Automática de Tareas
**Cuándo se activa**: Al cambiar el estado a `in_transit`

Crea automáticamente una tarea asignada al usuario responsable de la ubicación para:
- Verificar la recepción de productos
- Confirmar cantidades
- Validar calidad
- Actualizar el stock

**Servicio**: `TaskService::createFromPurchase()`

##### Actualización de Inventario
**Cuándo se activa**: Al cambiar el estado a `received`

- Incrementa el stock en `product_location`
- Registra movimiento en `movements` y `movements_details`
- Crea registro de kardex en `product_kardex`
- Actualiza costos promedio ponderados

#### Endpoints Principales
- `GET /api/purchases` - Lista paginada
- `POST /api/purchases` - Crear compra (estado: draft)
- `GET /api/purchases/{id}` - Detalle de compra
- `PUT /api/purchases/{id}` - Editar (solo en draft)
- `DELETE /api/purchases/{id}` - Eliminar (solo en draft)
- `POST /api/purchases/{id}/advance` - Avanzar al siguiente estado
- `POST /api/purchases/{id}/revert` - Retroceder al estado anterior
- `POST /api/purchases/{id}/transition-to` - Cambiar a estado específico
- `GET /api/purchases/stats` - Estadísticas de compras

---

### 2. Ventas (Sales)

**Controlador**: `SaleController.php`  
**Modelo**: `Sale.php`, `SaleDetail.php`  
**Endpoints**: `/api/sales`

#### Funcionalidad
Sistema completo de ventas con punto de venta (POS).

#### Estados del Flujo
1. **draft** (Borrador): Venta en proceso
2. **completed** (Completada): Venta finalizada y pagada
3. **cancelled** (Cancelada): Venta cancelada

#### 🔥 Funcionalidades Especiales

##### Actualización Automática de Inventario
**Cuándo se activa**: Al completar una venta (estado `completed`)

- Decrementa stock en `product_location`
- Registra movimiento de salida en `movements`
- Crea registro de kardex
- Valida disponibilidad de stock antes de confirmar

##### Cálculo Automático de Totales
- Subtotal por producto (cantidad × precio)
- Descuentos (por producto o general)
- Impuestos (IVA, ISR, etc.)
- Total final
- Comisiones (si aplica)

##### Métodos de Pago
Soporta múltiples métodos:
- Efectivo
- Tarjeta (débito/crédito)
- Transferencia
- Crédito (cuentas por cobrar)
- Mixto (combinación de métodos)

##### Generación de Reportes
- Reporte diario de ventas
- Análisis por producto
- Análisis por vendedor
- Márgenes de ganancia

#### Endpoints Principales
- `GET /api/sales` - Lista paginada de ventas
- `POST /api/sales` - Crear venta
- `GET /api/sales/{id}` - Detalle de venta
- `PUT /api/sales/{id}` - Editar venta (solo draft)
- `DELETE /api/sales/{id}` - Eliminar venta
- `POST /api/sales/{id}/complete` - Completar venta
- `POST /api/sales/{id}/cancel` - Cancelar venta
- `GET /api/sales/stats` - Estadísticas

---

### 3. Producción (Production)

**Controlador**: `ProductionController.php`  
**Modelo**: `Production.php`  
**Endpoints**: `/api/production`

#### Funcionalidad
Gestión de órdenes de producción y manufactura.

#### Estados
1. **draft** - En planificación
2. **in_progress** - En producción
3. **completed** - Completada
4. **cancelled** - Cancelada

#### 🔥 Funcionalidades Especiales

##### Gestión de Ingredientes/Insumos
- Decrementa stock de materias primas utilizadas
- Incrementa stock de productos terminados
- Valida disponibilidad de ingredientes antes de iniciar

##### Fórmulas de Producción
Soporta recetas con:
- Productos ingrediente (materia prima)
- Cantidades por unidad producida
- Mermas y desperdicios esperados
- Conversiones de unidades

##### Cálculo de Costos de Producción
- Costo de materias primas
- Mano de obra (si configurado)
- Costos indirectos
- Costo final por unidad producida

#### Endpoints Principales
- `GET /api/production` - Lista de órdenes
- `POST /api/production` - Crear orden
- `GET /api/production/{id}` - Detalle
- `PUT /api/production/{id}` - Editar
- `POST /api/production/{id}/start` - Iniciar producción
- `POST /api/production/{id}/complete` - Completar producción

---

### 4. Reportes de Ventas (Sales Reports)

**Controlador**: `SalesReportController.php`  
**Modelo**: `SalesReport.php`  
**Endpoints**: `/api/sales-reports`

#### Funcionalidad
Generación de reportes periódicos de ventas.

#### 🔥 Funcionalidades Especiales

##### Generación Automática de Reportes
- Reportes diarios automáticos (mediante cron/scheduler)
- Reportes semanales
- Reportes mensuales
- Reportes por período personalizado

##### Análisis Incluidos
- Total de ventas
- Número de transacciones
- Ticket promedio
- Productos más vendidos
- Productos de baja rotación
- Ventas por vendedor
- Ventas por cliente
- Comparativa con períodos anteriores

##### Exportación a PDF
Generación de PDF con firma de URL temporal:

```php
GET /api/sales-reports/{id}/pdf
```

El PDF incluye:
- Gráficos de tendencias
- Tablas de análisis
- Métricas clave (KPIs)
- Comparativas

---

## Módulos de Inventario

### 5. Productos (Products)

**Controlador**: `ProductController.php`  
**Modelo**: `Product.php`, `ProductImage.php`, `ProductIngredient.php`  
**Endpoints**: `/api/products`

#### Funcionalidad
Catálogo maestro de productos.

#### 🔥 Funcionalidades Especiales

##### Sistema de Imágenes
- Múltiples imágenes por producto
- Imagen principal destacada
- Almacenamiento optimizado
- Generación de thumbnails

Modelo: `ProductImage.php`

##### Paquetes de Productos
Un paquete es una agrupación de productos individuales:

**Ejemplo**: 
- Paquete "Caja de Refrescos" = 24 × Refresco Individual

**Controlador**: `ProductPackageController.php`  
**Modelo**: `ProductPackage.php`

**Funcionalidad**:
- Definir productos padre e hijos
- Cantidad de cada producto hijo
- Venta y compra de paquetes
- Desagregación automática de inventario

##### Ingredientes/Fórmulas
Para productos fabricados, define los ingredientes necesarios:

**Modelo**: `ProductIngredient.php`

```php
Product "Pan" tiene ingredientes:
- Harina: 500g
- Agua: 300ml
- Levadura: 10g
- Sal: 5g
```

##### Códigos de Barras
- Soporte para múltiples formatos (EAN-13, UPC, Code 128)
- Generación de etiquetas
- Impresión de códigos de barras

```php
GET /api/products/{id}/labels/pdf?quantity=10
```

##### Control de Stock por Ubicación
Cada producto mantiene stock independiente por ubicación:

**Modelo**: `ProductLocation` (tabla `product_location`)

Campos:
- `current_stock` - Stock actual
- `minimum_stock` - Stock mínimo (alerta)
- `maximum_stock` - Stock máximo
- `reorder_point` - Punto de reorden

##### Kardex (Historial de Movimientos)
Registro detallado de cada movimiento de inventario:

**Modelo**: `ProductKardex.php`

Incluye:
- Fecha y hora
- Tipo de movimiento (entrada/salida)
- Cantidad
- Stock anterior/nuevo
- Costo unitario
- Referencia del movimiento
- Usuario responsable

#### Endpoints Principales
- `GET /api/products` - Lista de productos
- `POST /api/products` - Crear producto
- `GET /api/products/{id}` - Detalle de producto
- `PUT /api/products/{id}` - Actualizar producto
- `DELETE /api/products/{id}` - Eliminar producto
- `GET /api/products/{id}/kardex` - Historial de movimientos
- `GET /api/products/{id}/stock-by-location` - Stock por ubicaciones
- `POST /api/products/{id}/upload-image` - Subir imagen
- `GET /api/products/{id}/labels/pdf` - Generar etiquetas

---

### 6. Inventario (Inventory)

**Controlador**: `InventoryController.php`  
**Endpoints**: `/api/inventory`

#### Funcionalidad
Consultas y reportes de inventario en tiempo real.

#### Endpoints Principales
- `GET /api/inventory/current` - Inventario actual por ubicación
- `GET /api/inventory/low-stock` - Productos con stock bajo
- `GET /api/inventory/out-of-stock` - Productos agotados
- `GET /api/inventory/by-category` - Inventario agrupado por categoría
- `GET /api/inventory/valuation` - Valuación del inventario
- `GET /api/inventory/movements` - Movimientos recientes

---

### 7. Conteo de Inventario (Inventory Count)

**Controlador**: `InventoryCountController.php`, `InventoryCountDetailController.php`  
**Modelo**: `InventoryCount.php`, `InventoryCountDetail.php`  
**Endpoints**: `/api/inventory-counts`

#### Funcionalidad
Conteos físicos periódicos del inventario con detección de discrepancias.

#### Estados
1. **draft** - En proceso de conteo
2. **completed** - Completado y analizado
3. **adjusted** - Ajustes aplicados al inventario

#### 🔥 Funcionalidades Especiales

##### Notificaciones Push por Stock Bajo
**Cuándo se activa**: Al completar un conteo de inventario

Después de actualizar el stock basado en el conteo físico, el sistema:

1. Detecta productos con `current_stock < minimum_stock`
2. Genera notificación en base de datos
3. Envía notificación push a través de Firebase

**Ubicación**: `InventoryCountController::completeInventory()`

```php
// Verificar stock bajo y notificar
$lowStockProducts = Product::whereHas('locations', function($q) use ($locationId) {
    $q->where('location_id', $locationId)
      ->whereColumn('current_stock', '<', 'minimum_stock');
})->get();

if ($lowStockProducts->count() > 0) {
    NotificationService::createLowStockNotification(
        $userId,
        $lowStockProducts,
        $locationId
    );
}
```

**Servicio**: `NotificationService::createLowStockNotification()`  
**Integración**: `FirebaseService::sendToUser()`

La notificación incluye:
- Título: "Alerta de Stock Bajo"
- Cuerpo: "X productos están por debajo del stock mínimo"
- Datos: Lista de productos afectados
- Navegación: Al hacer clic, lleva al módulo de inventario

##### Detección de Discrepancias
Compara el conteo físico con el sistema:
- Cantidad esperada (sistema)
- Cantidad contada (física)
- Diferencia (variación)
- Porcentaje de variación

##### Generación Automática de Ajustes
Puede generar ajustes automáticos basados en las diferencias encontradas.

##### Creación de Tareas
Si hay discrepancias significativas, crea tareas para revisión:

**Servicio**: `TaskService::createFromInventoryCount()`

##### Exportación a PDF
Genera reporte PDF del conteo con:
- Productos contados
- Diferencias encontradas
- Resumen estadístico
- Firma del responsable

```php
GET /api/inventory-counts/{id}/pdf
```

#### Endpoints Principales
- `GET /api/inventory-counts` - Lista de conteos
- `POST /api/inventory-counts` - Crear conteo
- `GET /api/inventory-counts/{id}` - Detalle de conteo
- `POST /api/inventory-counts/{id}/complete` - Completar conteo
- `POST /api/inventory-counts/{id}/generate-adjustments` - Generar ajustes
- `GET /api/inventory-counts/{id}/pdf` - Generar PDF
- `GET /api/inventory-counts/{id}/discrepancies` - Ver discrepancias

---

### 8. Ajustes de Inventario (Adjustments)

**Controlador**: `AdjustmentController.php`  
**Modelo**: `Adjustment.php`, `AdjustmentDetail.php`  
**Endpoints**: `/api/adjustments`

#### Funcionalidad
Correcciones y ajustes de inventario por diferentes motivos.

#### Tipos de Ajustes
- **Merma**: Pérdida natural de producto
- **Daño**: Productos dañados
- **Vencimiento**: Productos caducados
- **Robo/Pérdida**: Productos extraviados
- **Corrección**: Errores de conteo
- **Otro**: Motivo personalizado

#### 🔥 Funcionalidades Especiales

##### Creación de Tareas de Revisión
**Cuándo se activa**: Al crear un ajuste significativo (>10 unidades)

**Servicio**: `TaskService::createFromAdjustment()`

Crea una tarea automática para que un supervisor revise y apruebe el ajuste antes de aplicarlo.

##### Actualización Automática de Stock
Al aprobar un ajuste:
- Incrementa o decrementa stock
- Registra en kardex
- Calcula impacto económico
- Genera reporte de ajustes

##### Aprobación por Niveles
Ajustes grandes requieren aprobación de supervisor o gerente.

#### Endpoints Principales
- `GET /api/adjustments` - Lista de ajustes
- `POST /api/adjustments` - Crear ajuste
- `GET /api/adjustments/{id}` - Detalle
- `POST /api/adjustments/{id}/approve` - Aprobar ajuste
- `POST /api/adjustments/{id}/reject` - Rechazar ajuste

---

### 9. Transferencias entre Ubicaciones (Transfers)

**Controlador**: `InventoryTransferController.php`, `MovementController.php`  
**Modelo**: `InventoryTransfer.php`, `InventoryTransferDetail.php`, `Transfer.php`, `Movement.php`  
**Endpoints**: `/api/transfers`, `/api/inventory-transfers`

#### Funcionalidad
Sistema completo de transferencias de productos entre ubicaciones/sucursales.

#### Flujo de Transferencias

##### Opción 1: Solicitud/Aprobación/Envío/Recepción
1. **Ubicación B solicita** productos a Ubicación A (petición)
2. **Ubicación A recibe solicitud** (recibo)
3. **Ubicación A aprueba o rechaza** la solicitud
4. **Ubicación A envía** los productos (envío/shipment)
5. **Ubicación B recibe** los productos (transferencia recibida)

##### Opción 2: Transferencia Directa
1. **Ubicación A crea transferencia** para Ubicación B
2. **Ubicación A envía** productos
3. **Ubicación B recibe** productos

#### Estados del Flujo
1. **draft** - Borrador/En preparación
2. **ordered** - Solicitada/Aprobada
3. **in_transit** - En tránsito (enviada)
4. **received** - Recibida
5. **rejected** - Rechazada
6. **cancelled** - Cancelada

#### 🔥 Funcionalidades Especiales

##### Peticiones (Requisitions)
**Modelo**: `Transfer.php` con `movement_reason = 'transfer_request'`

Una ubicación solicita productos a otra.

**Servicio**: `TransferService::createRequisition()`

##### Envíos (Shipments)
**Modelo**: `InventoryTransferShipment.php`

Cuando se envían productos:
- Decrementa stock en ubicación origen
- Crea registro de envío
- Genera documento de embarque
- Notifica a ubicación destino

**Servicio**: `MovementService::ship()`

##### Recepción de Transferencias
Cuando se reciben productos:
- Incrementa stock en ubicación destino
- Valida cantidades recibidas vs enviadas
- Permite reportar diferencias o daños
- Cierra el ciclo de transferencia

**Servicio**: `MovementService::receive()`

##### Notificaciones Automáticas
El sistema notifica automáticamente en cada etapa:
- Solicitud creada → Notifica a ubicación origen
- Solicitud aprobada → Notifica a ubicación solicitante
- Productos enviados → Notifica a ubicación destino
- Productos recibidos → Notifica a ubicación origen

**Servicio**: `NotificationService::notifyTransfer()`

##### Trazabilidad Completa
Registro detallado de:
- Quién solicitó
- Quién aprobó
- Quién envió
- Quién recibió
- Fechas y horas de cada acción
- Observaciones en cada etapa

#### Endpoints Principales
- `GET /api/transfers` - Lista de transferencias
- `POST /api/transfers` - Crear transferencia/petición
- `GET /api/transfers/{id}` - Detalle
- `POST /api/transfers/{id}/approve` - Aprobar solicitud
- `POST /api/transfers/{id}/reject` - Rechazar solicitud
- `POST /api/transfers/{id}/ship` - Enviar productos
- `POST /api/transfers/{id}/receive` - Recibir productos
- `GET /api/transfers/pending-to-receive` - Transferencias por recibir
- `GET /api/transfers/pending-to-approve` - Solicitudes por aprobar

---

### 10. Movimientos (Movements)

**Controlador**: `MovementController.php`  
**Modelo**: `Movement.php`, `MovementDetail.php`  
**Endpoints**: `/api/movements`

#### Funcionalidad
Registro unificado de todos los movimientos de inventario.

#### Tipos de Movimientos

##### Por Tipo (movement_type)
- **entry** - Entrada de productos
- **exit** - Salida de productos
- **transfer** - Transferencia entre ubicaciones
- **adjustment** - Ajuste de inventario

##### Por Razón (movement_reason)
- **purchase** - Compra a proveedor
- **sale** - Venta a cliente
- **production** - Producción/manufactura
- **transfer_in** - Transferencia entrante
- **transfer_out** - Transferencia saliente
- **adjustment** - Ajuste
- **return** - Devolución
- **loss** - Pérdida/merma

#### 🔥 Funcionalidades Especiales

##### Servicio de Movimientos
**Servicio**: `MovementService.php`

Centraliza la lógica de:
- Validación de stock disponible
- Actualización de stock en `product_location`
- Registro de kardex
- Cálculo de costos
- Aplicación de transacciones atómicas (DB transactions)

##### Registro Automático de Kardex
Cada movimiento genera automáticamente entradas en:
- `movements` - Encabezado del movimiento
- `movements_details` - Detalle por producto
- `product_kardex` - Historial detallado por producto

##### Validación de Stock
Antes de cualquier movimiento de salida, valida:
- Stock disponible suficiente
- Producto activo
- Ubicación válida
- Permisos del usuario

##### Integridad Transaccional
Todos los movimientos se ejecutan en transacciones de base de datos:

```php
DB::beginTransaction();
try {
    // Validar stock
    // Actualizar product_location
    // Registrar movement
    // Registrar movement_details
    // Registrar kardex
    DB::commit();
} catch (Exception $e) {
    DB::rollBack();
    throw $e;
}
```

#### Endpoints Principales
- `GET /api/movements` - Lista de movimientos
- `GET /api/movements/{id}` - Detalle de movimiento
- `GET /api/movements/by-product/{productId}` - Movimientos de un producto
- `GET /api/movements/by-type/{type}` - Movimientos por tipo
- `GET /api/movements/summary` - Resumen de movimientos

---

## Módulos de Administración

### 11. Empresas (Companies)

**Controlador**: `Admin/CompanyController.php`  
**Modelo**: `Admin/Company.php`  
**Endpoints**: `/api/auth/admin/companies`

#### Funcionalidad
Gestión de empresas en el sistema multi-empresa.

#### 🔥 Funcionalidades Especiales

##### Multi-Tenancy
El sistema soporta múltiples empresas completamente aisladas:
- Cada empresa tiene sus propios:
  - Productos
  - Inventario
  - Ventas y compras
  - Usuarios y trabajadores
  - Ubicaciones/sucursales
  - Configuraciones

##### Contexto de Empresa Actual
**Helper**: `CurrentCompany::get()`

Obtiene la empresa seleccionada del usuario autenticado.

Usado en middleware para:
- Filtrar datos automáticamente
- Validar permisos
- Aislar información

#### Campos Principales
- `name` - Nombre de la empresa
- `business_name` - Razón social
- `tax_id` - RFC/NIT
- `email`, `phone` - Contacto
- `address` - Dirección fiscal
- `logo` - Logotipo
- `settings` (JSON) - Configuraciones personalizadas

---

### 12. Ubicaciones (Locations)

**Controlador**: `Admin/LocationController.php`  
**Modelo**: `Admin/Location.php`  
**Endpoints**: `/api/auth/admin/locations`

#### Funcionalidad
Gestión de sucursales, almacenes o puntos de venta.

#### 🔥 Funcionalidades Especiales

##### Contexto de Ubicación Actual
**Helper**: `CurrentLocation::get()`

Similar a `CurrentCompany`, mantiene el contexto de la ubicación desde donde el usuario está trabajando.

##### Tipos de Ubicaciones
- **Sucursal** - Punto de venta
- **Almacén** - Solo almacenamiento
- **Planta** - Producción/manufactura
- **Matriz** - Oficina central

##### Inventario Independiente
Cada ubicación mantiene su propio inventario en `product_location`.

#### Campos Principales
- `company_id` - Empresa propietaria
- `name` - Nombre de la ubicación
- `code` - Código único
- `type` - Tipo de ubicación
- `address` - Dirección física
- `is_active` - Estado
- `manager_id` - Responsable/Gerente

---

### 13. Usuarios (Users)

**Controlador**: `Admin/UserController.php`, `AuthController.php`  
**Modelo**: `User.php`  
**Endpoints**: `/api/auth/admin/users`, `/api/auth/*`

#### Funcionalidad
Gestión de usuarios del sistema con autenticación y autorización.

#### 🔥 Funcionalidades Especiales

##### Autenticación con Sanctum
**Controlador**: `AuthController.php`

```php
POST /api/auth/login
POST /api/auth/logout
POST /api/auth/logout-all  // Cierra todas las sesiones
GET /api/auth/me           // Datos del usuario autenticado
POST /api/auth/change-password
```

##### Tokens de Dispositivo (Push Notifications)
**Controlador**: `DeviceTokenController.php`  
**Modelo**: `DeviceToken.php`

Cada usuario puede tener múltiples dispositivos registrados para recibir notificaciones push.

```php
POST /api/device-tokens  // Registrar token de dispositivo
GET /api/device-tokens   // Listar dispositivos del usuario
DELETE /api/device-tokens/{id}  // Eliminar dispositivo
```

Campos:
- `user_id`
- `token` - FCM token
- `device_type` - iOS, Android, Web
- `device_name` - Nombre del dispositivo
- `app_version` - Versión de la app
- `is_active` - Estado
- `last_used_at` - Última vez usado

##### Roles y Permisos
Sistema basado en roles con permisos granulares.

**Modelos**: `Role.php`, `Permission.php`

Un usuario puede tener múltiples roles, cada rol tiene múltiples permisos.

**Controladores**:
- `RolesController.php`
- `PermissionsController.php`

Permisos comunes:
- `manage_products`
- `manage_inventory`
- `manage_sales`
- `manage_purchases`
- `view_reports`
- `manage_users`
- `manage_locations`
- etc.

##### Multi-Empresa y Multi-Ubicación
Un usuario puede:
- Pertenecer a múltiples empresas
- Tener acceso a múltiples ubicaciones
- Tener roles diferentes en cada empresa
- Seleccionar empresa/ubicación de trabajo

Tabla pivote: `company_user`, `location_user`

---

### 14. Trabajadores (Workers)

**Controlador**: `Admin/WorkerController.php`  
**Modelo**: `Admin/Worker.php`  
**Endpoints**: `/api/auth/admin/workers`

#### Funcionalidad
Gestión de empleados (NO usuarios del sistema).

#### Diferencia: Usuario vs Trabajador
- **Usuario**: Tiene acceso al sistema (login, permisos)
- **Trabajador**: Empleado sin acceso al sistema (vendedores, operarios, etc.)

#### Uso de Trabajadores
- Asignación de ventas a vendedores
- Asignación de tareas
- Control de horarios (si aplica)
- Nómina (si aplica)
- Comisiones por ventas

#### Campos Principales
- `company_id`
- `location_id` - Ubicación asignada
- `name`, `last_name`
- `email`, `phone`
- `position` - Puesto/cargo
- `hire_date` - Fecha de contratación
- `is_active` - Estado

---

### 15. Proveedores (Suppliers)

**Controlador**: `SupplierController.php`  
**Modelo**: `Supplier.php`  
**Endpoints**: `/api/suppliers`

#### Funcionalidad
Catálogo de proveedores.

#### 🔥 Funcionalidades Especiales

##### Integración con WhatsApp
Almacena el número de teléfono del proveedor para envío automático de órdenes de compra.

Campo: `phone` - Formato internacional (ej: 52987654321)

##### Historial de Compras
- Total comprado al proveedor
- Número de órdenes
- Última compra
- Productos más comprados

##### Evaluación de Proveedores
- Calidad
- Tiempos de entrega
- Precios competitivos
- Cumplimiento

---

### 16. Clientes (Customers)

**Controlador**: `Admin/CustomerController.php`  
**Modelo**: `Admin/Customer.php`  
**Endpoints**: `/api/auth/admin/customers`

#### Funcionalidad
Catálogo de clientes.

#### 🔥 Funcionalidades Especiales

##### Notas del Cliente
**Controlador**: `CustomerNoteController.php`  
**Modelo**: `CustomerNote.php`

Registro de interacciones, observaciones y seguimiento del cliente.

```php
POST /api/customer-notes
GET /api/customer-notes?customer_id={id}
```

##### Historial de Compras
- Total comprado
- Número de transacciones
- Ticket promedio
- Productos favoritos
- Última compra

##### Cuentas por Cobrar
Si el cliente compra a crédito:
- Saldo pendiente
- Historial de pagos
- Días de crédito
- Límite de crédito

---

### 17. Categorías (Categories)

**Controlador**: `CategoryController.php`  
**Modelo**: `Category.php`  
**Endpoints**: `/api/categories`

#### Funcionalidad
Organización jerárquica de productos.

#### 🔥 Funcionalidades Especiales

##### Categorías Anidadas
Soporta jerarquía multinivel:
- Categoría padre
  - Subcategoría 1
    - Subcategoría 1.1
    - Subcategoría 1.2
  - Subcategoría 2

Campo: `parent_id` - ID de la categoría padre

##### Conteo Automático
Cuenta productos asignados a cada categoría.

---

### 18. Unidades de Medida (Units)

**Controlador**: `UnitControllerV2.php`  
**Modelo**: `Unit.php`, `UnitConversion.php`  
**Endpoints**: `/api/units`

#### Funcionalidad
Catálogo de unidades de medida con conversiones.

#### 🔥 Funcionalidades Especiales

##### Conversiones entre Unidades
**Modelo**: `UnitConversion.php`

Permite convertir automáticamente entre unidades:

Ejemplos:
- 1 kg = 1000 g
- 1 caja = 24 piezas
- 1 litro = 1000 ml

Campos:
- `from_unit_id` - Unidad origen
- `to_unit_id` - Unidad destino
- `factor` - Factor de conversión

##### Uso en Productos
Un producto puede:
- Venderse en una unidad (pza)
- Comprarse en otra unidad (caja)
- El sistema convierte automáticamente

---

## Servicios e Integraciones

### 19. Firebase Service

**Archivo**: `app/Services/FirebaseService.php`  
**Integración**: Firebase Cloud Messaging (FCM)

#### Funcionalidad
Envío de notificaciones push a dispositivos móviles.

#### 🔥 Métodos Principales

```php
// Enviar a un usuario (todos sus dispositivos activos)
FirebaseService::sendToUser($userId, $title, $body, $data)

// Enviar a múltiples tokens
FirebaseService::sendToTokens($tokens, $title, $body, $data)

// Enviar a un token específico
FirebaseService::sendToToken($token, $title, $body, $data)
```

#### Configuración
```php
// config/services.php
'firebase' => [
    'credentials' => env('FIREBASE_CREDENTIALS'),
]
```

El archivo de credenciales es un JSON descargado de Firebase Console.

#### Gestión Automática de Tokens
- Detecta tokens inválidos
- Desactiva automáticamente tokens que fallan
- Actualiza `last_used_at` en tokens exitosos

#### Logging
Registra todos los intentos de envío en logs para auditoría.

---

### 20. WhatsApp Service

**Archivo**: `app/Services/WhatsAppService.php`  
**Integración**: WhatsApp Cloud API (Meta/Facebook)

#### Funcionalidad
Envío de mensajes de WhatsApp a proveedores y clientes.

#### 🔥 Funcionalidades Especiales

##### Envío de Orden de Compra
```php
WhatsAppService::sendPurchaseOrder($phoneNumber, $purchase)
```

**Formato del mensaje**:
```
🛒 Nueva Orden de Compra

📋 Orden #123
🏢 Empresa: Mi Empresa S.A.
📍 Ubicación: Sucursal Centro

📦 Productos:
• Producto A - 10 unidades - $100.00
• Producto B - 5 cajas - $250.00

💰 Total: $350.00

📅 Entrega esperada: 2025-12-15

📞 Contacto: contacto@miempresa.com
```

##### Webhook para Respuestas
**Controlador**: `WhatsAppWebhookController.php`

Recibe notificaciones de:
- Mensajes enviados
- Mensajes entregados
- Mensajes leídos
- Respuestas del proveedor

```php
POST /api/webhooks/whatsapp  // Recibir eventos
GET /api/webhooks/whatsapp   // Verificación del webhook
```

**Token de verificación**: `plastigest_webhook_token_2024`

##### Configuración

```php
// config/services.php
'whatsapp' => [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
]
```

##### Testing con ngrok
Para desarrollo local:

```bash
ngrok http 80
```

Configurar URL en Meta Developer Console:
```
https://abc123.ngrok.io/api/webhooks/whatsapp
```

---

### 21. Notification Service

**Archivo**: `app/Services/NotificationService.php`  
**Modelo**: `Notification.php`

#### Funcionalidad
Gestión centralizada de notificaciones del sistema.

#### 🔥 Métodos Principales

##### Crear Notificación
```php
NotificationService::create($userId, $title, $message, $type, $data)
```

Tipos de notificaciones:
- `low_stock` - Stock bajo
- `inventory_count_complete` - Conteo completado
- `purchase_received` - Compra recibida
- `sale_created` - Venta realizada
- `transfer_received` - Transferencia recibida
- `task_assigned` - Tarea asignada
- `task_due_soon` - Tarea próxima a vencer
- `task_overdue` - Tarea vencida

##### Enviar Notificación Push
```php
NotificationService::sendPushNotification($userId, $title, $message, $data)
```

1. Crea registro en base de datos
2. Envía push notification vía Firebase
3. Retorna resultado del envío

##### Notificación de Stock Bajo
```php
NotificationService::createLowStockNotification($userId, $products, $locationId)
```

**Cuándo se usa**:
- Después de completar un conteo de inventario
- Al realizar una venta que deja stock bajo el mínimo
- Verificación programada diaria

**Contenido**:
```json
{
  "title": "⚠️ Alerta de Stock Bajo",
  "body": "5 productos están por debajo del stock mínimo",
  "data": {
    "type": "low_stock",
    "location_id": 1,
    "location_name": "Sucursal Centro",
    "products_count": 5,
    "products": [
      {
        "product_id": 10,
        "product_name": "Producto A",
        "current_stock": 5,
        "minimum_stock": 10
      }
    ]
  }
}
```

##### Formateo de Datos
Convierte datos técnicos en información legible:
- IDs → Nombres
- Timestamps → Fechas formateadas
- Estados → Labels descriptivos

---

### 22. Task Service

**Archivo**: `app/Services/TaskService.php`  
**Modelo**: `Task.php`, `TaskComment.php`  
**Controlador**: `TaskController.php`

#### Funcionalidad
Gestión y automatización de tareas.

#### 🔥 Creación Automática de Tareas

##### Desde Compra
```php
TaskService::createFromPurchase($purchase)
```

Crea tarea de tipo `receive_purchase`:
- Título: "Recibir compra #{id}"
- Descripción: Lista de productos esperados
- Prioridad: Alta
- Vencimiento: Fecha estimada de entrega + 1 día

##### Desde Conteo de Inventario
```php
TaskService::createFromInventoryCount($count)
```

Si hay discrepancias, crea tarea de tipo `stock_check`:
- Título: "Revisar diferencias en conteo #{id}"
- Descripción: Número de productos con diferencias
- Prioridad: Urgente (si >10 productos)
- Vencimiento: 1 día

##### Desde Ajuste
```php
TaskService::createFromAdjustment($adjustment)
```

Si el ajuste es significativo (>10 unidades), crea tarea de tipo `adjustment_review`:
- Título: "Revisar ajuste #{id}"
- Descripción: Motivo del ajuste
- Prioridad: Alta
- Requiere aprobación de supervisor

##### Desde Transferencia
```php
TaskService::createFromTransfer($transfer)
```

Crea diferentes tareas según el estado:
- `approve_transfer` - Aprobar solicitud
- `send_transfer` - Enviar productos
- `receive_transfer` - Recibir productos

#### Asignación Automática
```php
TaskService::autoAssignTask($task)
```

Asigna tareas automáticamente basado en:
- Rol del usuario
- Ubicación
- Disponibilidad
- Carga de trabajo actual

#### Notificaciones de Tareas
Al crear/asignar una tarea:
1. Crea notificación en base de datos
2. Envía push notification
3. Envía email (opcional)

#### Tareas Recurrentes
Soporte para tareas que se repiten:
- Conteos semanales
- Reportes mensuales
- Revisiones periódicas

Campos:
- `is_recurring` - Booleano
- `recurrence_pattern` (JSON):
  - `frequency`: daily, weekly, monthly
  - `interval`: Cada cuántos días/semanas/meses
  - `end_date`: Fecha de finalización

---

### 23. Transfer Service

**Archivo**: `app/Services/TransferService.php`

#### Funcionalidad
Lógica de negocio para transferencias entre ubicaciones.

#### 🔥 Métodos Principales

##### Crear Requisición
```php
TransferService::createRequisition($fromLocationId, $toLocationId, $products, $userId)
```

Ubicación destino solicita productos a ubicación origen.

##### Aprobar Requisición
```php
TransferService::approveRequisition($transferId, $userId)
```

Ubicación origen aprueba la solicitud.

##### Rechazar Requisición
```php
TransferService::rejectRequisition($transferId, $reason, $userId)
```

Ubicación origen rechaza con motivo.

##### Convertir a Envío
```php
TransferService::convertToShipment($transferId, $userId)
```

Crea registro de envío y decrementa stock en origen.

##### Confirmar Recepción
```php
TransferService::confirmReceipt($transferId, $receivedProducts, $userId)
```

Incrementa stock en destino, permite reportar diferencias.

---

### 24. Movement Service

**Archivo**: `app/Services/MovementService.php`

#### Funcionalidad
Servicio centralizado para todos los movimientos de inventario.

#### 🔥 Métodos Críticos

##### Validar Stock
```php
MovementService::validateStock($locationId, $productId, $quantity)
```

Verifica que hay suficiente stock antes de movimiento de salida.

Lanza excepción si:
- No hay suficiente stock
- Producto no existe en ubicación
- Producto inactivo

##### Incrementar Stock
```php
MovementService::incrementStock($locationId, $productId, $quantity)
```

Usado en:
- Compras recibidas
- Transferencias recibidas
- Producción completada
- Ajustes positivos

Actualiza:
- `product_location.current_stock` (+cantidad)
- Registra en kardex

##### Decrementar Stock
```php
MovementService::decrementStock($locationId, $productId, $quantity)
```

Usado en:
- Ventas
- Transferencias enviadas
- Producción (materias primas)
- Ajustes negativos

Actualiza:
- `product_location.current_stock` (-cantidad)
- Registra en kardex
- Valida stock disponible primero

##### Registrar en Kardex
```php
MovementService::recordKardex($productId, $locationId, $movementData)
```

Crea registro detallado en `product_kardex`:
- Fecha y hora
- Tipo de movimiento
- Cantidad anterior
- Cantidad movida
- Cantidad nueva
- Costo unitario
- Usuario responsable
- Referencia (ID del movimiento)

---

### 25. Inventory Service

**Archivo**: `app/Services/InventoryService.php`

#### Funcionalidad
Consultas y análisis de inventario.

#### 🔥 Métodos Principales

##### Valuación de Inventario
```php
InventoryService::getInventoryValuation($locationId)
```

Calcula:
- Valor total del inventario (costo)
- Valor al precio de venta
- Margen potencial

##### Productos con Stock Bajo
```php
InventoryService::getLowStockProducts($locationId)
```

Retorna productos donde `current_stock < minimum_stock`.

##### Rotación de Inventario
```php
InventoryService::getInventoryTurnover($locationId, $startDate, $endDate)
```

Calcula rotación por producto:
- Unidades vendidas
- Stock promedio
- Índice de rotación
- Días de inventario

##### Productos sin Movimiento
```php
InventoryService::getDeadStock($locationId, $days = 90)
```

Productos sin ventas ni movimientos en X días.

---

## Sistema de Tareas y Notificaciones

### Arquitectura

```
Evento del Sistema (compra, venta, etc.)
    ↓
Servicio correspondiente detecta evento
    ↓
TaskService crea tarea automática
    ↓
NotificationService crea notificación
    ↓
Base de datos: registro en tabla notifications
    ↓
FirebaseService envía push notification
    ↓
Usuario recibe notificación en dispositivo móvil
    ↓
Usuario hace clic en notificación
    ↓
App navega a la pantalla relevante
```

### Tipos de Tareas

**Enum**: `TaskType`

1. **inventory_count** - Conteo de inventario
2. **receive_purchase** - Recibir compra
3. **approve_transfer** - Aprobar transferencia
4. **send_transfer** - Enviar transferencia
5. **receive_transfer** - Recibir transferencia
6. **sales_report** - Reporte de ventas
7. **stock_check** - Revisión de stock
8. **adjustment_review** - Revisar ajuste
9. **custom** - Personalizada

### Prioridades de Tareas

**Enum**: `TaskPriority`

- **urgent** - Urgente (requiere atención inmediata)
- **high** - Alta
- **medium** - Media
- **low** - Baja

### Estados de Tareas

**Enum**: `TaskStatus`

- **pending** - Pendiente
- **in_progress** - En proceso
- **completed** - Completada
- **cancelled** - Cancelada
- **overdue** - Vencida (automático si pasa due_date)

### Comentarios en Tareas

**Modelo**: `TaskComment.php`

Los usuarios pueden:
- Agregar comentarios a tareas
- Adjuntar archivos
- Mencionar a otros usuarios
- Seguimiento de conversación

### Tipos de Notificaciones

**Enum**: `NotificationType`

1. **low_stock** - Alerta de stock bajo
2. **inventory_count_complete** - Conteo completado
3. **inventory_discrepancy** - Discrepancias en conteo
4. **purchase_ordered** - Compra ordenada
5. **purchase_received** - Compra recibida
6. **sale_created** - Venta realizada
7. **transfer_requested** - Transferencia solicitada
8. **transfer_approved** - Transferencia aprobada
9. **transfer_shipped** - Transferencia enviada
10. **transfer_received** - Transferencia recibida
11. **task_assigned** - Tarea asignada
12. **task_due_soon** - Tarea próxima a vencer (24hrs)
13. **task_overdue** - Tarea vencida
14. **adjustment_created** - Ajuste creado
15. **production_completed** - Producción completada

---

## Modelos y Base de Datos

### Modelos Principales

#### Operaciones
- `Purchase` - Compras
- `PurchaseDetail` - Detalle de compras
- `Sale` - Ventas
- `SaleDetail` - Detalle de ventas
- `Production` - Producción
- `SalesReport` - Reportes de ventas

#### Inventario
- `Product` - Productos
- `ProductImage` - Imágenes de productos
- `ProductIngredient` - Ingredientes/fórmulas
- `ProductPackage` - Paquetes
- `ProductKardex` - Historial de movimientos
- `InventoryCount` - Conteos de inventario
- `InventoryCountDetail` - Detalle de conteos
- `Adjustment` - Ajustes
- `AdjustmentDetail` - Detalle de ajustes
- `Movement` - Movimientos de inventario
- `MovementDetail` - Detalle de movimientos

#### Transferencias
- `InventoryTransfer` - Transferencias
- `InventoryTransferDetail` - Detalle de transferencias
- `InventoryTransferShipment` - Envíos
- `Transfer` - Requisiciones

#### Administración
- `Company` - Empresas
- `Location` - Ubicaciones
- `User` - Usuarios
- `Worker` - Trabajadores
- `Role` - Roles
- `Permission` - Permisos
- `Supplier` - Proveedores
- `Customer` - Clientes
- `Category` - Categorías
- `Unit` - Unidades
- `UnitConversion` - Conversiones

#### Sistema
- `Notification` - Notificaciones
- `Task` - Tareas
- `TaskComment` - Comentarios de tareas
- `DeviceToken` - Tokens de dispositivos
- `CustomerNote` - Notas de clientes

### Tablas Pivote

- `company_user` - Relación usuarios-empresas
- `location_user` - Relación usuarios-ubicaciones
- `product_location` - Stock por producto y ubicación
- `role_permission` - Relación roles-permisos
- `user_role` - Relación usuarios-roles

---

## Funcionalidades Especiales - Resumen

### 🔔 Notificaciones Push (Firebase)

**Cuándo se envían**:
1. **Stock bajo** - Después de conteo de inventario o venta
2. **Tarea asignada** - Al crear/asignar tarea
3. **Tarea próxima a vencer** - 24 horas antes
4. **Tarea vencida** - Al pasar la fecha límite
5. **Transferencia recibida** - Al llegar productos
6. **Compra ordenada** - Al confirmar orden
7. **Conteo completado** - Al finalizar conteo con discrepancias

**Servicio**: `FirebaseService`  
**Modelo**: `DeviceToken`

### 📱 Mensajes de WhatsApp

**Cuándo se envían**:
1. **Orden de compra al proveedor** - Al cambiar compra de `draft` a `ordered`

**Servicio**: `WhatsAppService`  
**Webhook**: `WhatsAppWebhookController`

### ✅ Creación Automática de Tareas

**Cuándo se crean**:
1. **Compra en tránsito** - Tarea de recepción
2. **Conteo con discrepancias** - Tarea de revisión
3. **Ajuste significativo** - Tarea de aprobación
4. **Transferencia solicitada** - Tarea de aprobación
5. **Transferencia enviada** - Tarea de recepción

**Servicio**: `TaskService`

### 📊 Actualización Automática de Inventario

**Eventos que actualizan stock**:
1. Compra recibida → Incrementa stock
2. Venta completada → Decrementa stock
3. Producción completada → Decrementa ingredientes, incrementa productos
4. Transferencia enviada → Decrementa origen
5. Transferencia recibida → Incrementa destino
6. Ajuste aprobado → Incrementa o decrementa

**Servicio**: `MovementService`

### 📝 Registro Automático de Kardex

**Todos los movimientos** se registran automáticamente en:
- `movements` - Encabezado
- `movements_details` - Detalle por producto
- `product_kardex` - Historial detallado

**Servicio**: `MovementService::recordKardex()`

### 🔒 Transacciones Atómicas

Todos los procesos críticos usan transacciones de base de datos:
- Compras
- Ventas
- Transferencias
- Ajustes
- Producción

Garantiza integridad: si algo falla, todo se revierte.

---

## Endpoints de API - Resumen

### Autenticación
```
POST /api/auth/login
POST /api/auth/logout
GET /api/auth/me
POST /api/auth/change-password
```

### Compras
```
GET /api/purchases
POST /api/purchases
GET /api/purchases/{id}
PUT /api/purchases/{id}
POST /api/purchases/{id}/transition-to
GET /api/purchases/stats
```

### Ventas
```
GET /api/sales
POST /api/sales
GET /api/sales/{id}
POST /api/sales/{id}/complete
GET /api/sales/stats
```

### Productos
```
GET /api/products
POST /api/products
GET /api/products/{id}
PUT /api/products/{id}
GET /api/products/{id}/kardex
GET /api/products/{id}/labels/pdf
```

### Inventario
```
GET /api/inventory/current
GET /api/inventory/low-stock
GET /api/inventory/valuation
```

### Conteos
```
GET /api/inventory-counts
POST /api/inventory-counts
POST /api/inventory-counts/{id}/complete
GET /api/inventory-counts/{id}/pdf
```

### Transferencias
```
GET /api/transfers
POST /api/transfers
POST /api/transfers/{id}/approve
POST /api/transfers/{id}/ship
POST /api/transfers/{id}/receive
```

### Notificaciones
```
GET /api/notifications
POST /api/notifications/{id}/mark-as-read
POST /api/notifications/mark-all-read
```

### Tareas
```
GET /api/tasks
POST /api/tasks
GET /api/tasks/{id}
POST /api/tasks/{id}/complete
POST /api/tasks/{id}/comments
```

### Administración
```
GET /api/auth/admin/companies
GET /api/auth/admin/locations
GET /api/auth/admin/users
GET /api/auth/admin/workers
GET /api/suppliers
GET /api/customers
GET /api/categories
GET /api/units
```

---

## Configuración Requerida

### Variables de Entorno

```env
# Base de datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=plastigest
DB_USERNAME=root
DB_PASSWORD=

# Firebase
FIREBASE_CREDENTIALS=/path/to/firebase-credentials.json

# WhatsApp (Meta Cloud API)
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_ACCESS_TOKEN=your_access_token

# App
APP_URL=https://api.plastigest.com
```

### Archivos de Configuración

- `config/services.php` - Firebase y WhatsApp
- `config/sanctum.php` - Autenticación API
- `config/cors.php` - CORS para frontend
- `config/database.php` - Conexión a BD

---

## Comandos Artisan Personalizados

```bash
# Limpiar tokens de dispositivos inactivos
php artisan tokens:clean

# Verificar tareas vencidas y notificar
php artisan tasks:check-overdue

# Verificar stock bajo diario
php artisan inventory:check-low-stock

# Generar reportes automáticos
php artisan reports:generate-daily
```

---

## Documentación Adicional

Para más detalles, consultar:

- [TASKS_AND_NOTIFICATIONS_SYSTEM.md](../TASKS_AND_NOTIFICATIONS_SYSTEM.md)
- [PUSH_NOTIFICATIONS_LOW_STOCK.md](../PUSH_NOTIFICATIONS_LOW_STOCK.md)
- [WHATSAPP_WEBHOOK_SETUP.md](../WHATSAPP_WEBHOOK_SETUP.md)
- [INVENTORY_SYSTEM_DOCUMENTATION.md](../INVENTORY_SYSTEM_DOCUMENTATION.md)
- [INVENTORY_TRANSFERS_API.md](../INVENTORY_TRANSFERS_API.md)
- [TRANSFER_REQUISITION_FLOW.md](../TRANSFER_REQUISITION_FLOW.md)

---

*Última actualización: Diciembre 12, 2025*
