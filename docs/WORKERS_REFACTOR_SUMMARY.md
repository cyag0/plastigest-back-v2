# Reestructuración del Sistema de Usuarios, Workers, Empresas y Roles

## Cambios Realizados

### Nuevo Modelo de Relaciones

**Antes:**
- Un usuario tenía un solo worker
- Workers tenían múltiples roles (many-to-many)
- Workers tenían múltiples empresas (many-to-many)
- Workers tenían múltiples ubicaciones (many-to-many)

**Ahora:**
- Un **Usuario** tiene varios **Workers** (uno por cada empresa donde trabaja)
- Un **Worker** pertenece a un **Usuario** y a una **Empresa** (relación única)
- Un **Worker** tiene un **Rol** único (belongsTo)
- Un **Usuario** tiene acceso a varias **Empresas** a través de sus Workers

### Diagrama de Relaciones

```
User (1) ──────< Workers (N)
                    │
                    ├──> Company (1)
                    └──> Role (1)

Company (1) ────< Workers (N)
```

### Cambios en la Base de Datos

#### 1. Migración `2024_12_01_000002_refactor_workers_single_role.php`

**Acciones:**
- ✅ Elimina la tabla `worker_roles` (relación many-to-many)
- ✅ Agrega columna `role_id` directamente en la tabla `workers`
- ✅ Relación `role_id` es nullable y con foreign key a `roles`

**Estructura final de `workers`:**
```sql
- id
- company_id (FK a companies)
- user_id (FK a users)
- role_id (FK a roles) -- NUEVO
- position
- department
- hire_date
- salary
- is_active
- created_at
- updated_at
```

### Cambios en los Modelos

#### 2. Model `Worker.php`

**Cambios:**
- ✅ Eliminadas relaciones many-to-many: `roles()`, `companies()`, `locations()`
- ✅ Agregada relación `role()` usando `belongsTo`
- ✅ Agregados scopes útiles:
  - `active()` - Filtra workers activos
  - `forCompany($companyId)` - Filtra por empresa
  - `forUser($userId)` - Filtra por usuario

**Relaciones finales:**
```php
- company() -> BelongsTo
- user() -> BelongsTo
- role() -> BelongsTo
```

#### 3. Model `User.php`

**Cambios:**
- ✅ Cambiado `worker()` (hasOne) a `workers()` (hasMany)
- ✅ Agregado método `getWorkerForCompany($companyId)` para obtener el worker activo de una empresa específica

**Relaciones finales:**
```php
- workers() -> HasMany
- companies() -> HasManyThrough (a través de workers)
```

**Métodos útiles:**
```php
$user->workers; // Todos los workers del usuario
$user->companies; // Todas las empresas donde trabaja
$user->getWorkerForCompany($companyId); // Worker específico de una empresa
```

#### 4. Model `Company.php`

**Cambios:**
- ✅ Agregada relación `workers()` usando `hasMany`
- ✅ Agregada relación `users()` usando `hasManyThrough`

**Relaciones finales:**
```php
- workers() -> HasMany
- units() -> HasMany
- users() -> HasManyThrough (a través de workers)
```

### Nuevos Helpers

#### 5. Helper `CurrentWorker.php`

Helper para obtener el worker activo del usuario actual en la empresa actual.

**Métodos disponibles:**
```php
// Obtener el worker actual
CurrentWorker::get(); // Worker|null

// Obtener worker para empresa específica
CurrentWorker::getForCompany($companyId); // Worker|null

// Obtener ID del worker actual
CurrentWorker::id(); // int|null

// Obtener rol del worker actual
CurrentWorker::role(); // Role|null

// Verificar roles
CurrentWorker::hasRole('admin'); // bool
CurrentWorker::hasAnyRole(['admin', 'manager']); // bool

// Verificar permisos
CurrentWorker::hasPermission('products.create'); // bool
CurrentWorker::hasAllPermissions(['products.create', 'products.edit']); // bool
CurrentWorker::hasAnyPermission(['products.create', 'products.edit']); // bool
```

**Uso típico:**
```php
use App\Support\CurrentWorker;
use App\Support\CurrentCompany;

// En un controlador
$worker = CurrentWorker::get();
$role = $worker->role;
$company = $worker->company;

// Verificar permisos
if (CurrentWorker::hasPermission('products.create')) {
    // Crear producto...
}

// Obtener datos del worker
$workerId = CurrentWorker::id();
$workerRole = CurrentWorker::role();
```

### Cambios en Controladores

#### 6. WorkerController

