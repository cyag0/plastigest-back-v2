# 🧪 Plan de Pruebas - Sistema de Transferencias

## Objetivo
Validar el flujo completo de requisición de productos entre sucursales.

---

## ⚙️ Preparación Inicial

### 1. Verificar que el servidor esté corriendo
```bash
cd \\wsl.localhost\Ubuntu\home\gabet\plastigest-backend\plastigest-back-v2
docker ps
```

**Resultado esperado:** Contenedores corriendo (laravel.test, mysql, etc.)

---

### 2. Verificar datos base necesarios

**Ejecutar en MySQL:**
```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```

**Dentro de tinker:**
```php
// Verificar que existan ubicaciones
\App\Models\Location::all(['id', 'name', 'is_main']);

// Verificar que existan productos
\App\Models\Product::take(5)->get(['id', 'name', 'sku']);

// Verificar que exista stock en la matriz
DB::table('product_location')
  ->join('locations', 'product_location.location_id', '=', 'locations.id')
  ->where('locations.is_main', true)
  ->select('product_location.*', 'locations.name as location_name')
  ->get();

// Verificar usuarios
\App\Models\User::all(['id', 'name', 'email']);

exit
```

---

### 3. Si NO hay datos, crear datos de prueba

```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```

```php
// Crear ubicación matriz si no existe
$matriz = \App\Models\Location::firstOrCreate(
    ['name' => 'Matriz'],
    [
        'company_id' => 1,
        'is_main' => true,
        'address' => 'Av. Principal 123',
        'phone' => '555-0001'
    ]
);

// Crear sucursal
$sucursal = \App\Models\Location::firstOrCreate(
    ['name' => 'Sucursal Norte'],
    [
        'company_id' => 1,
        'is_main' => false,
        'address' => 'Calle Norte 456',
        'phone' => '555-0002'
    ]
);

// Crear producto de prueba si no existe
$product = \App\Models\Product::first();
if (!$product) {
    echo "No hay productos. Crear uno manualmente o ejecutar seeders.\n";
} else {
    echo "Producto disponible: {$product->name} (ID: {$product->id})\n";
    
    // Agregar stock en la matriz
    DB::table('product_location')->updateOrInsert(
        ['product_id' => $product->id, 'location_id' => $matriz->id],
        ['quantity' => 500, 'updated_at' => now()]
    );
    
    echo "Stock agregado: 500 unidades en Matriz\n";
}

exit
```

---

## 🧪 Suite de Pruebas

### **Test 1: Crear Requisición (Sucursal)**

**Endpoint:** `POST /api/auth/admin/inventory-transfers`

**cURL:**
```bash
curl -X POST http://localhost/api/auth/admin/inventory-transfers \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": 1,
    "from_location_id": 1,
    "to_location_id": 2,
    "notes": "Requisición de prueba - resurtir inventario",
    "details": [
      {
        "product_id": 1,
        "quantity_requested": 100,
        "unit_cost": 150.50,
        "notes": "Producto urgente"
      }
    ]
  }'
```

**Resultado Esperado:**
```json
{
  "message": "Transferencia creada exitosamente",
  "data": {
    "id": 1,
    "transfer_number": "TRANS-20231112-0001",
    "status": "pending",
    "status_label": "Pendiente",
    "requested_by_user": {
      "id": 1,
      "name": "Usuario Test"
    }
  }
}
```

**✅ Validar:**
- Status HTTP: 201 Created
- `status` = "pending"
- `transfer_number` generado automáticamente
- `requested_by` = tu usuario

---

### **Test 2: Listar Requisiciones Pendientes (Matriz)**

**Endpoint:** `GET /api/auth/admin/inventory-transfers-pending-requests`

**cURL:**
```bash
curl -X GET "http://localhost/api/auth/admin/inventory-transfers-pending-requests?company_id=1" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Resultado Esperado:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "from_location": {"name": "Matriz", "is_main": true},
      "to_location": {"name": "Sucursal Norte", "is_main": false},
      "requested_by_user": {"name": "Usuario Test"}
    }
  ],
  "meta": {
    "total": 1,
    "message": "Requisiciones pendientes de aprobación"
  }
}
```

**✅ Validar:**
- Aparece la requisición creada en Test 1
- Total = 1

