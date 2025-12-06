# 📦 Product Packages - Implementación Completa

## 🎯 Descripción General

Sistema para gestionar diferentes presentaciones/empaques de un mismo producto, cada uno con su propio código de barras. Permite vender productos por unidad, caja, display, pallet, etc., manteniendo un inventario unificado en unidades base.

---

## 🗄️ Base de Datos

### Tabla: `product_packages`

```sql
CREATE TABLE product_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    
    -- Información básica del empaque
    package_name VARCHAR(100) NOT NULL,        -- "Caja de 6", "Display de 24"
    barcode VARCHAR(100) UNIQUE NOT NULL,      -- Código de barras único
    quantity_per_package DECIMAL(10,2) NOT NULL, -- Cuántas unidades base contiene
    
    -- Precios específicos del empaque (opcional)
    purchase_price DECIMAL(10,2) NULL,
    sale_price DECIMAL(10,2) NULL,
    
    -- Información adicional en JSON (peso, dimensiones, SKU, etc.)
    content JSON NULL,                         -- { "weight": "5kg", "dimensions": "30x20x15" }
    
    -- Control
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,          -- Empaque por defecto para ventas
    sort_order INT DEFAULT 0,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    
    INDEX idx_barcode (barcode),
    INDEX idx_product (product_id),
    INDEX idx_company (company_id)
);
```

### Campo `content` - Estructura JSON

```json
{
  "weight": "5.5kg",
  "dimensions": "30x20x15cm",
  "sku": "BOT-500-CJ6",
  "volume": "0.009m³",
  "ean_13": "7501234567890",
  "custom_field_1": "Valor personalizado",
  "notes": "Notas adicionales del empaque"
}
```

**Ventajas del campo JSON:**
- ✅ Flexibilidad total para agregar datos sin modificar esquema
- ✅ No afecta rendimiento de queries principales
- ✅ Ideal para datos que no se usan en reportes/filtros
- ✅ Fácil de extender con nuevos campos

---

## 📝 Modelo Eloquent

**Archivo:** `app/Models/ProductPackage.php`

### Propiedades Principales

```php
protected $fillable = [
    'product_id',
    'company_id',
    'package_name',
    'barcode',
    'quantity_per_package',
    'purchase_price',
    'sale_price',
    'content',          // JSON field
    'is_active',
    'is_default',
    'sort_order',
];

protected $casts = [
    'quantity_per_package' => 'decimal:2',
    'purchase_price' => 'decimal:2',
    'sale_price' => 'decimal:2',
    'content' => 'array',  // Convierte JSON a array automáticamente
    'is_active' => 'boolean',
    'is_default' => 'boolean',
];
```

### Relaciones

```php
// Con el producto
public function product(): BelongsTo
{
    return $this->belongsTo(Product::class);
}

// Con la compañía
public function company(): BelongsTo
{
    return $this->belongsTo(\App\Models\Admin\Company::class);
}
```

### Scopes Útiles

```php
// Solo empaques activos
ProductPackage::active()->get();

// Buscar por código de barras
ProductPackage::byBarcode('7501234567890')->first();
```

### Accessor

```php
// Nombre con cantidad para UI
$package->display_name; // "Caja de 6 (6 uds)"
```

---

## 🛣️ Rutas API

**Archivo:** `routes/api.php`

```php
Route::middleware('auth:sanctum')->prefix('auth/admin')->group(function () {
    // CRUD completo
    Route::apiResource('product-packages', ProductPackageController::class);
    
    // Rutas adicionales
    Route::post('product-packages/search-barcode', [
        ProductPackageController::class, 
        'searchByBarcode'
    ]);
    
    Route::post('product-packages/generate-barcode', [
        ProductPackageController::class, 
        'generateBarcode'
    ]);
});
```

### Endpoints Disponibles

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/product-packages` | Listar todos los empaques (con filtros) |
| GET | `/product-packages/{id}` | Ver detalle de un empaque |
| POST | `/product-packages` | Crear nuevo empaque |
| PUT | `/product-packages/{id}` | Actualizar empaque |
| DELETE | `/product-packages/{id}` | Eliminar empaque |
| POST | `/product-packages/search-barcode` | Buscar por código de barras |
| POST | `/product-packages/generate-barcode` | Generar código único |

---

## 🎮 Controlador

**Archivo:** `app/Http/Controllers/ProductPackageController.php`

### Métodos Principales

#### 1. **index** - Listar con filtros

```php
GET /product-packages?product_id=123&active_only=true

Filtros:
- product_id: Filtrar por producto
- company_id: Filtrar por compañía
- active_only: Solo activos (boolean)
```

#### 2. **store** - Crear empaque

```php
POST /product-packages

