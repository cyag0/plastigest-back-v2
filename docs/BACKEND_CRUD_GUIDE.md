# 🛠️ Documentación Backend CRUD - PlastiGest Laravel

## 📋 Sistema de Generación Automática de CRUDs

### 🎯 Comando Principal

```bash
php artisan make:crud [ModelName]
php artisan make:crud [Namespace/ModelName]
```

**Ejemplos:**

```bash
# CRUD simple
php artisan make:crud Product

# CRUD con namespace (Admin)
php artisan make:crud Admin/Worker
php artisan make:crud Admin/Customer

# CRUDs para catálogos
php artisan make:crud Category
php artisan make:crud Unit
php artisan make:crud Supplier

# CRUDs para operaciones
php artisan make:crud PurchaseOrder
php artisan make:crud SalesOrder
php artisan make:crud Movement
```

---

## 🏗️ Qué Genera el Comando

### 1. **Modelo** (`/app/Models/[Namespace/]ModelName.php`)

```php
<?php
namespace App\Models\Admin; // Si tiene namespace

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    // Campos que se llenarán automáticamente
    protected $fillable = [];

    // Fechas que se castearán automáticamente
    protected $dates = ['created_at', 'updated_at'];
}
```

### 2. **Controller** (`/app/Http/Controllers/[Namespace/]ModelController.php`)

```php
<?php
namespace App\Http\Controllers\Admin; // Si tiene namespace

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\WorkerResource;
use App\Models\Admin\Worker;

class WorkerController extends CrudController
{
    protected string $resource = WorkerResource::class;
    protected string $model = Worker::class;

    // Métodos automáticos heredados de CrudController:
    // - index() - Lista con paginación opcional
    // - show($id) - Mostrar por ID
    // - store(Request $request) - Crear nuevo
    // - update(Request $request, $id) - Actualizar
    // - destroy($id) - Eliminar
}
```

### 3. **Resource** (`/app/Http/Resources/[Namespace/]ModelResource.php`)

```php
<?php
namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Worker;

class WorkerResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            // Agregar campos básicos del modelo aquí
        ];

        if ($editing) {
            // Datos completos para edición
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
```

---

## 🔧 Configuración Post-Generación

### 1. **Actualizar Modelo** - Agregar Campos y Relaciones

```php
class Worker extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'employee_number',
        'position',
        'hire_date',
        'salary',
        'is_active',
    ];

    // Relaciones
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### 2. **Configurar Controller** - Relaciones y Validaciones

```php
class WorkerController extends CrudController
{
    // Relaciones para el index (lista)
    protected function indexRelations(): array
    {
        return ['company', 'user'];
    }

    // Relaciones para show/edit
    protected function getShowRelations(): array
    {
        return ['company', 'user'];
    }

    // Validación para crear
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'employee_number' => 'required|string|unique:workers,employee_number',
            'position' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
    }

    // Validación para actualizar
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'employee_number' => 'required|string|unique:workers,employee_number,' . $model->id,
            'position' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
    }
}
```

### 3. **Configurar Resource** - Formateo de Datos

```php
class WorkerResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'employee_number' => $resource->employee_number,
            'position' => $resource->position,
            'hire_date' => $resource->hire_date,
            'salary' => $resource->salary,
            'is_active' => $resource->is_active ?? true,
        ];

        // Relaciones
        if ($resource->relationLoaded('company')) {
            $item['company'] = [
                'id' => $resource->company?->id,
                'name' => $resource->company?->name,
            ];
        }

        if ($resource->relationLoaded('user')) {
            $item['user'] = [
                'id' => $resource->user?->id,
                'name' => $resource->user?->name,
                'email' => $resource->user?->email,
            ];
        }

        if ($editing) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
