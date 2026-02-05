# Implementación del Módulo de Compras V2

## Archivos Creados

### 1. Migraciones

#### `2026_01_25_000000_update_purchases_table.php`
- Actualiza tabla `purchases` con nueva estructura
- Estados: draft → ordered → in_transit → received | cancelled
- Campos principales:
  - `supplier_id`: Relación con proveedores
  - `status`: Estado del flujo de compra
  - `total`: Total de la compra
  - `expected_delivery_date`, `delivery_date`
  - `received_by`: Usuario que recibe

#### `2026_01_25_000001_create_purchase_details_table.php`
- Tabla de detalles de compra
- Campos clave:
  - `product_id`: Producto base (siempre)
  - `package_id`: Si es paquete (nullable)
  - `quantity`, `unit_id`: Cantidad y unidad pedida
  - `unit_price`, `total`: Precios
  - `quantity_received`: Cantidad recibida (nullable hasta recibir)

### 2. Modelos

#### `PurchaseV2.php`
- Modelo principal de compras
- Métodos:
  - `generatePurchaseNumber()`: Genera número automático
  - `calculateTotal()`: Calcula total de detalles
  - `updateTotal()`: Actualiza total
- Scopes: `draft()`, `ordered()`, `inTransit()`, `received()`, `cancelled()`

#### `PurchaseDetailV2.php`
- Modelo de detalles
- Métodos importantes:
  - `isPackage()`: Verifica si es paquete
  - `getQuantityInBaseUnit()`: Convierte paquetes a unidad base
    - Ejemplo: 1 paquete de 12 = 12 unidades base
  - `calculateTotal()`: Calcula total automáticamente
- Boot events: Actualiza total de compra al guardar/eliminar

### 3. Controlador

#### `PurchaseV2Controller.php`

**Endpoints principales:**

1. **`POST /api/purchases-v2/draft`** - Guardar compra en tiempo real
   - Se llama cada vez que se agrega/modifica/elimina producto
   - Recibe array de items con `selectedProducts` del frontend
   - Crea o actualiza compra en estado `draft`
   - Sincroniza detalles automáticamente

2. **`GET /api/purchases-v2/draft`** - Obtener compra draft actual
   - Retorna última compra en estado draft
   - Incluye relaciones (productos, paquetes, unidades)

3. **`POST /api/purchases-v2/{id}/confirm`** - Confirmar pedido
   - Cambia estado a `ordered`
   - Valida que tenga productos
   - Establece fecha esperada de entrega

4. **`POST /api/purchases-v2/{id}/mark-in-transit`** - Marcar en tránsito
   - Cambia de `ordered` → `in_transit`

5. **`POST /api/purchases-v2/{id}/receive`** - Recibir compra
   - Recibe cantidades recibidas por producto
   - Convierte paquetes a unidad base
   - Actualiza stock en `product_location`
   - Cambia estado a `received`

6. **`POST /api/purchases-v2/{id}/cancel`** - Cancelar
   - Permite cancelar en estados: draft, ordered, in_transit

7. **`GET /api/purchases-v2`** - Listar compras
   - Filtros: status, location_id
   - Paginado

8. **`GET /api/purchases-v2/{id}`** - Ver detalle
   - Incluye todas las relaciones

## Flujo de Trabajo

### 1. Creación de Compra (Draft)

**Frontend:**
```typescript
// En PurchaseContext.tsx - handleAddProduct
const handleAddProduct = async (product: ProductListItem, unitId: number) => {
  // Agregar al estado local
  setSelectedProducts(prev => ({...prev, [product.id]: {...}}));
  
  // Sincronizar con backend inmediatamente
  await Services.purchasesV2.upsertDraft({
    purchase_id: currentPurchaseId,
    supplier_id: supplierId,
    items: Object.values(selectedProducts)
  });
};
```

**Backend:**
- Busca compra draft existente o crea nueva
- Sincroniza items usando `syncPurchaseDetails()`
- Actualiza/crea/elimina detalles según corresponda
- Retorna `purchase_id` y `total`

### 2. Modificar Cantidad/Unidad

**Frontend:**
```typescript
const handleItemChange = async (productId, action, data) => {
  // Actualizar estado local
  setSelectedProducts(prev => {...});
  
  // Sincronizar con backend
  await Services.purchasesV2.upsertDraft({
    purchase_id: currentPurchaseId,
    items: Object.values(selectedProducts)
  });
};
```

### 3. Eliminar Producto

**Frontend:**
```typescript
const handleRemoveProduct = async (productId) => {
  // Remover de estado local
  const {[productId]: removed, ...rest} = selectedProducts;
  setSelectedProducts(rest);
  
  // Sincronizar con backend (sin el producto eliminado)
  await Services.purchasesV2.upsertDraft({
    purchase_id: currentPurchaseId,
    items: Object.values(rest)
  });
};
```

### 4. Confirmar Compra