---

### **Test 3: Aprobar Requisición (Matriz)**

**Endpoint:** `POST /api/auth/admin/inventory-transfers/{id}/approve`

**cURL:**
```bash
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/approve \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Resultado Esperado:**
```json
{
  "message": "Transferencia aprobada exitosamente",
  "data": {
    "id": 1,
    "status": "approved",
    "status_label": "Aprobada",
    "approved_by_user": {
      "id": 1,
      "name": "Usuario Test"
    },
    "approved_at": "2023-11-12T15:30:00.000000Z"
  }
}
```

**✅ Validar:**
- Status HTTP: 200 OK
- `status` cambió de "pending" a "approved"
- `approved_by` = tu usuario
- `approved_at` tiene fecha/hora

---

### **Test 4: Verificar Stock ANTES de Enviar**

**Ejecutar en tinker:**
```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```

```php
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 1) // Matriz
  ->value('quantity');
// Debe mostrar: 500 (o el stock inicial que tengas)

exit
```

---

### **Test 5: Enviar Transferencia (Matriz)**

**Endpoint:** `POST /api/auth/admin/inventory-transfers/{id}/ship`

**cURL:**
```bash
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/ship \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Resultado Esperado:**
```json
{
  "message": "Transferencia marcada como enviada. Stock decrementado en origen.",
  "data": {
    "id": 1,
    "status": "in_transit",
    "status_label": "En Tránsito",
    "shipped_by_user": {
      "id": 1,
      "name": "Usuario Test"
    },
    "shipped_at": "2023-11-12T16:00:00.000000Z",
    "details": [
      {
        "quantity_requested": 100,
        "quantity_shipped": 100,
        "quantity_received": null
      }
    ]
  }
}
```

**✅ Validar:**
- Status HTTP: 200 OK
- `status` cambió a "in_transit"
- `quantity_shipped` = `quantity_requested` (100)

---

### **Test 6: Verificar Stock DESPUÉS de Enviar**

**Ejecutar en tinker:**
```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```

```php
// Stock en MATRIZ (debe haber decrementado)
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 1)
  ->value('quantity');
// Debe mostrar: 400 (500 - 100)

// Stock en SUCURSAL (aún debe ser 0 o null)
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 2)
  ->value('quantity');
// Debe mostrar: null o 0

exit
```

**✅ Validar:**
- Stock matriz: 500 → 400 (-100) ✅
- Stock sucursal: aún en 0 o null ✅

---

### **Test 7: Listar Transferencias en Tránsito (Sucursal)**

**Endpoint:** `GET /api/auth/admin/inventory-transfers-in-transit`

**cURL:**
```bash
curl -X GET "http://localhost/api/auth/admin/inventory-transfers-in-transit?to_location_id=2" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Resultado Esperado:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "in_transit",
      "from_location": {"name": "Matriz"},
      "to_location": {"name": "Sucursal Norte"},
      "shipped_at": "2023-11-12T16:00:00.000000Z"
    }
  ],
  "meta": {
    "total": 1,
    "message": "Transferencias en tránsito para recibir"
  }
}
```

**✅ Validar:**
- Aparece la transferencia enviada
- Total = 1

---

### **Test 8: Recibir Transferencia COMPLETA (Sucursal)**

**Endpoint:** `POST /api/auth/admin/inventory-transfers/{id}/receive`

**cURL:**
```bash
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/receive \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "received_quantities": {
      "1": 100
    }
  }'
```

**Nota:** El "1" en `received_quantities` es el **ID del detalle** (inventory_transfer_details.id). Puedes obtenerlo del Test 5 o consultando la transferencia.

**Resultado Esperado:**
```json
{
  "message": "Transferencia recibida exitosamente. Stock actualizado en destino.",
  "data": {
    "id": 1,
    "status": "completed",
    "status_label": "Completada",
    "received_by_user": {
      "id": 1,
      "name": "Usuario Test"
    },
    "received_at": "2023-11-12T17:00:00.000000Z",
    "has_differences": false,
    "total_differences": 0,
    "details": [
      {
        "quantity_shipped": 100,
        "quantity_received": 100,
        "difference": 0
      }
    ]
  }
}
```

