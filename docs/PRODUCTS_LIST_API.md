# API: Lista de Productos y Paquetes Combinados

Endpoint para obtener productos y paquetes en un formato unificado, listo para usar en componentes de POS imperativo.

## Endpoint

```
GET /api/auth/admin/purchases/products-list
```

## Parámetros Query

| Parámetro | Tipo | Requerido | Default | Descripción |
|-----------|------|-----------|---------|-------------|
| `type` | string | No | `purchases` | Tipo de operación: `purchases` (compras) o `sales` (ventas) |

## Autenticación

Requiere token de autenticación Sanctum en el header:
```
Authorization: Bearer {token}
```

## Comportamiento

1. **Filtra productos** por la ubicación actual del usuario
2. **Incluye solo productos activos** (`is_active = true`)
3. **Combina productos y paquetes** en un solo array
4. **Formatea el campo `price`** según el tipo:
   - `type=purchases` → usa `purchase_price`
   - `type=sales` → usa `sale_price`

## Respuesta Exitosa

```json
{
  "success": true,
  "data": [
    {
      "id": "p-123",
      "product_id": 123,
      "code": "PROD-001",
      "name": "Producto Ejemplo",
      "price": 150.50,
      "current_stock": 100,
      "category_id": 5,
      "unit_id": 1,
      "unit_name": "Unidad",
      "unit_abbreviation": "uds",
      "main_image": {
        "uri": "https://example.com/image.jpg"
      },
      "type": "product",
      "available_units": [
        {
          "id": 1,
          "name": "Unidad",
          "abbreviation": "uds",
          "factor_to_base": 1,
          "price": 150.50
        },
        {
          "id": 2,
          "name": "Caja",
          "abbreviation": "cja",
          "factor_to_base": 12,
          "price": 1800.00
        }
      ]
    },
    {
      "id": "pkg-456",
      "product_id": 123,
      "package_id": 456,
      "code": "PKG-CAJA-6",
      "name": "Producto Ejemplo - Caja de 6",
      "price": 900.00,
      "current_stock": 16,
      "category_id": 5,
      "unit_id": null,
      "unit_name": "Caja de 6",
      "unit_abbreviation": "Caja de 6",
      "main_image": {
        "uri": "https://example.com/image.jpg"
      },
      "type": "package",
      "quantity_per_package": 6,
      "is_default": false,
      "available_units": []
    }
  ],
  "meta": {
    "total_items": 2,
    "type": "purchases",
    "location_id": 1
  }
}
```

## Estructura de Items

### Producto Individual (`type: "product"`)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | string | ID con prefijo `p-` (ej: `p-123`) |
| `product_id` | integer | ID del producto |
| `code` | string | Código del producto |
| `name` | string | Nombre del producto |
| `price` | float | Precio según tipo (purchase/sale) |
| `current_stock` | float | Stock actual en ubicación |
| `category_id` | integer | ID de categoría |
| `unit_id` | integer | ID de unidad base |
| `unit_name` | string | Nombre de unidad |
| `unit_abbreviation` | string | Abreviación de unidad |
| `main_image` | object\|null | Imagen principal |
| `type` | string | Siempre `"product"` |
| `available_units` | array | Unidades disponibles para cambio |

### Paquete (`type: "package"`)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | string | ID con prefijo `pkg-` (ej: `pkg-456`) |
| `product_id` | integer | ID del producto base |
| `package_id` | integer | ID del paquete |
| `code` | string | Código de barras del paquete |
| `name` | string | Nombre del producto + nombre del paquete |
| `price` | float | Precio del paquete (o calculado) |
| `current_stock` | float | Stock en paquetes (stock_base / qty_per_package) |
| `category_id` | integer | ID de categoría del producto |
| `unit_id` | null | Siempre null para paquetes |
| `unit_name` | string | Nombre del paquete |
| `unit_abbreviation` | string | Nombre del paquete |
| `main_image` | object\|null | Imagen del producto |
| `type` | string | Siempre `"package"` |
| `quantity_per_package` | float | Cantidad de unidades en el paquete |
| `is_default` | boolean | Si es el paquete por defecto |
| `available_units` | array | Siempre vacío para paquetes |

## Ejemplo de Uso en Frontend (POSV3)

```typescript
// Cargar productos y paquetes
const loadProducts = async () => {
  const response = await Services.purchases.getProductsList({
    type: 'purchases' // o 'sales'
  });

  // Formatear a ProductListItem
  const formattedItems: ProductListItem[] = response.data.map(item => ({
    id: item.product_id,
    code: item.code,
    name: item.name,
    price: item.price, // ✅ Ya viene formateado
    current_stock: item.current_stock,
    category_id: item.category_id,
    main_image: item.main_image,
    // Datos adicionales para diferenciar productos y paquetes
    type: item.type,
    package_id: item.package_id,
    quantity_per_package: item.quantity_per_package,
  }));

  setProducts(formattedItems);
};
```

## Notas Importantes

1. **IDs con prefijos**: Los productos llevan prefijo `p-` y los paquetes `pkg-` para evitar colisiones
2. **Stock de paquetes**: Se calcula automáticamente dividiendo el stock base entre `quantity_per_package`
3. **Precio de paquetes**: Si el paquete no tiene precio específico, se calcula multiplicando el precio del producto por `quantity_per_package`
4. **Ubicación**: Solo devuelve productos disponibles en la ubicación actual del usuario
5. **Paquetes activos**: Solo se incluyen paquetes con `is_active = true`

## Casos de Uso

- **POS de Ventas**: Mostrar productos y paquetes para selección rápida
- **POS de Compras**: Recibir productos en diferentes presentaciones
- **Inventario**: Visualizar productos con sus diferentes empaques
- **Reportes**: Análisis de ventas por producto vs paquete