**Frontend:**
```typescript
const confirmPurchase = async () => {
  await Services.purchasesV2.confirm(purchaseId, {
    expected_delivery_date: '2026-02-01',
    document_number: 'FAC-12345'
  });
};
```

### 5. Recibir Compra

**Backend (método receive):**
```php
// Recibe cantidades y actualiza stock
foreach ($details as $detail) {
  // Si es paquete: 1 paquete × 12 = 12 unidades
  $quantityInBase = $detail->getQuantityInBaseUnit();
  
  // Actualiza product_location
  DB::table('product_location')
    ->where('product_id', $detail->product_id)
    ->where('location_id', $purchase->location_id)
    ->increment('current_stock', $quantityInBase);
}
```

## Conversión de Paquetes

**Ejemplo: Compra de cocos**

1. **Pedido:**
   - 1 paquete de 12 cocos (barcode: PKG-COCO-12)
   - 1 coco suelto

2. **En purchase_details:**
   ```
   ID | product_id | package_id | quantity | unit_id
   1  | 5 (coco)   | 3          | 1        | 1 (pz)
   2  | 5 (coco)   | NULL       | 1        | 1 (pz)
   ```

3. **Al recibir:**
   ```php
   // Detail 1 (paquete)
   $quantityInBase = 1 × 12 = 12 cocos
   
   // Detail 2 (suelto)
   $quantityInBase = 1 coco
   
   // Total a agregar al stock: 13 cocos
   ```

## Integración con Frontend

### Servicios a Crear

```typescript
// utils/services/index.ts
purchasesV2: {
  upsertDraft: (data: {
    purchase_id?: number;
    supplier_id?: number;
    notes?: string;
    items: Array<{
      id: string;
      product_id: number;
      package_id?: number;
      quantity: number;
      unit_id: number;
      price: number;
    }>;
  }) => apiClient.post('/purchases-v2/draft', data),
  
  getDraft: () => apiClient.get('/purchases-v2/draft'),
  
  confirm: (id: number, data: {
    expected_delivery_date?: string;
    document_number?: string;
  }) => apiClient.post(`/purchases-v2/${id}/confirm`, data),
  
  markInTransit: (id: number) => apiClient.post(`/purchases-v2/${id}/mark-in-transit`),
  
  receive: (id: number, details: Array<{
    id: number;
    quantity_received: number;
  }>) => apiClient.post(`/purchases-v2/${id}/receive`, { details }),
  
  cancel: (id: number) => apiClient.post(`/purchases-v2/${id}/cancel`),
  
  index: (params?: any) => apiClient.get('/purchases-v2', { params }),
  
  show: (id: number) => apiClient.get(`/purchases-v2/${id}`),
}
```

### Actualizar PurchaseContext

```typescript
// Agregar currentPurchaseId al estado
const [currentPurchaseId, setCurrentPurchaseId] = useState<number | null>(null);

// Modificar handleAddProduct
const handleAddProduct = async (product: ProductListItem, unitId: number) => {
  // ... código existente para actualizar estado local ...
  
  // Sincronizar con backend
  const response = await Services.purchasesV2.upsertDraft({
    purchase_id: currentPurchaseId,
    items: Object.entries(newSelectedProducts).map(([key, item]) => ({
      id: key,
      product_id: item.product_id,
      package_id: item.package_id,
      quantity: item.quantity,
      unit_id: item.unit_id,
      price: item.price,
    }))
  });
  
  setCurrentPurchaseId(response.data.purchase_id);
};
```

## Rutas a Agregar

Agregar en `routes/api.php` dentro de `Route::middleware('auth:sanctum')`:

```php
use App\Http\Controllers\PurchaseV2Controller;

Route::prefix('purchases-v2')->group(function () {
    Route::post('draft', [PurchaseV2Controller::class, 'upsertDraft']);
    Route::get('draft', [PurchaseV2Controller::class, 'getDraft']);
    Route::post('{id}/confirm', [PurchaseV2Controller::class, 'confirm']);
    Route::post('{id}/mark-in-transit', [PurchaseV2Controller::class, 'markInTransit']);
    Route::post('{id}/receive', [PurchaseV2Controller::class, 'receive']);
    Route::post('{id}/cancel', [PurchaseV2Controller::class, 'cancel']);
    Route::get('', [PurchaseV2Controller::class, 'index']);
    Route::get('{id}', [PurchaseV2Controller::class, 'show']);
});
```

## Comandos para Ejecutar

```bash
# 1. Ejecutar migraciones
php artisan migrate

# 2. Probar endpoint (opcional)
php artisan tinker
>>> PurchaseV2::generatePurchaseNumber()
```

## Próximos Pasos

1. ✅ Crear modelos y migraciones
2. ✅ Crear controlador con lógica de negocio
3. ⏳ Agregar rutas en api.php
4. ⏳ Crear servicios en frontend
5. ⏳ Integrar con PurchaseContext
6. ⏳ Crear vistas para recibir compras
7. ⏳ Agregar creación de movimientos/kardex al recibir