**✅ Validar:**
- Status HTTP: 200 OK
- `status` = "completed"
- `quantity_received` = 100
- `difference` = 0
- `has_differences` = false

---

### **Test 9: Verificar Stock FINAL**

**Ejecutar en tinker:**
```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```

```php
// Stock en MATRIZ (debe seguir en 400)
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 1)
  ->value('quantity');
// Debe mostrar: 400

// Stock en SUCURSAL (debe haber incrementado a 100)
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 2)
  ->value('quantity');
// Debe mostrar: 100

exit
```

**✅ Validar:**
- Stock matriz: 400 (sin cambios) ✅
- Stock sucursal: 0 → 100 (+100) ✅

---

## 🧪 Test Adicional: Recepción PARCIAL

### **Test 10: Crear Segunda Transferencia para Probar Diferencias**

Repetir Tests 1-5 para crear y enviar otra transferencia.

### **Test 11: Recibir MENOS de lo Enviado**

**cURL:**
```bash
curl -X POST http://localhost/api/auth/admin/inventory-transfers/2/receive \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "received_quantities": {
      "2": 95
    }
  }'
```

**Nota:** Si se enviaron 100, pero solo recibes 95.

**Resultado Esperado:**
```json
{
  "data": {
    "status": "completed",
    "has_differences": true,
    "total_differences": 5,
    "details": [
      {
        "quantity_shipped": 100,
        "quantity_received": 95,
        "difference": 5,
        "has_difference": true
      }
    ]
  }
}
```

**✅ Validar:**
- `difference` = 5 (100 - 95)
- `has_differences` = true
- Stock en sucursal incrementó solo 95 (no 100)

---

## 🧪 Test de Cancelación

### **Test 12: Cancelar Transferencia en Tránsito**

**Crear transferencia, aprobar y enviar (Tests 1-5)**

**Verificar stock antes:**
```php
// En tinker
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 1)
  ->value('quantity');
// Ej: 300 (después de 2 envíos)
```

**Cancelar:**
```bash
curl -X DELETE http://localhost/api/auth/admin/inventory-transfers/3 \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**Resultado Esperado:**
```json
{
  "message": "Transferencia cancelada exitosamente"
}
```

**Verificar stock después:**
```php
// En tinker
DB::table('product_location')
  ->where('product_id', 1)
  ->where('location_id', 1)
  ->value('quantity');
// Debe mostrar: 400 (se revirtieron las 100 unidades)
```

**✅ Validar:**
- Stock se revirtió correctamente
- Status de la transferencia = "cancelled"

---

## 📊 Checklist Final

Después de ejecutar todos los tests:

- [ ] ✅ Crear requisición (status: pending)
- [ ] ✅ Aprobar requisición (status: approved)
- [ ] ✅ Enviar transferencia (status: in_transit)
- [ ] ✅ Stock decrementó en origen al enviar
- [ ] ✅ Recibir transferencia completa (status: completed)
- [ ] ✅ Stock incrementó en destino al recibir
- [ ] ✅ Recepción parcial calcula diferencias correctamente
- [ ] ✅ Cancelación revierte stock si está en tránsito
- [ ] ✅ Endpoints de consulta rápida funcionan
- [ ] ✅ Auditoría registra usuarios correctamente

---

## 🐛 Errores Comunes

### Error: "Unauthenticated"
**Solución:** Generar token de prueba:
```bash
docker exec -it plastigest-back-v2-laravel.test-1 php artisan tinker
```
```php
$user = \App\Models\User::first();
$token = $user->createToken('test-token')->plainTextToken;
echo $token;
exit
```

### Error: "Stock insuficiente"
**Solución:** Agregar stock en tinker:
```php
DB::table('product_location')->updateOrInsert(
    ['product_id' => 1, 'location_id' => 1],
    ['quantity' => 1000, 'updated_at' => now()]
);
```

### Error: "Location not found"
**Solución:** Verificar IDs de ubicaciones:
```php
\App\Models\Location::all(['id', 'name']);
```

---

## ✅ Resultado Esperado

Si todos los tests pasan:
- ✅ Backend funciona al 100%
- ✅ Puedes proceder con confianza al frontend
- ✅ El flujo de requisición está validado

**¡Buena suerte con las pruebas!** 🚀
