# Flujo de Requisición de Productos entre Sucursales

## 📋 Descripción General

El sistema permite que las **sucursales soliciten productos** a la **matriz** (o entre ubicaciones). La sucursal crea una **requisición**, la matriz la aprueba, prepara y envía los productos, y finalmente la sucursal confirma la recepción.

---

## 🔄 Flujo Completo de Requisición

### 1. **Sucursal Crea Requisición** (PENDING)
**Quién:** Usuario de la sucursal receptora  
**Acción:** Crea la transferencia especificando qué productos necesita

```http
POST /api/auth/admin/inventory-transfers
Authorization: Bearer {token_usuario_sucursal}

{
  "company_id": 1,
  "from_location_id": 1,        // ID de la matriz (is_main: true)
  "to_location_id": 2,           // ID de la sucursal que solicita
  "notes": "Requisición mensual - necesitamos resurtir inventario",
  "details": [
    {
      "product_id": 5,
      "quantity_requested": 100,  // Cantidad que necesita la sucursal
      "notes": "Urgente - stock bajo"
    },
    {
      "product_id": 8,
      "quantity_requested": 50
    }
  ]
}
```

**Resultado:**
- Estado: `PENDING`
- `requested_by`: ID del usuario de la sucursal
- `requested_at`: Fecha/hora actual
- Se genera automáticamente `transfer_number`: TRANS-20231112-0001

---

### 2. **Matriz Revisa y Aprueba** (PENDING → APPROVED)
**Quién:** Usuario de la matriz con permisos  
**Acción:** Revisa la requisición y la aprueba

```http
POST /api/auth/admin/inventory-transfers/1/approve
Authorization: Bearer {token_usuario_matriz}
```

**Resultado:**
- Estado: `PENDING` → `APPROVED`
- `approved_by`: ID del usuario de la matriz
- `approved_at`: Fecha/hora actual

**Validaciones:**
- Solo puede aprobar si está en estado PENDING
- El usuario debe tener permisos para aprobar transferencias

---

### 3. **Matriz Prepara y Envía** (APPROVED → IN_TRANSIT)
**Quién:** Usuario de la matriz (almacén/bodega)  
**Acción:** Prepara el pedido y lo envía

```http
POST /api/auth/admin/inventory-transfers/1/ship
Authorization: Bearer {token_usuario_matriz}
```

**Resultado:**
- Estado: `APPROVED` → `IN_TRANSIT`
- `shipped_by`: ID del usuario de la matriz
- `shipped_at`: Fecha/hora actual
- `quantity_shipped` = `quantity_requested` en cada detalle
- **Stock decrementado** en la ubicación de origen (matriz)

**Validaciones:**
- Solo puede enviar si está en estado APPROVED
- Verifica que haya stock suficiente en la matriz
- Si no hay stock, lanza error y no permite enviar

**Ejemplo de error por falta de stock:**
```json
{
  "message": "Error al enviar la transferencia",
  "error": "Stock insuficiente para el producto 'Producto A' en la ubicación 'Matriz'. Disponible: 50, Requerido: 100"
}
```

---

### 4. **Sucursal Confirma Recepción** (IN_TRANSIT → COMPLETED)
**Quién:** Usuario de la sucursal receptora  
**Acción:** Confirma que recibió los productos y reporta cantidades

```http
POST /api/auth/admin/inventory-transfers/1/receive
Authorization: Bearer {token_usuario_sucursal}

{
  "received_quantities": {
    "1": 100,  // detail_id: cantidad recibida (completa)
    "2": 48    // detail_id: cantidad recibida (parcial - faltaron 2)
  }
}
```

**Resultado:**
- Estado: `IN_TRANSIT` → `COMPLETED`
- `received_by`: ID del usuario de la sucursal
- `received_at`: Fecha/hora actual
- `quantity_received` actualizado en cada detalle
- **Stock incrementado** en la ubicación de destino (sucursal)
- Se calculan automáticamente las diferencias si las hay

