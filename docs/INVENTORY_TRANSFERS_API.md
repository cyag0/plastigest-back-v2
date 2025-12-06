# API de Transferencias de Inventario

## Descripción General
Sistema completo para gestionar transferencias de inventario entre sucursales/ubicaciones de la empresa. Permite crear, aprobar, enviar y recibir transferencias con seguimiento de cantidades y diferencias.

## Flujo de Trabajo
1. **PENDING** → Transferencia creada, esperando aprobación
2. **APPROVED** → Aprobada, lista para envío
3. **IN_TRANSIT** → Enviada, decrementó stock del origen
4. **COMPLETED** → Recibida, incrementó stock en destino
5. **CANCELLED** → Cancelada (revierte stock si es necesario)

---

## Endpoints

### 1. Listar Transferencias
**GET** `/api/auth/admin/inventory-transfers`

**Query Parameters:**
- `company_id` (optional): Filtrar por empresa
- `status` (optional): Filtrar por estado (pending, approved, in_transit, completed, cancelled)
- `from_location_id` (optional): Filtrar por ubicación de origen
- `to_location_id` (optional): Filtrar por ubicación de destino
- `start_date` (optional): Fecha inicial del rango
- `end_date` (optional): Fecha final del rango
- `per_page` (optional): Número de resultados por página para paginación

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "company_id": 1,
      "transfer_number": "TRANS-20231112-0001",
      "from_location": {
        "id": 1,
        "name": "Matriz",
        "is_main": true
      },
      "to_location": {
        "id": 2,
        "name": "Sucursal Norte",
        "is_main": false
      },
      "status": "in_transit",
      "status_label": "En Tránsito",
      "status_color": "#f59e0b",
      "requested_by_user": {
        "id": 1,
        "name": "Juan Pérez"
      },
      "approved_by_user": {
        "id": 2,
        "name": "María García"
      },
      "shipped_by_user": {
        "id": 1,
        "name": "Juan Pérez"
      },
      "received_by_user": null,
      "requested_at": "2023-11-12T10:00:00Z",
      "approved_at": "2023-11-12T11:00:00Z",
      "shipped_at": "2023-11-12T14:00:00Z",
      "received_at": null,
      "cancelled_at": null,
      "total_cost": 15000.50,
      "notes": "Transferencia mensual de productos",
      "rejection_reason": null,
      "total_differences": 0,
      "has_differences": false,
      "details": [
        {
          "id": 1,
          "transfer_id": 1,
          "product_id": 5,
          "product": {
            "id": 5,
            "name": "Producto A",
            "sku": "PROD-A-001",
            "code": "PA001"
          },
          "quantity_requested": 100,
          "quantity_shipped": 100,
          "quantity_received": null,
          "difference": 0,
          "has_difference": false,
          "unit_cost": 150.00,
          "total_cost": 15000.00,
          "batch_number": "LOTE-2023-11",
          "expiry_date": "2024-11-12",
          "notes": null,
          "damage_report": null
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 67
  }
}
```

---

### 2. Crear Transferencia
**POST** `/api/auth/admin/inventory-transfers`

**Body:**
```json
{
  "company_id": 1,
  "from_location_id": 1,
  "to_location_id": 2,
  "notes": "Transferencia mensual de productos",
  "details": [
    {
      "product_id": 5,
      "quantity_requested": 100,
      "unit_cost": 150.00,
      "batch_number": "LOTE-2023-11",
      "expiry_date": "2024-11-12",
      "notes": "Producto en buen estado"
    },
    {
      "product_id": 8,
      "quantity_requested": 50,
      "unit_cost": 80.50
    }
  ]
}
```

**Validaciones:**
- `company_id`: requerido, debe existir
- `from_location_id`: requerido, debe existir
- `to_location_id`: requerido, debe existir, debe ser diferente de `from_location_id`
- `notes`: opcional, string
- `details`: requerido, array con mínimo 1 elemento
- `details.*.product_id`: requerido, debe existir
- `details.*.quantity_requested`: requerido, numérico, mínimo 0.001
- `details.*.unit_cost`: opcional, numérico, mínimo 0
- `details.*.batch_number`: opcional, string, máximo 50 caracteres
- `details.*.expiry_date`: opcional, fecha
- `details.*.notes`: opcional, string

**Response:** 201 Created
```json
{
  "message": "Transferencia creada exitosamente",
  "data": { /* Transfer object */ }
}
```

---

### 3. Ver Transferencia
**GET** `/api/auth/admin/inventory-transfers/{id}`

**Response:**
```json
{
  "data": { /* Transfer object con todos los detalles */ }
}
```

---

### 4. Actualizar Transferencia
**PUT/PATCH** `/api/auth/admin/inventory-transfers/{id}`

**Restricción:** Solo se pueden editar transferencias con estado `PENDING`

**Body:** (todos los campos opcionales excepto details si se envía)
```json
{
  "from_location_id": 1,
  "to_location_id": 3,
  "notes": "Actualización de notas",
  "details": [
    {
      "product_id": 5,
      "quantity_requested": 150,
      "unit_cost": 155.00
    }
  ]
}
```

**Response:**
```json
{
  "message": "Transferencia actualizada exitosamente",
  "data": { /* Transfer object */ }
}
```

**Error si no es PENDING:**
```json
{
  "message": "Solo se pueden editar transferencias pendientes"
}
```

---

### 5. Cancelar Transferencia
**DELETE** `/api/auth/admin/inventory-transfers/{id}`

**Comportamiento:**
- Si está en estado `IN_TRANSIT`, revierte el stock en la ubicación de origen
- Cambia el estado a `CANCELLED`
- Establece `cancelled_at` y `rejection_reason`

**Response:**
```json
{
  "message": "Transferencia cancelada exitosamente"
}
```

**Error si no se puede cancelar:**
```json
{
  "message": "Esta transferencia no puede ser cancelada"
}
```

---

### 6. Aprobar Transferencia
**POST** `/api/auth/admin/inventory-transfers/{id}/approve`

**Requisito:** Debe estar en estado `PENDING`

**Response:**
```json
{
  "message": "Transferencia aprobada exitosamente",
  "data": { /* Transfer object con status = APPROVED */ }
}
```

---

### 7. Enviar Transferencia (Marcar en Tránsito)
**POST** `/api/auth/admin/inventory-transfers/{id}/ship`

**Requisito:** Debe estar en estado `APPROVED`

**Comportamiento:**
- Decrementa el stock en la ubicación de origen (`from_location_id`)
- Valida que haya suficiente stock disponible
- Establece `quantity_shipped` = `quantity_requested` en cada detalle
- Cambia estado a `IN_TRANSIT`
- Registra `shipped_by` y `shipped_at`

**Response:**
```json
{
  "message": "Transferencia marcada como enviada. Stock decrementado en origen.",
  "data": { /* Transfer object con status = IN_TRANSIT */ }
}
```

**Error si no hay stock suficiente:**
```json
{
  "message": "Error al enviar la transferencia",
  "error": "Stock insuficiente para el producto X en la ubicación de origen"
}
```

---

### 8. Recibir Transferencia
**POST** `/api/auth/admin/inventory-transfers/{id}/receive`

**Requisito:** Debe estar en estado `IN_TRANSIT`

**Body:**
```json
{
  "received_quantities": {
    "1": 100,  // detail_id: cantidad_recibida
    "2": 48    // Si se reciben menos, se registra la diferencia
  }
}
```

**Comportamiento:**
- Incrementa el stock en la ubicación de destino (`to_location_id`)
- Crea el registro en `product_location` si no existe
- Establece `quantity_received` en cada detalle
- Calcula automáticamente la diferencia (`quantity_shipped - quantity_received`)
- Cambia estado a `COMPLETED`
- Registra `received_by` y `received_at`
- Permite recibir cantidades parciales

**Response:**
```json
{
  "message": "Transferencia recibida exitosamente. Stock actualizado en destino.",
  "data": {
    /* Transfer object con status = COMPLETED */
    "has_differences": true,  // Si hubo diferencias
    "total_differences": 2,
    "details": [
      {
        "id": 2,
        "quantity_shipped": 50,
        "quantity_received": 48,
        "difference": 2,
        "has_difference": true
      }
    ]
  }
}
```

---

## Modelo de Estados (TransferStatus Enum)

| Estado | Valor | Label | Color | Puede Editar | Puede Cancelar |
|--------|-------|-------|-------|--------------|----------------|
| PENDING | pending | Pendiente | #6b7280 | ✅ | ✅ |
| APPROVED | approved | Aprobada | #3b82f6 | ❌ | ✅ |
| IN_TRANSIT | in_transit | En Tránsito | #f59e0b | ❌ | ✅ |
| COMPLETED | completed | Completada | #10b981 | ❌ | ❌ |
| CANCELLED | cancelled | Cancelada | #ef4444 | ❌ | ❌ |

### Transiciones Permitidas
- PENDING → APPROVED, CANCELLED
- APPROVED → IN_TRANSIT, CANCELLED
- IN_TRANSIT → COMPLETED, CANCELLED
- COMPLETED → (ninguna, estado final)
- CANCELLED → (ninguna, estado final)

---

## Validaciones de Negocio

### Al Crear
- Las ubicaciones de origen y destino deben ser diferentes
- Debe haber al menos 1 producto en los detalles
- Las cantidades solicitadas deben ser mayores a 0

### Al Aprobar
- Solo puede aprobar si está en estado PENDING
- Registra el usuario que aprobó

### Al Enviar
- Solo puede enviar si está en estado APPROVED
- Verifica que haya stock suficiente en la ubicación de origen
- Decrementa automáticamente el stock
- Si no hay suficiente stock, lanza error y no permite continuar

### Al Recibir
- Solo puede recibir si está en estado IN_TRANSIT
- Puede recibir cantidades menores a las enviadas (registra diferencia)
- Incrementa automáticamente el stock en destino
- Crea el registro product_location si es la primera vez que llega ese producto a esa ubicación

### Al Cancelar
- No se puede cancelar si está COMPLETED
- Si está IN_TRANSIT, revierte el stock en origen (vuelve a sumar las cantidades)
- Registra el motivo de cancelación

---

## Ejemplos de Uso

### Flujo Completo Exitoso

1. **Crear transferencia**
```bash
POST /api/auth/admin/inventory-transfers
{
  "company_id": 1,
  "from_location_id": 1,
  "to_location_id": 2,
  "details": [
    {"product_id": 5, "quantity_requested": 100, "unit_cost": 150}
  ]
}
```

2. **Aprobar**
```bash
POST /api/auth/admin/inventory-transfers/1/approve
```

3. **Enviar**
```bash
POST /api/auth/admin/inventory-transfers/1/ship
# Stock en origen: 500 → 400 (-100)
```

4. **Recibir**
```bash
POST /api/auth/admin/inventory-transfers/1/receive
{
  "received_quantities": {
    "1": 100
  }
}
# Stock en destino: 0 → 100 (+100)
```

### Flujo con Recepción Parcial

```bash
POST /api/auth/admin/inventory-transfers/1/receive
{
  "received_quantities": {
    "1": 95  # Solo se recibieron 95 de 100
  }
}
# Diferencia: 5 unidades
# Se registra en damage_report o se puede agregar nota
```

### Flujo de Cancelación

```bash
DELETE /api/auth/admin/inventory-transfers/1
# Si estaba IN_TRANSIT con 100 unidades enviadas:
# Stock en origen: 400 → 500 (+100, se revierten)
```

---

## Notas Importantes

1. **Autenticación**: Todos los endpoints requieren autenticación con Sanctum (`auth:sanctum` middleware)
2. **Multi-tenant**: Las transferencias están aisladas por `company_id`
3. **Auditoría**: Se registra qué usuario realizó cada acción (requested_by, approved_by, shipped_by, received_by)
4. **Stock en tiempo real**: El stock se actualiza automáticamente al enviar y recibir
5. **Manejo de diferencias**: El sistema permite recibir cantidades menores y las registra como diferencias
6. **Creación automática**: Si un producto no existe en la ubicación de destino, se crea automáticamente al recibir
7. **Números de transferencia**: Se generan automáticamente con formato `TRANS-YYYYMMDD-####`

---

## Estructura de Base de Datos

### Tabla: `inventory_transfers`
- `id`, `company_id`, `transfer_number`
- `from_location_id`, `to_location_id`
- `status` (enum: pending, approved, in_transit, completed, cancelled)
- `requested_by`, `approved_by`, `shipped_by`, `received_by` (user_id)
- `requested_at`, `approved_at`, `shipped_at`, `received_at`, `cancelled_at`
- `total_cost`, `notes`, `rejection_reason`

### Tabla: `inventory_transfer_details`
- `id`, `transfer_id`, `product_id`
- `quantity_requested`, `quantity_shipped`, `quantity_received`
- `unit_cost`, `total_cost`
- `batch_number`, `expiry_date`
- `notes`, `damage_report`

### Relaciones Importantes
- `inventory_transfers` → `locations` (from_location, to_location)
- `inventory_transfers` → `users` (requested_by, approved_by, shipped_by, received_by)
- `inventory_transfer_details` → `products`
- `product_location` → Actualizado automáticamente al enviar/recibir
