# API de Notificaciones v2 — Documentación para Frontend

> Base URL autenticada: `/api/auth/admin`
> Todos los endpoints requieren header `Authorization: Bearer {token}` y `X-Company-ID` (o el mecanismo estándar del proyecto).

---

## Notificaciones In-App

Las notificaciones in-app son las que aparecen en el listado dentro de la app (campana/bandeja). El backend solo devuelve registros del canal `db`; los canales `email` y `push` son internos.

---

### `GET /notifications`

Lista las notificaciones del usuario autenticado, filtradas por la empresa activa. Solo devuelve canal `db`.

**Query params opcionales:**

| Param | Tipo | Descripción | Ejemplo |
|---|---|---|---|
| `event_type` | string | Filtrar por tipo de evento | `low_stock` |
| `read` | boolean string | `true` = solo leídas, `false` = solo no leídas | `false` |
| `page` | int | Paginación | `1` |
| `per_page` | int | Registros por página | `15` |

**Valores posibles de `event_type`:**

| Valor | Descripción |
|---|---|
| `low_stock` | Producto con stock por debajo del mínimo |
| `inventory_adjustment` | Ajuste manual de inventario |
| `inventory_count_discrepancy` | Discrepancias en conteo de inventario |
| `purchase_update` | Compra en tránsito o recibida |
| `task_event` | Tarea asignada, completada, comentada o vencida |

**Response:**
```json
{
  "data": [
    {
      "id": 42,
      "user_id": 7,
      "company_id": 2,
      "event_type": "low_stock",
      "title": "⚠️ Stock Bajo: Bolsa 20x30",
      "message": "El producto 'Bolsa 20x30' en 'Almacén Central' tiene 3 unidades, por debajo del mínimo de 10.",
      "severity": "warning",
      "is_read": false,
      "read_at": null,
      "data": {
        "event_type": "low_stock",
        "product_id": 15,
        "location_id": 3,
        "current_stock": 3,
        "minimum_stock": 10
      },
      "created_at": "2026-05-06T14:30:00.000Z",
      "updated_at": "2026-05-06T14:30:00.000Z"
    }
  ],
  "links": { "...": "paginación estándar" },
  "meta": { "total": 12, "per_page": 15, "current_page": 1 }
}
```

**Valores de `severity`:** `info` | `success` | `warning` | `error` | `alert`

---

### `GET /notifications/unread-count`

Devuelve el conteo de notificaciones no leídas del usuario autenticado.

**Response:**
```json
{
  "success": true,
  "data": {
    "count": 5
  }
}
```

---

### `GET /notifications/{id}`

Detalle de una notificación.

**Response:** objeto `NotificationResource` igual al item del listado.

---

### `POST /notifications/{id}/mark-as-read`

Marca una notificación como leída. Solo funciona si la notificación pertenece al usuario autenticado.

**Body:** vacío.

**Response:**
```json
{
  "success": true,
  "message": "Notificación marcada como leída",
  "data": { "...": "NotificationResource actualizado" }
}
```

---

### `POST /notifications/{id}/mark-as-unread`

Marca una notificación como no leída.

**Body:** vacío.

**Response:**
```json
{
  "success": true,
  "message": "Notificación marcada como no leída",
  "data": { "...": "NotificationResource actualizado" }
}
```

---

### `POST /notifications/mark-all-as-read`

Marca todas las notificaciones no leídas del usuario autenticado (en la empresa activa) como leídas.

**Body:** vacío.

**Response:**
```json
{
  "success": true,
  "message": "Se marcaron 5 notificaciones como leídas",
  "data": {
    "count": 5
  }
}
```

---

### `DELETE /notifications/{id}`

Elimina una notificación. Solo el propietario puede eliminarla.

**Response:** `204 No Content`

---

## Preferencias de Notificaciones

Configuración por empresa: qué eventos están activos y por cuáles canales se envían. Solo deben ser modificadas por administradores.

---

### `GET /notification-preferences`