**Manejo de diferencias:**
```json
{
  "message": "Transferencia recibida exitosamente. Stock actualizado en destino.",
  "data": {
    "id": 1,
    "status": "completed",
    "has_differences": true,
    "total_differences": 2,
    "details": [
      {
        "id": 1,
        "product": { "name": "Producto A" },
        "quantity_requested": 100,
        "quantity_shipped": 100,
        "quantity_received": 100,
        "difference": 0,
        "has_difference": false
      },
      {
        "id": 2,
        "product": { "name": "Producto B" },
        "quantity_requested": 50,
        "quantity_shipped": 50,
        "quantity_received": 48,
        "difference": 2,
        "has_difference": true,
        "damage_report": "2 unidades dañadas en tránsito"
      }
    ]
  }
}
```

---

## 🎯 Casos de Uso Especiales

### Caso 1: Cancelar Requisición Antes de Envío
**Escenario:** La sucursal ya no necesita los productos o cometió un error

```http
DELETE /api/auth/admin/inventory-transfers/1
```

- Solo puede cancelar en estados: PENDING, APPROVED
- No afecta el stock (aún no se ha enviado)
- Estado cambia a CANCELLED

---

### Caso 2: Cancelar Transferencia en Tránsito
**Escenario:** Los productos se perdieron o deben regresar a la matriz

```http
DELETE /api/auth/admin/inventory-transfers/1
```

- Solo puede cancelar en estado: IN_TRANSIT
- **Revierte el stock** en la ubicación de origen (matriz)
- Estado cambia a CANCELLED
- Las unidades vuelven a la matriz automáticamente

**Ejemplo:**
- Stock matriz antes de enviar: 500
- Se envían 100 (stock matriz: 400)
- Se cancela la transferencia
- Stock matriz después de cancelar: 500 (se revierten los 100)

---

### Caso 3: Editar Requisición
**Escenario:** La sucursal necesita cambiar productos o cantidades

```http
PUT /api/auth/admin/inventory-transfers/1

{
  "notes": "Actualización - cambiamos cantidades",
  "details": [
    {
      "product_id": 5,
      "quantity_requested": 150  // Cambiado de 100 a 150
    }
  ]
}
```

- Solo puede editar en estado: PENDING
- Se eliminan los detalles anteriores y se crean nuevos
- Útil antes de que la matriz apruebe

---

## 📊 Consultas y Filtros

### Ver Requisiciones Pendientes de Aprobación (Vista Matriz)
```http
GET /api/auth/admin/inventory-transfers?status=pending&to_location_id=2
```

Retorna todas las requisiciones que la sucursal 2 ha solicitado y están esperando aprobación.

---

### Ver Transferencias en Tránsito para Recibir (Vista Sucursal)
```http
GET /api/auth/admin/inventory-transfers?status=in_transit&to_location_id=2
```

Retorna todas las transferencias que vienen hacia la sucursal 2 y están listas para recibir.

---

### Ver Histórico de Requisiciones de una Sucursal
```http
GET /api/auth/admin/inventory-transfers?to_location_id=2&start_date=2023-11-01&end_date=2023-11-30
```

Retorna todas las transferencias (completas, canceladas, etc.) de la sucursal 2 en noviembre.

---

### Ver Quién Solicitó la Transferencia
```http
GET /api/auth/admin/inventory-transfers/1
```

Respuesta incluye:
```json
{
  "data": {
    "id": 1,
    "requested_by_user": {
      "id": 5,
      "name": "María López",
      "email": "maria@sucursal-norte.com"
    },
    "from_location": {
      "id": 1,
      "name": "Matriz",
      "is_main": true
    },
    "to_location": {
      "id": 2,
      "name": "Sucursal Norte",
      "is_main": false
    }
  }
}
```

---

## 🔐 Permisos Recomendados

### Usuarios de Sucursal
- **Crear** requisiciones (POST /inventory-transfers)
- **Ver** sus propias requisiciones
- **Editar** requisiciones en estado PENDING
- **Recibir** transferencias (POST /{id}/receive)
- **Cancelar** requisiciones en PENDING

### Usuarios de Matriz
- **Ver** todas las requisiciones
- **Aprobar** requisiciones (POST /{id}/approve)
- **Enviar** transferencias (POST /{id}/ship)
- **Cancelar** transferencias en cualquier estado

### Administradores
- Todos los permisos anteriores

---

## 📈 Flujo Visual

