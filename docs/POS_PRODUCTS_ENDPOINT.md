# 📦 Endpoint POS Products - Productos Combinados con Paquetes

## 🎯 Descripción General

Endpoint que devuelve una colección combinada de productos y sus paquetes para el sistema POS. Los paquetes comparten el stock del producto original y utilizan la misma imagen.

---

## 🛣️ Ruta API

```
GET /auth/admin/products/pos
```

**Nota:** Esta ruta debe estar definida ANTES del `apiResource` de productos para que no sea capturada por las rutas del resource.

---

## 📥 Parámetros de Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `location_id` | integer | No | ID de la ubicación. Si no se proporciona, usa `current_location_id()` |
| `company_id` | integer | No | ID de la empresa. Si no se proporciona, usa `current_company_id()` |
| `category_id` | integer | No | Filtrar por categoría |
| `with_stock` | boolean | No | Si es `true`, solo devuelve productos con stock disponible |
| `search` | string | No | Buscar por nombre o código del producto |

---

## 📤 Respuesta

### Estructura de Respuesta

```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Agua Embotellada 500ml",
      "code": "7501234567890",
      "description": "Agua purificada 500ml",
      "sale_price": 15.00,
      "category_id": 5,
      "unit_id": 1,
      "product_type": "commercial",
      "current_stock": 100,
      "image_url": "products/agua-500ml.jpg",
      "is_package": false,
      "package_id": null,
      "quantity_per_package": 1,
      "original_product_id": 123
    },
    {
      "id": "pkg_1_123",
      "name": "Agua Embotellada 500ml - Paquete de 6",
      "code": "7501234567891",
      "description": "Agua purificada 500ml",
      "sale_price": 80.00,
      "category_id": 5,
      "unit_id": 1,
      "product_type": "commercial",
      "current_stock": 16,
      "image_url": "products/agua-500ml.jpg",
      "is_package": true,
      "package_id": 1,
      "quantity_per_package": 6,
      "original_product_id": 123,
      "package_name": "Paquete de 6",
      "display_name": "Paquete de 6 (6 uds)"
    },
    {
      "id": "pkg_2_123",
      "name": "Agua Embotellada 500ml - Caja de 24",
      "code": "7501234567892",
      "description": "Agua purificada 500ml",
      "sale_price": 300.00,
      "category_id": 5,
      "unit_id": 1,
      "product_type": "commercial",
      "current_stock": 4,
      "image_url": "products/agua-500ml.jpg",
      "is_package": true,
      "package_id": 2,
      "quantity_per_package": 24,
      "original_product_id": 123,
      "package_name": "Caja de 24",
      "display_name": "Caja de 24 (24 uds)"
    }
  ]
}
```

### Campos de Respuesta

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | mixed | ID del producto (número) o ID compuesto del paquete (string: `pkg_{package_id}_{product_id}`) |
| `name` | string | Nombre del producto o producto + nombre del paquete |
| `code` | string | Código del producto o código de barras del paquete |
| `description` | string | Descripción del producto |
| `sale_price` | decimal | Precio de venta del producto o paquete |
| `category_id` | integer | ID de la categoría del producto |
| `unit_id` | integer | ID de la unidad del producto |
| `product_type` | string | Tipo de producto (`raw_material`, `processed`, `commercial`) |
| `current_stock` | decimal | Stock actual. Para paquetes, es el stock compartido calculado |
| `image_url` | string\|null | URL de la imagen principal (compartida con el producto) |
| `is_package` | boolean | `true` si es un paquete, `false` si es el producto base |
| `package_id` | integer\|null | ID del paquete (null para productos base) |
| `quantity_per_package` | decimal | Cantidad de unidades por paquete (1 para productos base) |
| `original_product_id` | integer | ID del producto original |
| `package_name` | string | Nombre del paquete (solo para paquetes) |
| `display_name` | string | Nombre de visualización (solo para paquetes) |

---

## 📊 Cálculo de Stock Compartido

El stock de los paquetes se calcula dividiendo el stock del producto original entre la cantidad por paquete:

```php
$packageStock = floor($currentStock / $package->quantity_per_package)
```

**Ejemplo:**
- Producto tiene 100 unidades en stock
- Paquete de 6 unidades: `floor(100 / 6) = 16` paquetes disponibles
- Paquete de 24 unidades: `floor(100 / 24) = 4` paquetes disponibles

---

## 🔧 Implementación Backend

### Controlador

**Archivo:** `app/Http/Controllers/ProductController.php`

```php
public function getPosProducts(Request $request) {
    // Implementación completa en el archivo
}
```

### Relaciones Cargadas

- `mainImage`: Imagen principal del producto
- `activePackages`: Paquetes activos del producto
- `locations`: Ubicaciones con stock del producto

### Filtros Aplicados

1. Solo productos con `for_sale = true`
2. Filtro por empresa actual o especificada
3. Filtro por categoría (opcional)
4. Filtro por stock disponible (opcional)
5. Búsqueda por nombre o código (opcional)

---

## 💻 Implementación Frontend

### Servicio

**Archivo:** `utils/services/index.ts`

```typescript
products: {
  ...createCrudService<App.Entities.Product>("/auth/admin/products"),
  async getPosProducts(params?: {
    location_id?: number;
    company_id?: number;
    category_id?: number;
    with_stock?: boolean;
    search?: string;
  }) {
    const response = await axiosClient.get("/auth/admin/products/pos", {
      params,
    });
    return response.data;
  },
}
```

### Uso en Componentes

```typescript
const loadData = async () => {
  const productsRes = await Services.products.getPosProducts({
    location_id: selectedLocation?.id,
    with_stock: true,
  });
  
  const productsData = productsRes?.data || [];
  setProducts(productsData);
};
```

---

## ✅ Características Principales

1. **Stock Compartido**: Los paquetes comparten el stock del producto original
2. **Imagen Compartida**: Los paquetes usan la misma imagen del producto
3. **ID Único**: Cada paquete tiene un ID compuesto único (`pkg_{package_id}_{product_id}`)
4. **Cálculo Automático**: El stock de paquetes se calcula automáticamente
5. **Filtros Flexibles**: Múltiples opciones de filtrado disponibles

---

## 🎨 Casos de Uso

### POS (Punto de Venta)
- Mostrar productos y paquetes en una sola vista
- Escanear códigos de barras de productos y paquetes
- Vender por unidad o por paquete

### Búsqueda por Código de Barras
```typescript
const result = await Services.products.getPosProducts({
  search: "7501234567891"
});
```

### Solo Productos con Stock
```typescript
const result = await Services.products.getPosProducts({
  location_id: 5,
  with_stock: true
});
```

### Filtrar por Categoría
```typescript
const result = await Services.products.getPosProducts({
  category_id: 10
});
```

---

## 🚀 Próximos Pasos

- [ ] Implementar caché para mejorar rendimiento
- [ ] Agregar paginación para grandes volúmenes de datos
- [ ] Implementar búsqueda por nombre de paquete
- [ ] Agregar soporte para imágenes específicas de paquetes

---

**Documentado por:** GitHub Copilot  
**Fecha:** 12 de enero de 2026  
**Versión:** 1.0
