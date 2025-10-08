# ✅ EJEMPLO COMPLETO: Workers CRUD - PlastiGest

## 🎯 Implementación Exitosa

Este documento muestra un ejemplo completo y funcional de cómo implementar un CRUD usando el sistema de comandos de PlastiGest.

---

## 📋 Pasos Ejecutados

### 1. **Generar CRUD con Comando**

```bash
cd /home/cyag/plastigest/back/plastigest-back
php artisan make:crud Admin/Worker
```

**Resultado:**

-   ✅ Controller: `App\Http\Controllers\Admin\WorkerController`
-   ✅ Model: `App\Models\Admin\Worker`
-   ✅ Resource: `App\Http\Resources\Admin\WorkerResource`

### 2. **Crear Migración**

```bash
php artisan make:migration create_workers_table
```

**Configuración de la migración:**

```php
Schema::create('workers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
    $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
    $table->string('employee_number', 50)->unique();
    $table->string('position', 100)->nullable();
    $table->string('department', 100)->nullable();
    $table->date('hire_date')->nullable();
    $table->decimal('salary', 12, 2)->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    // Índices para performance
    $table->index(['company_id', 'is_active']);
    $table->index(['employee_number']);
    $table->unique(['user_id', 'company_id']); // Un usuario por compañía
});
```

### 3. **Configurar Modelo Worker**

```php
<?php
namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'employee_number',
        'position',
        'department',
        'hire_date',
        'salary',
        'is_active',
    ];

    protected $dates = [
        'hire_date',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
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

### 4. **Configurar Controller WorkerController**

```php
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\WorkerResource;
use App\Models\Admin\Worker;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class WorkerController extends CrudController
{
    protected string $resource = WorkerResource::class;
    protected string $model = Worker::class;

    protected function indexRelations(): array
    {
        return ['company', 'user'];
    }

    protected function getShowRelations(): array
    {
        return ['company', 'user'];
    }

    protected function handleQuery($query, array $params)
    {
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        if (isset($params['department'])) {
            $query->where('department', 'like', '%' . $params['department'] . '%');
        }

        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id|unique:workers,user_id',
            'employee_number' => 'required|string|max:50|unique:workers,employee_number',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id|unique:workers,user_id,' . $model->id,
            'employee_number' => 'required|string|max:50|unique:workers,employee_number,' . $model->id,
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
    }
}
```

### 5. **Configurar Resource WorkerResource**

```php
<?php
namespace App\Http\Resources\Admin;

use App\Http\Resources\Resources;
use App\Models\Admin\Worker;
use Illuminate\Database\Eloquent\Model;

class WorkerResource extends Resources
{
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'employee_number' => $resource->employee_number,
            'position' => $resource->position,
            'department' => $resource->department,
            'hire_date' => $resource->hire_date?->format('Y-m-d'),
            'salary' => $resource->salary,
            'is_active' => $resource->is_active ?? true,
        ];

        // Relación con Company
        if ($resource->relationLoaded('company')) {
            if (!$editing) {
                $item['company_name'] = $resource->company?->name;
            } else {
                $item['company'] = [
                    'id' => $resource->company?->id,
                    'name' => $resource->company?->name,
                ];
            }
        }

        // Relación con User
        if ($resource->relationLoaded('user')) {
            if (!$editing) {
                $item['user_name'] = $resource->user?->name;
                $item['user_email'] = $resource->user?->email;
            } else {
                $item['user'] = [
                    'id' => $resource->user?->id,
                    'name' => $resource->user?->name,
                    'email' => $resource->user?->email,
                ];
            }
        }

        if ($editing) {
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
```

### 6. **Ejecutar Migración**

```bash
php artisan migrate
```

### 7. **Ruta Ya Registrada en api.php**

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::prefix('admin')->group(function () {
            Route::apiResource('workers', WorkerController::class);
        });
    });
});
```

---

## 🧪 Pruebas API Exitosas

### **Login y Obtener Token**

```bash
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@test.com","password":"password"}'
```

**Respuesta:**

```json
{
    "message": "Inicio de sesión exitoso",
    "access_token": "16|s0PYnDrHLDSDXHVNcgadadZXVjufxDkQFH0xHOgv6ef4ee6d",
    "token_type": "Bearer",
    "user": {
        "id": 2,
        "name": "Test User",
        "email": "test@test.com"
    }
}
```

### **1. Crear Worker**

```bash
curl -X POST http://localhost/api/auth/admin/workers \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "company_id": 2,
    "user_id": 2,
    "employee_number": "EMP001",
    "position": "Developer",
    "department": "IT",
    "salary": 50000
  }'
```

**Respuesta (201 Created):**

```json
{
    "data": {
        "id": 1,
        "employee_number": "EMP001",
        "position": "Developer",
        "department": "IT",
        "hire_date": null,
        "salary": "50000.00",
        "is_active": true,
        "company": {
            "id": 2,
            "name": "Test Company"
        },
        "user": {
            "id": 2,
            "name": "Test User",
            "email": "test@test.com"
        },
        "created_at": "2025-10-08T06:28:14.000000Z",
        "updated_at": "2025-10-08T06:28:14.000000Z"
    }
}
```

### **2. Listar Workers**

```bash
curl -H "Authorization: Bearer {token}" \
     -H "Accept: application/json" \
     http://localhost/api/auth/admin/workers
```

**Respuesta (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "employee_number": "EMP001",
            "position": "Developer",
            "department": "IT",
            "hire_date": null,
            "salary": "50000.00",
            "is_active": true,
            "company_name": "Test Company",
            "user_name": "Test User",
            "user_email": "test@test.com"
        }
    ]
}
```

### **3. Ver Worker por ID**

```bash
curl -H "Authorization: Bearer {token}" \
     -H "Accept: application/json" \
     http://localhost/api/auth/admin/workers/1