```
┌─────────────┐                    ┌─────────────┐
│  SUCURSAL   │                    │   MATRIZ    │
│   NORTE     │                    │  (is_main)  │
└─────────────┘                    └─────────────┘
      │                                    │
      │  1. Crear Requisición (PENDING)   │
      ├───────────────────────────────────>│
      │     "Necesito 100 unidades"        │
      │                                    │
      │                                    │ 2. Aprobar (APPROVED)
      │                                    │    Revisar disponibilidad
      │                                    │
      │  3. Enviar (IN_TRANSIT)           │
      │<───────────────────────────────────┤
      │    Stock matriz: 500 → 400         │
      │                                    │
      │  4. Confirmar Recepción           │
      │     (COMPLETED)                    │
      ├───────────────────────────────────>│
      │    Stock sucursal: 0 → 100        │
      │                                    │
```

---

## 💡 Mejores Prácticas

1. **Crear requisiciones periódicas**: Las sucursales pueden programar requisiciones mensuales o semanales

2. **Revisar stock antes de aprobar**: La matriz debe verificar disponibilidad antes de aprobar

3. **Reportar diferencias siempre**: Si hay faltantes o daños, reportarlos en `damage_report` al recibir

4. **Comunicación**: Usar el campo `notes` para comunicar detalles importantes

5. **Auditoría**: El sistema registra automáticamente quién hizo cada acción y cuándo, útil para rastrear responsabilidades

6. **Cancelaciones responsables**: Si se cancela una transferencia en tránsito, coordinar la devolución física de los productos

---

## 🚨 Validaciones Importantes

| Acción | Requisito | Validación |
|--------|-----------|------------|
| Crear | - | `from_location_id` ≠ `to_location_id` |
| Aprobar | Estado = PENDING | Solo usuarios autorizados |
| Enviar | Estado = APPROVED | Stock suficiente en origen |
| Recibir | Estado = IN_TRANSIT | Cantidades válidas |
| Cancelar | Estado ≠ COMPLETED | Revierte stock si está IN_TRANSIT |
| Editar | Estado = PENDING | Solo antes de aprobar |

---

## 📝 Notas Técnicas

- **Auto-cálculo de diferencias**: Al recibir, el sistema calcula automáticamente `difference = quantity_shipped - quantity_received`
- **Creación de product_location**: Si el producto no existe en la sucursal, se crea automáticamente al recibir
- **Números únicos**: Cada transferencia tiene un número único: `TRANS-YYYYMMDD-####`
- **Multi-tenant**: Todo está aislado por `company_id`
- **Auditoría completa**: Se registran 4 usuarios diferentes: requested_by, approved_by, shipped_by, received_by
- **Timestamps granulares**: Cada estado tiene su fecha: requested_at, approved_at, shipped_at, received_at

---

## 🎯 Ejemplo Completo

```bash
# 1. Sucursal crea requisición
curl -X POST http://localhost/api/auth/admin/inventory-transfers \
  -H "Authorization: Bearer {token}" \
  -d '{
    "company_id": 1,
    "from_location_id": 1,
    "to_location_id": 2,
    "notes": "Requisición semanal",
    "details": [
      {"product_id": 5, "quantity_requested": 100}
    ]
  }'
# → Status: PENDING, requested_by: User 5

# 2. Matriz aprueba
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/approve \
  -H "Authorization: Bearer {token}"
# → Status: APPROVED, approved_by: User 2

# 3. Matriz envía
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/ship \
  -H "Authorization: Bearer {token}"
# → Status: IN_TRANSIT, shipped_by: User 2
# → Stock matriz: 500 → 400

# 4. Sucursal recibe
curl -X POST http://localhost/api/auth/admin/inventory-transfers/1/receive \
  -H "Authorization: Bearer {token}" \
  -d '{
    "received_quantities": {
      "1": 100
    }
  }'
# → Status: COMPLETED, received_by: User 5
# → Stock sucursal: 0 → 100
```

---

## ✅ Conclusión

El sistema **ya está completamente preparado** para manejar el flujo de requisición que mencionaste:

✅ Las sucursales pueden crear requisiciones  
✅ La matriz puede aprobar y enviar  
✅ Se registra quién solicitó (`requested_by`)  
✅ El stock se maneja automáticamente  
✅ Se pueden reportar diferencias al recibir  
✅ Todo está auditado con usuarios y fechas  

**No se necesitan cambios en el código**, solo usar el flujo correctamente desde el frontend.