```

---

## 🛣️ Registro de Rutas API

### Ubicación: `/routes/api.php`

**Después de generar el CRUD, SIEMPRE registrar la ruta:**

```php
use App\Http\Controllers\Admin\WorkerController;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::prefix('admin')->group(function () {
            // Rutas existentes...
            Route::apiResource('permissions', PermissionsController::class);
            Route::apiResource('roles', RolesController::class);
            Route::apiResource('companies', CompanyController::class);
            Route::apiResource('locations', LocationController::class);

            // ✅ AGREGAR NUEVA RUTA AQUÍ
            Route::apiResource('workers', WorkerController::class);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('categories', CategoryController::class);
            // ... más rutas según necesidad
        });
    });
});
```

---

## 📋 Checklist Completo para Nuevo CRUD

### ✅ **Paso 1: Generar CRUD**

```bash
cd /home/cyag/plastigest/back/plastigest-back
php artisan make:crud Admin/Worker
```

### ✅ **Paso 2: Crear Migración**

```bash
php artisan make:migration create_workers_table
```

```php
// En la migración
Schema::create('workers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('employee_number', 50)->unique();
    $table->string('position', 100)->nullable();
    $table->date('hire_date')->nullable();
    $table->decimal('salary', 12, 2)->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### ✅ **Paso 3: Ejecutar Migración**

```bash
php artisan migrate
```

### ✅ **Paso 4: Configurar Modelo**

-   Agregar campos a `$fillable`
-   Definir relaciones (`belongsTo`, `hasMany`, etc.)
-   Agregar casts si es necesario

### ✅ **Paso 5: Configurar Controller**

-   Implementar `indexRelations()`
-   Implementar `getShowRelations()`
-   Implementar `validateStoreData()`
-   Implementar `validateUpdateData()`
-   Agregar filtros personalizados si es necesario

### ✅ **Paso 6: Configurar Resource**

-   Implementar método `formatter()`
-   Incluir relaciones cargadas
-   Manejar contexto de edición vs lista

### ✅ **Paso 7: Registrar Ruta API**

```php
Route::apiResource('workers', WorkerController::class);
```

### ✅ **Paso 8: Probar API**

```bash
# Listar
curl -H "Authorization: Bearer {token}" http://localhost/api/auth/admin/workers

# Crear
curl -X POST -H "Authorization: Bearer {token}" \
     -H "Content-Type: application/json" \
     -d '{"company_id":1,"user_id":1,"employee_number":"EMP001"}' \
     http://localhost/api/auth/admin/workers

# Ver
curl -H "Authorization: Bearer {token}" http://localhost/api/auth/admin/workers/1

# Actualizar
curl -X PUT -H "Authorization: Bearer {token}" \
     -H "Content-Type: application/json" \
     -d '{"position":"Manager"}' \
     http://localhost/api/auth/admin/workers/1

# Eliminar
curl -X DELETE -H "Authorization: Bearer {token}" \
     http://localhost/api/auth/admin/workers/1
```

---

## 🎯 Módulos Prioritarios para Implementar

### 🏛️ **Admin** (Namespace: `Admin/`)

```bash
php artisan make:crud Admin/Worker     # ⚠️ CRÍTICO - Empleados
php artisan make:crud Admin/Customer   # Clientes
php artisan make:crud Admin/Supplier   # Proveedores
```

### 📋 **Catálogos** (Sin namespace)

```bash
php artisan make:crud Product          # Productos
php artisan make:crud Category         # Categorías
php artisan make:crud Unit             # Unidades de medida
```

### 🔄 **Operaciones** (Namespace: `Operations/`)

```bash
php artisan make:crud Operations/PurchaseOrder    # Órdenes de compra
php artisan make:crud Operations/SalesOrder       # Órdenes de venta
php artisan make:crud Operations/Movement         # Movimientos de inventario
```

---

## 🚨 Notas Importantes

1. **SIEMPRE** usar el comando `make:crud` - no crear archivos manualmente
2. **SIEMPRE** registrar la ruta en `api.php` después de generar
3. **SIEMPRE** crear la migración correspondiente
4. **SIEMPRE** configurar las relaciones en el modelo
5. **SIEMPRE** implementar validaciones en el controller
6. **SIEMPRE** probar la API antes de continuar al frontend

Este sistema garantiza consistencia y funcionalidad completa en todos los CRUDs del backend PlastiGest.