**Validación actualizada:**
```php
// Store
'company_id' => 'required|exists:companies,id',
'user_id' => 'required|exists:users,id',
'role_id' => 'nullable|exists:roles,id', // NUEVO

// Update
'company_id' => 'sometimes|exists:companies,id',
'user_id' => 'sometimes|exists:users,id',
'role_id' => 'nullable|exists:roles,id', // NUEVO
```

**Relaciones cargadas:**
```php
- company
- user
- role (antes: roles, companies, locations)
```

**Eliminados:**
- ❌ Sincronización de `role_ids` (many-to-many)
- ❌ Sincronización de `company_ids` (many-to-many)
- ❌ Sincronización de `location_ids` (many-to-many)

#### 7. WorkerResource

**Cambios en el formato de respuesta:**

**Listado (editing=false):**
```json
{
  "id": 1,
  "company_id": 1,
  "user_id": 1,
  "role_id": 2,
  "company_name": "Empresa XYZ",
  "user_name": "Juan Pérez",
  "user_email": "juan@example.com",
  "role_name": "Gerente",
  "position": "Gerente de Ventas",
  "department": "Ventas",
  "hire_date": "2024-01-01",
  "salary": 15000.00,
  "is_active": true
}
```

**Detalle/Edición (editing=true):**
```json
{
  "id": 1,
  "company_id": 1,
  "user_id": 1,
  "role_id": 2,
  "company": {
    "id": 1,
    "name": "Empresa XYZ",
    "business_name": "Empresa XYZ SA de CV"
  },
  "user": {
    "id": 1,
    "name": "Juan Pérez",
    "email": "juan@example.com"
  },
  "role": {
    "id": 2,
    "name": "manager",
    "display_name": "Gerente"
  },
  "position": "Gerente de Ventas",
  "department": "Ventas",
  "hire_date": "2024-01-01",
  "salary": 15000.00,
  "is_active": true,
  "created_at": "2024-01-01T12:00:00.000Z",
  "updated_at": "2024-01-01T12:00:00.000Z"
}
```

## Casos de Uso

### 1. Crear un worker para un usuario en una empresa

```php
$worker = Worker::create([
    'user_id' => 1,
    'company_id' => 2,
    'role_id' => 3,
    'position' => 'Vendedor',
    'department' => 'Ventas',
    'hire_date' => now(),
    'salary' => 12000,
    'is_active' => true,
]);
```

### 2. Obtener todas las empresas donde trabaja un usuario

```php
$user = User::find(1);
$companies = $user->companies; // A través de workers
```

### 3. Obtener todos los workers de una empresa

```php
$company = Company::find(1);
$workers = $company->workers;
```

### 4. Obtener el worker activo del usuario actual

```php
use App\Support\CurrentWorker;
use App\Support\CurrentCompany;

$worker = CurrentWorker::get();
$role = $worker->role;
$permissions = $role->permissions;
```

### 5. Cambiar el rol de un worker

```php
$worker = Worker::find(1);
$worker->update(['role_id' => 5]);
```

### 6. Verificar permisos del worker actual

```php
if (CurrentWorker::hasPermission('products.create')) {
    // Crear producto
}

if (CurrentWorker::hasRole('admin')) {
    // Acciones de admin
}
```

## Ventajas del Nuevo Sistema

1. **Claridad en las relaciones**: Un worker = un usuario en una empresa con un rol
2. **Escalabilidad**: Un usuario puede trabajar en múltiples empresas con diferentes roles
3. **Simplicidad**: Eliminadas relaciones many-to-many innecesarias
4. **Seguridad**: Fácil verificación de permisos con `CurrentWorker::hasPermission()`
5. **Mantenibilidad**: Código más limpio y fácil de entender
6. **Flexibilidad**: Cada worker puede tener diferente rol en cada empresa

## Migración de Datos

Si necesitas migrar datos existentes:

```php
// Ejemplo: Asignar el primer rol de cada worker como role_id
Worker::with('roles')->get()->each(function ($worker) {
    $firstRole = $worker->roles->first();
    if ($firstRole) {
        $worker->update(['role_id' => $firstRole->id]);
    }
});
```

## Próximos Pasos Recomendados

1. ✅ Actualizar frontend para enviar `role_id` en lugar de `role_ids`
2. ✅ Actualizar formularios de workers para selector único de rol
3. ⏳ Revisar middleware de autenticación para usar `CurrentWorker`
4. ⏳ Actualizar políticas de autorización para usar `CurrentWorker::hasPermission()`
5. ⏳ Crear seeder para roles y workers de prueba