Lista las preferencias actuales de la empresa. Si la empresa no ha personalizado un evento, devuelve los valores por defecto del sistema.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "event_type": "low_stock",
      "permission_name": "inventory_manage",
      "channel_db": true,
      "channel_email": true,
      "channel_push": true,
      "is_active": true,
      "is_customized": false
    },
    {
      "event_type": "inventory_adjustment",
      "permission_name": "inventory_manage",
      "channel_db": true,
      "channel_email": false,
      "channel_push": true,
      "is_active": true,
      "is_customized": true
    },
    {
      "event_type": "inventory_count_discrepancy",
      "permission_name": "inventory_manage",
      "channel_db": true,
      "channel_email": true,
      "channel_push": true,
      "is_active": true,
      "is_customized": false
    },
    {
      "event_type": "purchase_update",
      "permission_name": "purchases_manage",
      "channel_db": true,
      "channel_email": true,
      "channel_push": true,
      "is_active": true,
      "is_customized": false
    },
    {
      "event_type": "task_event",
      "permission_name": "",
      "channel_db": true,
      "channel_email": true,
      "channel_push": true,
      "is_active": true,
      "is_customized": false
    }
  ]
}
```

**Campo `is_customized`:** `false` = usando defaults del sistema, `true` = la empresa tiene un registro propio en BD.

**Significado de `permission_name`:** Los usuarios con este permiso en la empresa reciben la notificación. `task_event` siempre va dirigido a un usuario específico (no por permiso), por eso `permission_name` es vacío.

---

### `PATCH /notification-preferences/{eventType}`

Actualiza la configuración de canales para un tipo de evento específico. Crea el registro si no existía.

**Path param `{eventType}`:** uno de `low_stock` | `inventory_adjustment` | `inventory_count_discrepancy` | `purchase_update` | `task_event`

**Body (todos los campos son opcionales):**
```json
{
  "channel_db": true,
  "channel_email": false,
  "channel_push": true,
  "is_active": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Preferencia actualizada",
  "data": {
    "id": 3,
    "company_id": 2,
    "event_type": "low_stock",
    "permission_name": "inventory_manage",
    "channel_db": true,
    "channel_email": false,
    "channel_push": true,
    "is_active": true,
    "created_at": "2026-05-06T14:00:00.000Z",
    "updated_at": "2026-05-06T15:00:00.000Z"
  }
}
```

**Error — event_type inválido (`422`):**
```json
{
  "success": false,
  "message": "Tipo de evento inválido"
}
```

---

### `POST /notification-preferences/reset`

Elimina todas las preferencias personalizadas de la empresa. Todos los eventos vuelven a los valores por defecto del sistema.

**Body:** vacío.

**Response:**
```json
{
  "success": true,
  "message": "Preferencias restablecidas a los valores predeterminados"
}
```

---

## Comportamiento del campo `data`

El campo `data` de cada notificación contiene el payload específico del evento. Se puede usar para navegar al recurso relacionado desde la app:

| `event_type` | Claves en `data` |
|---|---|
| `low_stock` | `product_id`, `location_id`, `current_stock`, `minimum_stock` |
| `inventory_adjustment` | `product_id`, `location_id`, `adjustment_qty`, `new_stock` |
| `inventory_count_discrepancy` | `inventory_count_id`, `location_id`, `discrepancies_count` |
| `purchase_update` | `purchase_id`, `sub_type` (`in_transit` \| `received`) |
| `task_event` | `task_id`, `sub_type` (`assigned` \| `completed` \| `overdue` \| `comment`), `actor_name` |

---

## Notas de integración

- **Polling vs Push:** El frontend puede combinar `GET /notifications/unread-count` con polling corto (30 s) para actualizar el badge, y cargar el listado completo solo cuando el usuario abre la bandeja.
- **Paginación:** Usar `page` y `per_page` para scroll infinito en la bandeja.
- **Filtro de no leídas:** `GET /notifications?read=false` para la vista del badge/bandeja inicial.
- **Deep linking:** Usar el campo `data` para construir el link de navegación al recurso (producto, compra, tarea) al que hace referencia la notificación.