Payload:
{
  "product_id": 123,
  "company_id": 1,
  "package_name": "Caja de 6",
  "barcode": "7501234567891",
  "quantity_per_package": 6,
  "sale_price": 80.00,
  "content": {
    "weight": "3kg",
    "dimensions": "30x20x10cm"
  },
  "is_default": true
}

Lógica especial:
- Si is_default=true, desmarca otros empaques del mismo producto
```

#### 3. **update** - Actualizar empaque

```php
PUT /product-packages/{id}

- Valida unicidad de barcode excluyendo el actual
- Maneja is_default correctamente
```

#### 4. **searchByBarcode** - Buscar por código

```php
POST /product-packages/search-barcode

Payload:
{
  "barcode": "7501234567891"
}

Response:
{
  "id": 5,
  "product_id": 123,
  "package_name": "Caja de 6",
  "barcode": "7501234567891",
  "quantity_per_package": 6,
  "sale_price": 80.00,
  "product": { ... },
  "display_name": "Caja de 6 (6 uds)"
}
```

#### 5. **generateBarcode** - Generar código único

```php
POST /product-packages/generate-barcode

Payload:
{
  "product_id": 123
}

Response:
{
  "barcode": "PKG-123-1732745123456"
}

Formato: PKG-{productId}-{timestamp}{random}
```

---

## 🔗 Integración con Product Model

**Archivo:** `app/Models/Product.php`

### Relaciones agregadas

```php
// Todos los empaques del producto
public function packages()
{
    return $this->hasMany(ProductPackage::class)->orderBy('sort_order');
}

// Solo empaques activos
public function activePackages()
{
    return $this->hasMany(ProductPackage::class)
        ->where('is_active', true)
        ->orderBy('sort_order');
}

// Empaque por defecto
public function defaultPackage()
{
    return $this->hasOne(ProductPackage::class)
        ->where('is_default', true)
        ->where('is_active', true);
}
```

### Uso

```php
$product = Product::with('packages')->find(123);

// Obtener todos los empaques
$product->packages;

// Solo activos
$product->activePackages;

// Empaque por defecto
$product->defaultPackage;
```

---

## 💻 Frontend - Servicios

**Archivo:** `utils/services/index.ts`

```typescript
const Services = {
  // ... otros servicios
  
  productPackages: {
    ...createCrudService<any>("/auth/admin/product-packages"),
    
    async searchByBarcode(barcode: string) {
      const response = await axiosClient.post(
        "/auth/admin/product-packages/search-barcode",
        { barcode }
      );
      return response.data;
    },
    
    async generateBarcode(productId: number) {
      const response = await axiosClient.post(
        "/auth/admin/product-packages/generate-barcode",
        { product_id: productId }
      );
      return response.data;
    },
  },
};
```

### Uso en componentes

```typescript
// Listar empaques de un producto
const packages = await Services.productPackages.index({
  product_id: 123
});

// Crear empaque
await Services.productPackages.store({
  product_id: 123,
  package_name: "Caja de 6",
  barcode: scannedCode,
  quantity_per_package: 6,
  sale_price: 80.00
});

// Buscar por código de barras
const package = await Services.productPackages.searchByBarcode("7501234567891");

