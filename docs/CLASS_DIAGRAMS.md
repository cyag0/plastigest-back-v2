# Diagramas de Clases - Sistema PlastiGest

## 📊 Análisis del Backend Actual

### Estructura Principal Identificada:

1. **Sistema de Autenticación y Roles**
2. **Gestión de Compañías y Sucursales**
3. **Gestión de Inventarios**
4. **Sistema de Órdenes**
5. **Movimientos de Inventario**

---

## 🏗️ Diagrama de Clases Principal

```mermaid
classDiagram
    %% === SISTEMA DE AUTENTICACIÓN Y ROLES ===
    class User {
        +id: int
        +name: string
        +email: string
        +password: string
        +email_verified_at: datetime
        +created_at: datetime
        +updated_at: datetime
        --
        +roles(): BelongsToMany~Role~
        +purchaseOrders(): HasMany~PurchaseOrder~
        +salesOrders(): HasMany~SalesOrder~
        +movements(): HasMany~Movement~
    }

    class Role {
        +id: int
        +name: string
        +description: string
        +is_system: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +permissions(): BelongsToMany~Permission~
        +users(): BelongsToMany~User~
    }

    class Permission {
        +id: int
        +name: string
        +description: string
        +resource: string
        +created_at: datetime
        +updated_at: datetime
        --
        +roles(): BelongsToMany~Role~
    }

    %% === GESTIÓN DE COMPAÑÍAS ===
    class Company {
        +id: int
        +name: string
        +business_name: string
        +rfc: string
        +address: string
        +phone: string
        +email: string
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +locations(): HasMany~Location~
        +products(): HasMany~Product~
        +customers(): HasMany~Customer~
        +suppliers(): HasMany~Supplier~
        +purchaseOrders(): HasMany~PurchaseOrder~
        +salesOrders(): HasMany~SalesOrder~
        +movements(): HasMany~Movement~
        +workers(): HasMany~Worker~
    }

    class Location {
        +id: int
        +company_id: int
        +name: string
        +description: string
        +address: string
        +phone: string
        +email: string
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +products(): BelongsToMany~Product~
        +movementsAsOrigin(): HasMany~Movement~
        +movementsAsDestination(): HasMany~Movement~
    }

    %% === GESTIÓN DE PRODUCTOS ===
    class Product {
        +id: int
        +company_id: int
        +category_id: int
        +unit_id: int
        +code: string
        +name: string
        +description: text
        +purchase_price: decimal
        +sale_price: decimal
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +category(): BelongsTo~Category~
        +unit(): BelongsTo~Unit~
        +locations(): BelongsToMany~Location~
        +suppliers(): BelongsToMany~Supplier~
        +categories(): BelongsToMany~Category~
        +purchaseOrderDetails(): HasMany~PurchaseOrderDetail~
        +salesOrderDetails(): HasMany~SalesOrderDetail~
        +movementDetails(): HasMany~MovementDetail~
    }

    class Category {
        +id: int
        +name: string
        +description: string
        +created_at: datetime
        +updated_at: datetime
        --
        +products(): HasMany~Product~
    }

    class Unit {
        +id: int
        +name: string
        +abbreviation: string
        +created_at: datetime
        +updated_at: datetime
        --
        +products(): HasMany~Product~
    }

    %% === GESTIÓN DE CLIENTES Y PROVEEDORES ===
    class Customer {
        +id: int
        +company_id: int
        +name: string
        +business_name: string
        +social_reason: string
        +rfc: string
        +address: string
        +phone: string
        +email: string
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +salesOrders(): HasMany~SalesOrder~
        +movements(): HasMany~Movement~
    }

    class Supplier {
        +id: int
        +company_id: int
        +name: string
        +business_name: string
        +social_reason: string
        +rfc: string
        +address: string
        +phone: string
        +email: string
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +products(): BelongsToMany~Product~
        +purchaseOrders(): HasMany~PurchaseOrder~
        +movements(): HasMany~Movement~
    }

    %% === SISTEMA DE ÓRDENES ===
    class PurchaseOrder {
        +id: int
        +company_id: int
        +supplier_id: int
        +user_id: int
        +order_number: string
        +order_date: date
        +expected_date: date
        +status: enum
        +subtotal: decimal
        +comments: text
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +supplier(): BelongsTo~Supplier~
        +user(): BelongsTo~User~
        +details(): HasMany~PurchaseOrderDetail~
    }

    class PurchaseOrderDetail {
        +id: int
        +purchase_order_id: int
        +product_id: int
        +quantity: decimal
        +unit_price: decimal
        +total_price: decimal
        +created_at: datetime
        +updated_at: datetime
        --
        +purchaseOrder(): BelongsTo~PurchaseOrder~
        +product(): BelongsTo~Product~
    }

    class SalesOrder {
        +id: int
        +company_id: int
        +customer_id: int
        +user_id: int
        +order_number: string
        +order_date: date
        +expected_date: date
        +status: enum
        +subtotal: decimal
        +comments: text
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +customer(): BelongsTo~Customer~
        +user(): BelongsTo~User~
        +details(): HasMany~SalesOrderDetail~
    }

    class SalesOrderDetail {
        +id: int
        +sales_order_id: int
        +product_id: int
        +quantity: decimal
        +unit_price: decimal
        +total_price: decimal
        +created_at: datetime
        +updated_at: datetime
        --
        +salesOrder(): BelongsTo~SalesOrder~
        +product(): BelongsTo~Product~
    }

    %% === MOVIMIENTOS DE INVENTARIO ===
    class Movement {
        +id: int
        +company_id: int
        +movement_type: enum
        +warehouse_origin_id: int
        +warehouse_destination_id: int
        +supplier_id: int
        +customer_id: int
        +user_id: int
        +date: date
        +total_cost: decimal
        +status: enum
        +comments: text
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +warehouseOrigin(): BelongsTo~Location~
        +warehouseDestination(): BelongsTo~Location~
        +supplier(): BelongsTo~Supplier~
        +customer(): BelongsTo~Customer~
        +user(): BelongsTo~User~
        +details(): HasMany~MovementDetail~
    }

    class MovementDetail {
        +id: int
        +movement_id: int
        +product_id: int
        +quantity: decimal
        +unit_cost: decimal
        +total_cost: decimal
        +created_at: datetime
        +updated_at: datetime
        --
        +movement(): BelongsTo~Movement~
        +product(): BelongsTo~Product~
    }

    %% === CONTEOS DE INVENTARIO ===
    class InventoryCount {
        +id: int
        +company_id: int
        +location_id: int
        +user_id: int
        +count_date: date
        +status: enum
        +comments: text
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +location(): BelongsTo~Location~
        +user(): BelongsTo~User~
        +details(): HasMany~InventoryCountDetail~
    }

    class InventoryCountDetail {
        +id: int
        +inventory_count_id: int
        +product_id: int
        +system_quantity: decimal
        +physical_quantity: decimal
        +difference: decimal
        +unit_cost: decimal
        +total_difference_cost: decimal
        +created_at: datetime
        +updated_at: datetime
        --
        +inventoryCount(): BelongsTo~InventoryCount~
        +product(): BelongsTo~Product~
    }

    %% === WORKERS (FALTANTE - PROPUESTA) ===
    class Worker {
        +id: int
        +company_id: int
        +user_id: int
        +employee_number: string
        +position: string
        +hire_date: date
        +salary: decimal
        +is_active: boolean
        +created_at: datetime
        +updated_at: datetime
        --
        +company(): BelongsTo~Company~
        +user(): BelongsTo~User~
        +movements(): HasMany~Movement~
        +purchaseOrders(): HasMany~PurchaseOrder~
        +salesOrders(): HasMany~SalesOrder~
    }

    %% === RELACIONES ===
    User ||--o{ Role : users_roles
    Role ||--o{ Permission : rol_permission
    Company ||--o{ Location : company_id
    Company ||--o{ Product : company_id
    Company ||--o{ Customer : company_id
    Company ||--o{ Supplier : company_id
    Company ||--o{ PurchaseOrder : company_id
    Company ||--o{ SalesOrder : company_id
    Company ||--o{ Movement : company_id
    Company ||--o{ Worker : company_id
    Location ||--o{ Movement : warehouse_origin_id
    Location ||--o{ Movement : warehouse_destination_id
    Product }|--|| Category : category_id
    Product }|--|| Unit : unit_id
    Product }|--|| Company : company_id
    Product ||--o{ Location : product_location
    Product ||--o{ Supplier : product_supplier
    Supplier ||--o{ PurchaseOrder : supplier_id
    Customer ||--o{ SalesOrder : customer_id
    User ||--o{ PurchaseOrder : user_id
    User ||--o{ SalesOrder : user_id
    User ||--o{ Movement : user_id
    User ||--|| Worker : user_id
    PurchaseOrder ||--o{ PurchaseOrderDetail : purchase_order_id
    SalesOrder ||--o{ SalesOrderDetail : sales_order_id
    Movement ||--o{ MovementDetail : movement_id
    InventoryCount ||--o{ InventoryCountDetail : inventory_count_id
```

