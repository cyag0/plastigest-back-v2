# Configuración de WhatsApp Webhook

## Webhook URL
Para configurar el webhook en Meta Developer Console:

**URL del Webhook:**
```
https://tu-dominio.com/api/webhooks/whatsapp
```

**Verify Token:**
```
plastigest_webhook_token_2024
```

## Pasos para configurar en Meta Developer Console

1. Ve a https://developers.facebook.com/apps
2. Selecciona tu app "plastigest"
3. En el menú lateral, ve a **WhatsApp** → **Configuración**
4. En la sección "Webhooks", haz clic en **Configurar webhooks**
5. Ingresa:
   - **URL de devolución de llamada**: `https://tu-dominio.com/api/webhooks/whatsapp`
   - **Verificar token**: `plastigest_webhook_token_2024`
6. Haz clic en **Verificar y guardar**
7. Suscríbete a los siguientes campos:
   - ✅ **messages** (para recibir mensajes)
   - ✅ **message_status** (opcional, para estados de entrega)

## Testing Local con ngrok

Si estás en desarrollo local, usa ngrok:

```bash
ngrok http 80
```

Copia la URL de ngrok (ejemplo: `https://abc123.ngrok.io`) y úsala como:
```
https://abc123.ngrok.io/api/webhooks/whatsapp
```

## Funcionamiento

### Envío automático (al iniciar pedido)
Cuando cambias una compra de "draft" a "ordered", se envía automáticamente un mensaje de WhatsApp al proveedor con:
- Número de pedido
- Lista de productos
- Cantidades y precios
- Total

### Recepción automática (respuestas del proveedor)

El webhook detecta automáticamente palabras clave:

**Para marcar como "en tránsito"** (cuando el proveedor envía el pedido):
- "enviado"
- "en camino"
- "despachado"
- "tránsito" / "transito"

**Para registrar confirmación de entrega** (cuando el proveedor confirma):
- "recibido"
- "entregado"
- "llegó" / "llego"
- "completado"

### Logs

Todos los eventos se registran en los logs de Laravel:

```bash
# Ver logs en tiempo real
sail artisan tail

# O ver el archivo de logs
tail -f storage/logs/laravel.log
```

## Números de prueba

En modo TEST de WhatsApp Cloud API, solo puedes enviar mensajes a números que agregues manualmente en la configuración de Meta.

Para agregar un número de prueba:
1. Ve a WhatsApp → **API Setup**
2. En "To:", haz clic en **Manage phone number list**
3. Agrega los números a los que quieres enviar mensajes de prueba

## Seguridad

- El webhook verifica el token antes de procesar mensajes
- Siempre retorna 200 para evitar reintentos de Meta
- Todos los errores se registran en logs sin exponer información sensible

## Notas importantes

⚠️ **El webhook NO actualiza el stock automáticamente**
Cuando detecta palabras como "recibido", solo registra la confirmación en las notas de la compra. Aún debes hacer clic en "Confirmar Recepción" en la app para actualizar el inventario.

Esto es por seguridad, para evitar actualizaciones accidentales de stock.