// Generar código único
const { barcode } = await Services.productPackages.generateBarcode(123);
```

---

## 🎨 Estructura UI Recomendada

### En Detalle del Producto

```
Product Detail
├── Tab: Información
├── Tab: Paquetes ← NUEVO
│   ├── Lista de empaques
│   │   ├── Unidad (1) - $15.00 [Default]
│   │   ├── Caja de 6 (6) - $80.00
│   │   └── Display de 24 (24) - $300.00
│   └── [Botón: Agregar Empaque]
├── Tab: Stock
└── Tab: Movimientos
```

### Formulario de Empaque

```
┌─────────────────────────────────────┐
│ Crear Nuevo Empaque                 │
├─────────────────────────────────────┤
│ Nombre del Empaque: [Caja de 6___] │
│                                     │
│ Código de Barras: [_______________] │
│   [📷 Escanear] [🎲 Generar]       │
│                                     │
│ Cantidad por Empaque: [6__________] │
│ Precio de Venta: [$80.00_________] │
│ Precio de Compra: [$75.00________] │
│                                     │
│ ☑ Empaque activo                   │
│ ☐ Empaque por defecto              │
│                                     │
│ Información Adicional (Opcional):   │
│ Peso: [3kg___]  Dimensiones: [___] │
│                                     │
│ [Cancelar]            [Guardar]    │
└─────────────────────────────────────┘
```

---

## 🔄 Flujo de Trabajo

### Escenario 1: Crear Producto con Empaque Base

1. Usuario crea producto "Agua 500ml"
2. Código de barras: `7501234567890` (unidad)
3. Sistema crea automáticamente empaque base con quantity=1

### Escenario 2: Agregar Empaques Adicionales

1. Usuario entra al detalle del producto
2. Tab "Paquetes" → "Agregar Empaque"
3. Ingresa datos: "Caja de 6", escanea código `7501234567891`, quantity=6
4. Guarda → Sistema valida unicidad de código

### Escenario 3: Escanear en Venta

1. Cajero escanea `7501234567891`
2. Sistema busca en `product_packages`
3. Encuentra: "Caja de 6" (6 unidades)
4. Agrega al carrito: 6 unidades del producto Agua
5. Precio: $80 (del empaque, no del producto base)

### Escenario 4: Generación Automática

1. Usuario no tiene etiqueta física
2. Clic en "Generar Código"
3. Sistema genera: `PKG-123-1732745123456`
4. Usuario imprime etiqueta con ese código

---

## ✅ Validaciones Implementadas

### Backend

- ✅ `barcode` único en toda la tabla
- ✅ `product_id` debe existir en products
- ✅ `company_id` debe existir en companies
- ✅ `quantity_per_package` > 0
- ✅ Solo un empaque puede ser `is_default=true` por producto
- ✅ Al actualizar, excluye el registro actual en validación de unicidad

### Lógica de Negocio

- ✅ Al marcar empaque como default, desmarca los demás
- ✅ Códigos de barras generados son únicos
- ✅ Transacciones para operaciones críticas

---

## 📊 Ejemplo de Datos

```json
// Producto: Agua Embotellada 500ml
{
  "id": 123,
  "name": "Agua Embotellada 500ml",
  "code": "7501234567890",
  "sale_price": 15.00,
  "packages": [
    {
      "id": 1,
      "package_name": "Unidad",
      "barcode": "7501234567890",
      "quantity_per_package": 1,
      "sale_price": 15.00,
      "is_default": true,
      "content": null
    },
    {
      "id": 2,
      "package_name": "Paquete de 6",
      "barcode": "7501234567891",
      "quantity_per_package": 6,
      "sale_price": 80.00,
      "content": {
        "weight": "3kg",
        "dimensions": "30x20x10cm",
        "ean": "7501234567891"
      }
    },
    {
      "id": 3,
      "package_name": "Caja de 24",
      "barcode": "7501234567892",
      "quantity_per_package": 24,
      "sale_price": 300.00,
      "content": {
        "weight": "12kg",
        "dimensions": "60x40x20cm"
      }
    }
  ]
}
```

---

## 🚀 Próximos Pasos

### Frontend (Pendientes)

1. ✅ Servicio API agregado
2. ⏳ Tab "Paquetes" en detalle del producto
3. ⏳ Formulario para crear/editar empaques
4. ⏳ Integración con escáner de código de barras
5. ⏳ Búsqueda de empaque al escanear en ventas/compras
6. ⏳ Impresión de etiquetas de empaques

### Backend (Completado)

- ✅ Migración
- ✅ Modelo con relaciones
- ✅ Controlador completo
- ✅ Rutas API
- ✅ Validaciones
- ✅ Generación de códigos únicos

---

## 📌 Notas Importantes

1. **Campo `content`**: Almacena datos adicionales que NO se usan en reportes. Si un campo se necesita filtrar/reportar frecuentemente, debe ser columna real.

2. **Inventario Unificado**: Todos los empaques se manejan en unidades base. Al vender "1 caja de 6", se descuentan 6 unidades del inventario.

3. **Códigos Únicos**: El sistema garantiza unicidad global de códigos de barras entre productos y empaques.

4. **Default Package**: Útil para punto de venta, permite tener un empaque predeterminado al buscar producto por nombre.

5. **Precios por Empaque**: Permite descuentos por volumen (precio unitario vs precio de caja).

---

## 🔧 Comandos Útiles

```bash
# Ejecutar migración
php artisan migrate

# Rollback (si necesario)
php artisan migrate:rollback

# Ver estructura de tabla
php artisan db:show product_packages

# Crear empaque de prueba
php artisan tinker
>>> ProductPackage::create([
  'product_id' => 1,
  'company_id' => 1,
  'package_name' => 'Caja de 6',
  'barcode' => 'TEST-001',
  'quantity_per_package' => 6,
  'sale_price' => 80.00
])
```

---

**Documentado por:** GitHub Copilot  
**Fecha:** 27 de noviembre de 2025  
**Versión:** 1.0