---

## ⚠️ Elementos Faltantes Identificados

### 1. **Workers/Empleados por Compañía**

**Problema detectado**: Actualmente NO existe una tabla `workers` ni modelo `Worker` funcional. El sistema tiene usuarios pero no hay relación directa con compañías.

**Propuesta de implementación**:

```sql
CREATE TABLE workers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    employee_number VARCHAR(50) UNIQUE,
    position VARCHAR(100),
    hire_date DATE,
    salary DECIMAL(10,2),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company (user_id, company_id)
);
```

### 2. **Relación User-Company Directa**

**Problema**: Los usuarios no tienen `company_id` directo, lo que podría complicar las consultas.

**Opciones**:

-   **A**: Agregar `company_id` a la tabla `users`
-   **B**: Usar la tabla `workers` como pivot entre users y companies (Recomendado)

### 3. **Sistema de Permisos por Compañía**

**Mejora sugerida**: Los permisos podrían ser específicos por compañía.

```sql
ALTER TABLE users_roles ADD COLUMN company_id BIGINT UNSIGNED;
ALTER TABLE users_roles ADD FOREIGN KEY (company_id) REFERENCES companies(id);
```

---

## 🛠️ Implementaciones Recomendadas

### 1. Crear Worker Model y Migration

```php
// Migration
Schema::create('workers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('employee_number', 50)->unique();
    $table->string('position', 100)->nullable();
    $table->date('hire_date')->nullable();
    $table->decimal('salary', 10, 2)->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['user_id', 'company_id']);
});

// Model
class Worker extends Model {
    protected $fillable = [
        'company_id', 'user_id', 'employee_number',
        'position', 'hire_date', 'salary', 'is_active'
    ];

    public function company() {
        return $this->belongsTo(Company::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
```