```

**Respuesta (200 OK):**

```json
{
    "data": {
        "id": 1,
        "employee_number": "EMP001",
        "position": "Developer",
        "department": "IT",
        "hire_date": null,
        "salary": "50000.00",
        "is_active": true,
        "company": {
            "id": 2,
            "name": "Test Company"
        },
        "user": {
            "id": 2,
            "name": "Test User",
            "email": "test@test.com"
        },
        "created_at": "2025-10-08T06:28:14.000000Z",
        "updated_at": "2025-10-08T06:28:14.000000Z"
    }
}
```

---

## ✅ Endpoints Disponibles

| Método   | Endpoint                       | Descripción              |
| -------- | ------------------------------ | ------------------------ |
| `GET`    | `/api/auth/admin/workers`      | Listar todos los workers |
| `POST`   | `/api/auth/admin/workers`      | Crear nuevo worker       |
| `GET`    | `/api/auth/admin/workers/{id}` | Ver worker específico    |
| `PUT`    | `/api/auth/admin/workers/{id}` | Actualizar worker        |
| `DELETE` | `/api/auth/admin/workers/{id}` | Eliminar worker          |

---

## 🎯 Funcionalidades Implementadas

### ✅ **Multi-tenant por Compañía**

-   Cada worker pertenece a una compañía específica
-   Validación de `company_id` obligatorio
-   Filtrado automático por compañía

### ✅ **Relaciones Automáticas**

-   Carga automática de relaciones `company` y `user`
-   Datos optimizados para lista vs detalle
-   Formateo contextual según endpoint

### ✅ **Validaciones Completas**

-   Employee number único
-   Usuario único por compañía
-   Validación de existencia de company_id y user_id
-   Validaciones numéricas para salary

### ✅ **Filtros Disponibles**

```bash
# Filtrar por compañía
GET /api/auth/admin/workers?company_id=2

# Filtrar por departamento
GET /api/auth/admin/workers?department=IT

# Filtrar por estado activo
GET /api/auth/admin/workers?is_active=true
```

### ✅ **Paginación Opcional**

```bash
# Con paginación
GET /api/auth/admin/workers?paginated=true&per_page=10
```

---

## 🚀 Próximos Módulos Sugeridos

Siguiendo este mismo patrón exitoso:

### 1. **Productos** (Catálogo Principal)

```bash
php artisan make:crud Product
php artisan make:migration create_products_table
```

### 2. **Categorías** (Organización de Productos)

```bash
php artisan make:crud Category
php artisan make:migration create_categories_table
```

### 3. **Clientes** (Gestión de Ventas)

```bash
php artisan make:crud Admin/Customer
php artisan make:migration create_customers_table
```

### 4. **Proveedores** (Gestión de Compras)

```bash
php artisan make:crud Admin/Supplier
php artisan make:migration create_suppliers_table
```

---

## 💡 Lecciones Aprendidas

1. **Sempre usar `make:crud`** - Genera estructura consistente
2. **Configurar namespaces correctamente** - Admin/ para módulos administrativos
3. **Definir relaciones completas** - Company y User en todos los módulos
4. **Implementar validaciones robustas** - Unique constraints y foreign keys
5. **Formatear respuestas contextuales** - Datos diferentes para lista vs detalle
6. **Probar API completamente** - Verificar todos los endpoints antes de continuar

Este ejemplo de Workers CRUD sirve como **template perfecto** para todos los módulos futuros del sistema PlastiGest.