### 2. Actualizar User Model

```php
class User extends Authenticatable {
    // ... existing code

    public function workers() {
        return $this->hasMany(Worker::class);
    }

    public function companies() {
        return $this->belongsToMany(Company::class, 'workers')
                    ->withPivot(['employee_number', 'position', 'hire_date', 'salary', 'is_active'])
                    ->withTimestamps();
    }

    public function currentCompany() {
        // Logic to get current active company for user
        return $this->companies()->wherePivot('is_active', true)->first();
    }
}
```

### 3. Actualizar Company Model

```php
class Company extends Model {
    // ... existing code

    public function workers() {
        return $this->hasMany(Worker::class);
    }

    public function users() {
        return $this->belongsToMany(User::class, 'workers')
                    ->withPivot(['employee_number', 'position', 'hire_date', 'salary', 'is_active'])
                    ->withTimestamps();
    }
}
```

---

## 📋 Siguientes Pasos

1. **Crear migración para tabla `workers`**
2. **Implementar modelo `Worker` con relaciones**
3. **Actualizar modelos `User` y `Company`**
4. **Crear WorkerController y WorkerResource**
5. **Implementar frontend para gestión de workers**
6. **Actualizar sistema de autenticación para manejar company context**

---

## 🔍 Notas Técnicas

-   **Patrón Usado**: Cada entidad principal (productos, clientes, proveedores, órdenes) está relacionada con `company_id`
-   **Arquitectura**: Multi-tenant por compañía
-   **Autenticación**: Laravel Sanctum con sistema de roles y permisos
-   **Frontend**: React Native con Expo Router
-   **Base de Datos**: MySQL con foreign keys y constraints apropiados

Este diagrama refleja el estado ACTUAL del backend y identifica las áreas que necesitan desarrollo adicional.
