# Plan de despliegue — PlastiGest (Coolify + Hetzner + Cloudflare)

Runbook para sacar el beta a producción usando **Coolify** (PaaS self-hosted) sobre un VPS Hetzner. Coolify reemplaza el trabajo manual de Nginx, SSL, queue worker, cron y deploys: instalas Coolify **una vez** y todo lo demás se configura por panel/Docker.

- **Backend:** Laravel 12, Sanctum (solo token Bearer, sin cookies), cola y caché en `database`, scheduler (`tasks:generate-recurring` diario 06:00 + limpiezas nocturnas), PDFs con dompdf, Excel de los reportes con OpenSpout (necesita las extensiones `zip` y `xmlreader`, declaradas como `ext-*` en `composer.json` para que Nixpacks las instale), push con Firebase (kreait). Nixpacks elige PHP según `"php"` de `composer.json`: con `^8.4` instala **PHP 8.4.2** (la misma serie que CI y Sail; la 8.2 pierde soporte de seguridad el 31/12/2026). `config.platform.php` está fijo en `8.4.2` para que `composer update` no traiga paquetes que pidan un PHP más nuevo que el de producción, y si la app corre en otra versión no arranca (chequeo de plataforma de Composer). OpenSpout sigue fijo en `~4.28.5` (ya se podría actualizar). El build lo ajusta `nixpacks.toml`: instala sin dependencias de desarrollo y falla si a la imagen le falta una extensión.
- **Frontend:** Expo SDK 56 / Expo Router, web con `output: "single"` (SPA: un solo `index.html`; `public/_redirects` hace el fallback de rutas) → Cloudflare Pages.
- **Objetivo:** 8 sucursales, ~30 usuarios. Carga pequeña: el VPS va sobrado.

Arquitectura objetivo:
- `api.cocos-francisco.com` → app de Laravel en **Coolify** (VPS Hetzner).
- `app.cocos-francisco.com` → Cloudflare Pages (build estático de Expo web).
- DNS en Cloudflare; SSL del backend **automático** vía Coolify (Let's Encrypt / Traefik).

> Diferencia clave con el deploy manual: aquí **no** tocas Nginx ni Certbot ni systemd. Coolify lo gestiona. Tú solo conectas el repo, pones las variables y das deploy.

---

## Fase 0 — Antes de tocar nada

- [x] ~~Comprar dominio y mover su DNS a Cloudflare~~ — `cocos-francisco.com` comprado en **Cloudflare Registrar**; el DNS ya está en Cloudflare (solo falta crear los registros, Fase 8).
- [ ] Crear cuentas: [Hetzner Cloud](https://www.hetzner.com/cloud) y [Cloudflare](https://dash.cloudflare.com) (gratis). ✅ Cloudflare ya la tienes (el dominio está ahí).
- [ ] **Decidir proveedor de correo** (Resend recomendado) — necesario para reset de contraseña, notificaciones y cierre de caja (Fase 7.5).
- [ ] Llave SSH lista (`~/.ssh/id_ed25519.pub`). ✅ (ya la tienes)
- [ ] Tener el **JSON de credenciales de Firebase** (service account) que ya usas en dev.
- [ ] **Commit + push del fix de doble-venta** y limpiar `console.log` de debug en `inventory/products/form.tsx`.
- [x] ~~Decidir qué seeders corren en producción~~ — resuelto en código: en producción `php artisan db:seed --force` solo corre los de referencia (recursos, permisos, unidades y el Super Admin con `ADMIN_PASSWORD`); nunca datos demo ni `CompaniesSeeder` (ver Fase 5).

---

## Fase 1 — Crear el servidor en Hetzner

1. [ ] Hetzner Cloud Console → *Add Server*:
   - Imagen: **Ubuntu 24.04**.
   - Tipo: **CPX31** (4 vCPU / 8 GB) recomendado para Coolify — los builds Docker comen RAM en picos. CPX21 (4 GB) también sirve, pero más justo.
   - Región: la de EE.UU. más cercana.
   - Añadir tu **llave SSH pública**. Crear.
2. [ ] Anotar la **IP pública**. Entrar como root: `ssh root@IP`.
3. [ ] Actualizar el sistema:
   ```bash
   apt update && apt upgrade -y
   ```
4. [ ] Firewall: Coolify abre/gestiona puertos por Docker, pero deja explícitos SSH, web y el panel:
   ```bash
   ufw allow OpenSSH
   ufw allow 80
   ufw allow 443
   ufw allow 8000   # panel de Coolify (puedes cerrarlo luego tras poner dominio al panel)
   ufw enable
   ```

> Coolify recomienda instalarse en un servidor **limpio**, como root. No montes el stack LEMP a mano: lo hace él.

---

## Fase 2 — Instalar Coolify

En el VPS, como root:
```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```
El instalador pone Docker, Traefik (proxy + SSL) y Coolify. Tarda unos minutos.

1. [ ] Abrir el panel: `http://IP:8000`.
2. [ ] Crear la **cuenta admin** (primer registro = dueño de la instancia). Hazlo rápido: queda expuesto hasta que registres.
3. [ ] El servidor donde corre Coolify aparece como **localhost**; ya está listo como destino de deploy.
4. [ ] (Recomendado) Settings → asignar un dominio al propio Coolify (ej. `coolify.cocos-francisco.com`) para entrar por HTTPS y poder cerrar el `8000`.

---

## Fase 3 — Base de datos (MySQL gestionado por Coolify)

1. [ ] En tu proyecto Coolify → *+ New* → *Database* → **MySQL**.
2. [ ] Anota credenciales que genera (host interno, user, password, db). El **host** será el nombre de servicio interno de Docker (ej. el que muestre Coolify), no `127.0.0.1`.
3. [ ] No expongas el puerto público de la DB salvo que lo necesites para una restauración puntual.

> Alternativa: instalar MySQL a mano en el host como en el deploy clásico. Pero dejar que Coolify lo gestione (backups incluidos, Fase 12) es más simple.

---

## Fase 4 — Crear la app del backend

1. [ ] *+ New* → *Application* → *Public/Private Repository* → conectar `plastigest-back-v2` (rama `main`).
2. [ ] **Build Pack: Nixpacks** (autodetecta Laravel/PHP/Composer). No hay Dockerfile propio en el repo, así que Nixpacks es la ruta directa.
3. [ ] **Dominio:** poner `https://api.cocos-francisco.com`. Coolify emite el SSL automático cuando el DNS resuelva (Fase 8).
4. [ ] **Puerto expuesto (Ports Exposes): `80`.** El nginx del contenedor Nixpacks escucha en `$PORT`, que Nixpacks fija en `80` (no 8080). Si cambias `PORT`, cambia también *Ports Exposes*.
5. [ ] **Health check:** ruta `/up` (la define `bootstrap/app.php`), puerto 80.
6. [x] ~~Límite de subida~~ — resuelto en el repo: `nginx.template.conf` (el nginx del contenedor) tiene `client_max_body_size 30M` y `public/.user.ini` sube `upload_max_filesize=6M`, `post_max_size=30M`, `memory_limit=256M` (fotos de 5 MB, varias por petición; dompdf). Traefik no limita el tamaño por defecto.

> **Rutas dentro del contenedor:** Nixpacks copia la app a **`/app`** (no `/var/www/html`); el *document root* es `/app/public`, así que los scripts de la raíz del repo no se pueden pedir por HTTP. Todas las rutas de esta guía usan `/app`.

---

## Fase 5 — Variables de entorno y arranque

1. [ ] En la app → *Environment Variables*, pega la config de producción (Coolify las inyecta; **no** subas `.env` al repo):
   La plantilla completa y comentada está en `.env.production.example`. Lo esencial:
   ```env
   APP_NAME=GCStock
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=            # genera uno (ver abajo) y pégalo aquí
   APP_URL=https://api.cocos-francisco.com
   APP_TIMEZONE=America/Mexico_City

   LOG_CHANNEL=stack
   LOG_STACK=stderr    # los logs salen en el panel de Coolify
   LOG_LEVEL=info

   DB_CONNECTION=mysql
   DB_HOST=<host-interno-de-la-db-de-coolify>
   DB_PORT=3306
   DB_DATABASE=plastigest
   DB_USERNAME=plastigest
   DB_PASSWORD=<el-de-coolify>

   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   FILESYSTEM_DISK=local
   TELESCOPE_ENABLED=false            # Nixpacks instala también las deps de desarrollo
   SANCTUM_TOKEN_EXPIRATION=43200     # minutos (30 días)
   # NO definir SANCTUM_STATEFUL_DOMAINS (la API es solo Bearer; con el dominio
   # de la web ahí, todo POST desde la web respondería 419)

   FRONTEND_URL=https://app.cocos-francisco.com   # exacto, sin "/" final

   # Solo para el primer seed (crea el Super Admin); mínimo 12 caracteres
   ADMIN_EMAIL=admin@cocos-francisco.com
   ADMIN_PASSWORD=<contraseña-larga>

   # Correo (ver Fase 7.5 para el detalle del proveedor). Ejemplo con Resend:
   MAIL_MAILER=resend
   RESEND_KEY=re_xxxxxxxxxxxxxxxxx
   MAIL_FROM_ADDRESS=no-reply@mail.cocos-francisco.com
   MAIL_FROM_NAME=GCStock

   # WhatsApp (si se usa el webhook)
   WHATSAPP_CLOUD_API_TOKEN=
   WHATSAPP_PHONE_NUMBER_ID=
   WHATSAPP_APP_SECRET=
   WHATSAPP_VERIFY_TOKEN=
   ```
   > `APP_KEY`: si lo dejas vacío, genera uno en tu máquina con `php artisan key:generate --show` y pégalo. Debe ser **fijo** (no regenerar en cada deploy o invalidas datos cifrados).
   > **No** definas `FIREBASE_CREDENTIALS`: la ruta por defecto (`config/services.php`) es `/app/storage/app/firebase/service-account.json`, justo donde se monta el archivo (paso 2).

2. [ ] **Storage persistente:** las imágenes, evidencias y recibos se guardan en `storage/app/public`. Monta un **Persistent Storage (volumen)** en **`/app/storage/app`** para que no se borren al redesplegar.

3. [ ] **Credencial de Firebase** (es un archivo, no una env var): súbela al volumen anterior (o como *File Mount*) para que quede en **`/app/storage/app/firebase/service-account.json`**. El **queue worker (Fase 6) también la necesita** (los push salen desde el worker): monta el mismo volumen/archivo ahí. **Nunca la commitees.**

4. [ ] **Comandos post-deploy** (en Coolify, *Post-deployment Command*). Corren en **cada** deploy: cada deploy crea un contenedor nuevo y ni el symlink de `storage:link` ni los cachés vienen en la imagen.
   ```bash
   php artisan migrate --force && php artisan storage:link --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```

5. [ ] Primer **Deploy**. Verifica logs en Coolify hasta ver la app arriba (y que `/up` responda 200).

6. [ ] **Solo la primera vez** (BD vacía), desde la terminal de la app en Coolify:
   ```bash
   php artisan db:seed --class=CompaniesSeeder --force   # empresa + 8 sucursales reales (se niega si ya hay datos)
   php artisan db:seed --force                           # recursos, permisos, unidades y Super Admin (requiere ADMIN_PASSWORD)
   php artisan db:seed --class=InitialCatalogSeeder --force   # catálogo real: categorías, proveedores, productos y paquetes (stock en 0)
   ```
   > `InitialCatalogSeeder` carga el catálogo de Cocos Francisco una sola vez: si la empresa ya tiene productos no hace nada, así no pisa precios cambiados desde la app. El stock queda en 0: la existencia inicial se captura con un conteo (Inventario → Inv. semanal) o con ajustes de entrada en cada sucursal.
   >
   > En producción `db:seed` solo corre los seeders de referencia; nunca catálogos ni datos demo, y `CompaniesSeeder` no borra nada si ya existen empresas. Correr `db:seed --force` otra vez es seguro (idempotente; no pide ni cambia la contraseña del admin ya creado). Después del primer seed puedes quitar `ADMIN_PASSWORD` de las variables y cambiar la contraseña desde la app.

---

## Fase 6 — Cola (queue worker)

En el deploy manual esto era un servicio systemd; en Coolify es un **recurso aparte que comparte el mismo repo/imagen**:

1. [ ] *+ New* → *Application* (o "Additional service" sobre la misma fuente) apuntando al mismo repo.
2. [ ] **Command override (Start Command):**
   ```bash
   php artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=60 --max-time=3600
   ```
   > `--queue=notifications,default` es obligatorio: las notificaciones (push, correo, in-app) se encolan en la cola `notifications` y el resto (reset de contraseña, cierre de caja) en `default`. Sin la primera, las notificaciones nunca salen. `--timeout` (60 s) debe quedar por debajo de `retry_after` (90 s) para no procesar un job dos veces.
3. [ ] **Las mismas variables de entorno** que la app (DB, `MAIL_*`/`RESEND_KEY`, `APP_KEY`, etc.) y el **JSON de Firebase** montado en `/app/storage/app/firebase/service-account.json` (Fase 5, paso 3). Sin dominio y con el *health check* desactivado (no escucha HTTP). Coolify lo mantiene **Restart: always**.
4. [ ] Activa *Auto Deploy* también en el worker: corre en su propio contenedor, así que solo toma el código nuevo cuando se redespliega.

> Alternativa más simple: un proceso con **Supervisor** dentro del mismo contenedor. Pero separarlo como recurso propio es lo más "Coolify".

---

## Fase 7 — Scheduler (cron)

El scheduler (definido en `routes/console.php`) dispara `tasks:generate-recurring` a las 06:00, `notifications:scheduled` cada hora de 07:00 a 21:00 (avisos de tareas por vencer o vencidas y de recordatorios: **sin el scheduler esos avisos nunca salen**) y dos limpiezas nocturnas: `queue:prune-failed` (jobs fallidos de más de 7 días, 03:30) y `sanctum:prune-expired` (tokens vencidos, 03:45). Horas en `APP_TIMEZONE` (America/Mexico_City).

- [ ] En Coolify → app → *Scheduled Tasks* → nueva tarea:
  - **Frecuencia:** `* * * * *` (cada minuto, como Laravel espera).
  - **Comando:** `php artisan schedule:run`
- [ ] Verifica tras un día que se generaron las tareas recurrentes (o fuérzalo con `php artisan tasks:generate-recurring` desde la terminal de la app).
- [ ] Los avisos a usuarios (campana y push) los procesa el **worker** de la cola `notifications` (Fase 6); el scheduler solo agenda los que dependen de la hora. Prueba rápida: `php artisan notifications:scheduled` debe imprimir cuántos avisos envió.

---

## Fase 7.5 — Correo saliente (SMTP / Resend)

La app **manda correos** y hay que configurarlos: reset de contraseña con OTP (`PasswordResetMail`), notificaciones (`GenericNotificationMail`) y el **cierre de caja con PDF adjunto** (`CashClosingMail`).

> ✅ **Resuelto en código:** el cierre de caja (`CashClosingController`) y el reset de contraseña (`PasswordResetController`) ahora usan `Mail::to(...)->queue(...)`, así que el envío SMTP corre en el **queue worker** (Fase 6) y **no bloquea** la petición HTTP. Por eso el worker de la Fase 6 es **obligatorio** en producción: si no está corriendo, los correos se quedan en la tabla `jobs` sin enviarse. (En dev sin worker, `QUEUE_CONNECTION=sync` los corre inline.) El PDF del corte viaja en base64 dentro del job (`CashClosingMail`): el payload de la cola es JSON y no admite el binario crudo.

En dev se usa **MailHog**. En producción, proveedor real. **Recomendado: Resend** (driver `resend` ya en `config/mail.php`, y el dominio en Cloudflare facilita SPF/DKIM).

### Opción A — Resend (recomendada)
1. [ ] Cuenta en [resend.com](https://resend.com) → *API Keys* → generar.
2. [x] *Domains* → se verificó el **subdominio** `mail.cocos-francisco.com` (DKIM/SPF/MX en verde en Cloudflare). El `from` DEBE estar en ese subdominio, no en la raíz.
3. [x] `resend/resend-php` ya está instalado (`composer require resend/resend-php`, en `composer.json`).
4. [ ] En Coolify, las env vars de correo ya van en la Fase 5:
   ```env
   MAIL_MAILER=resend
   RESEND_KEY=re_xxxxxxxxxxxxxxxxx
   MAIL_FROM_ADDRESS=no-reply@mail.cocos-francisco.com
   MAIL_FROM_NAME=GCStock
   ```

### Opción B — SMTP genérico (Mailgun / SES / Gmail-Workspace)
1. [ ] Host/puerto/usuario/contraseña del proveedor (Gmail/Workspace: **App Password**).
2. [ ] Verifica el dominio y crea sus **SPF/DKIM (TXT)** en Cloudflare DNS (Fase 8).
3. [ ] Env vars en Coolify (en vez de las de Resend):
   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.tu-proveedor.com
   MAIL_PORT=587
   MAIL_SCHEME=smtp           # STARTTLS automático en 587; "smtps" para el 465. "tls"/"ssl" NO son válidos en Laravel 12
   MAIL_USERNAME=<usuario>
   MAIL_PASSWORD=<password / app-password>
   MAIL_FROM_ADDRESS=no-reply@mail.cocos-francisco.com
   MAIL_FROM_NAME=GCStock
   ```

> Coolify aplica las env vars en el redeploy (y el `config:cache` del post-deploy). Cambiar correo = editar vars + redeploy.

### Verificación
1. [ ] Desde la **terminal de la app** en Coolify:
   ```bash
   php artisan tinker
   >>> Mail::raw('Prueba prod', fn($m) => $m->to('TU_CORREO@gmail.com')->subject('Test GCStock'));
   ```
   Debe llegar (revisa spam). Si falla, mira los logs de la app en Coolify.
2. [ ] Flujo real: reset de contraseña (OTP) y un **cierre de caja** → que llegue con el **PDF adjunto**.

> **Salida SMTP:** con Resend/Mailgun **por API HTTPS** no dependes del puerto SMTP (Traefik/Docker no lo bloquean). Con SMTP puro, verifica que el contenedor pueda salir al 587/465.

> **DNS anti-spam (Fase 8):** además de SPF y DKIM, agrega un **DMARC** TXT en `_dmarc.cocos-francisco.com` (`v=DMARC1; p=none; rua=mailto:postmaster@cocos-francisco.com`). Sin esto Gmail/Outlook marcan o rechazan.

---

## Fase 8 — DNS y SSL

Cloudflare DNS:
- [ ] `api.cocos-francisco.com` → registro **A** a la IP del VPS.
  - Ponlo en **DNS only** (nube gris) la primera vez para que Coolify/Let's Encrypt emita el cert sin interferencia.
  - Una vez emitido, puedes proxearlo (nube naranja) con SSL mode **Full (strict)** en Cloudflare.
- [ ] `app.cocos-francisco.com` → lo crea Cloudflare Pages al añadir el *Custom domain* (Fase 9).
- [ ] (Opcional) `coolify.cocos-francisco.com` → **A** a la IP, para el panel.
- [ ] **Correo (Fase 7.5):** los **TXT de SPF y DKIM** del proveedor + **DMARC** en `_dmarc.cocos-francisco.com`. Van **DNS only** (no se proxean). Sin ellos los correos caen en spam.

SSL del backend: **automático** en Coolify una vez que el DNS resuelve. No hay Certbot manual.

---

## Fase 9 — Frontend (Cloudflare Pages)

Igual que en el plan original (Coolify no toca el frontend):

1. [ ] Cloudflare Dashboard → *Workers & Pages* → *Create* → *Pages* → conectar `plastigest-app-v3`.
2. [ ] Build settings:
   - **Build command:** `npx expo export -p web`
   - **Output directory:** `dist`
3. [ ] Variables (`EXPO_PUBLIC_*`, se **incrustan en el build**):
   ```env
   EXPO_PUBLIC_API_URL=https://api.cocos-francisco.com/api
   EXPO_PUBLIC_APP_ENV=production
   EXPO_PUBLIC_APP_NAME=GCStock
   EXPO_PUBLIC_APP_VERSION=1.0.0
   EXPO_PUBLIC_FIREBASE_API_KEY=...
   EXPO_PUBLIC_FIREBASE_AUTH_DOMAIN=...
   EXPO_PUBLIC_FIREBASE_PROJECT_ID=...
   ```
   > Son **obligatorias**: `.env` no está en el repo y, sin `EXPO_PUBLIC_API_URL`, el build apunta a `http://localhost/api`. Las apps móviles (EAS) toman estos valores de los perfiles de `eas.json`.
4. [ ] Deploy. La web es una SPA (`output: "single"`): `public/_redirects` ya sirve `index.html` en cualquier ruta, así que recargar `sales/[id]` no da 404.

---

## Fase 10 — CORS

- [x] `config/cors.php` ya restringe el origen según el entorno: en `production` solo permite `FRONTEND_URL` (sin orígenes ni patrones de dev). Si `FRONTEND_URL` no está puesta, la lista queda vacía y bloquea todo (a prueba de fallos).
- [ ] **Único requisito en Coolify:** que la variable `FRONTEND_URL=https://app.cocos-francisco.com` esté puesta (ya está en la Fase 5). El `config:cache` del post-deploy la aplica.

---

## Fase 11 — (Opcional) Almacenamiento en R2

Para el beta el volumen local del VPS sirve. Para sacar imágenes/PDF del servidor:

1. [ ] Crear bucket en Cloudflare R2 + token S3.
2. [ ] **Requiere código:** instalar `league/flysystem-aws-s3-v3` (no está en `composer.json`) y cambiar los `Storage::disk('public')` del código por un disco configurable; cambiar solo `FILESYSTEM_DISK=s3` no mueve las imágenes y, sin el paquete, rompe la app.
3. [ ] Variables en Coolify: claves `AWS_*` + `AWS_ENDPOINT` de R2. Redeploy.
4. [ ] Verificar subidas y apertura de PDFs.

Ventaja con R2: ya no dependes del volumen persistente para `storage/app`.

---

## Fase 12 — Respaldos (NO opcional)

- [ ] **Base de datos:** en Coolify → la base MySQL → *Backups*: activa un backup programado **diario** (p. ej. `0 3 * * *`), con retención de 14 días, y en *Settings → S3 Storages* agrega el bucket de **R2** como destino. No dejes el único respaldo en el mismo VPS.
- [ ] **Archivos (`storage/app`: imágenes, evidencias, JSON de Firebase):** Coolify no respalda volúmenes de aplicaciones. En el VPS, un cron diario que empaquete el volumen y lo suba a R2 (nombre real del volumen: `docker volume ls`):
  ```bash
  # /root/backup-storage.sh  — crontab: 30 3 * * * /root/backup-storage.sh
  set -e
  VOL=<volumen-de-storage-app>
  F=/root/backups/storage-$(date +%F).tar.gz
  mkdir -p /root/backups
  docker run --rm -v "$VOL":/data:ro -v /root/backups:/backup alpine tar czf "/backup/$(basename "$F")" -C /data .
  rclone copy "$F" r2:plastigest-backups/storage/     # rclone configurado con el token S3 de R2
  find /root/backups -name 'storage-*.tar.gz' -mtime +14 -delete
  ```
- [ ] **Probar una restauración** de ambos al menos una vez (BD en una base nueva; volumen en un directorio temporal).

---

## Fase 13 — Actualizaciones futuras (deploy)

Con Coolify el deploy es **automático**:
- [ ] Activa *Auto Deploy* (webhook de GitHub): cada `push` a `main` redeploya.
- Si lo prefieres manual, botón **Deploy** en el panel.
- Los comandos de release (migrate, *:cache) ya están en post-deploy (Fase 5), así que no hay script manual.

> Migraciones destructivas o seeders nuevos: revísalos antes de mergear a `main`, porque el auto-deploy los corre solo.

---

## Fase 14 — Prueba de humo antes de abrir a usuarios

En `app.cocos-francisco.com`, ciclo real completo:

1. [ ] Login real → seleccionar empresa y sucursal.
2. [ ] **Vender** → confirmar que el **stock baja**.
3. [ ] Que la venta **entre a caja**.
4. [ ] **Cierre de caja** y que **cuadre**.
5. [ ] **Cancelar** una venta → el **stock regresa**.
6. [ ] **Ajuste de inventario** y **conteo semanal**.
7. [ ] Generar un **PDF** y que abra.
8. [ ] Desde **dos sucursales**, confirmar que los stocks están aislados.

---

## Fase 15 — Go-live checklist

- [ ] `APP_DEBUG=false` y `APP_KEY` fijo.
- [ ] CORS restringido al dominio de la web (no `*`).
- [ ] Queue worker (Fase 6) **running** con `--queue=notifications,default` y scheduled task (Fase 7) puesto.
- [ ] **Correo (Fase 7.5)** funcionando: proveedor configurado, SPF/DKIM/DMARC en DNS y prueba real (reset de contraseña + cierre de caja con PDF).
- [ ] Volumen persistente en `/app/storage/app` y JSON de Firebase en `/app/storage/app/firebase/service-account.json`, en la app **y** en el worker.
- [ ] Post-deploy con `migrate`, `storage:link` y cachés en **cada** deploy; health check `/up` en verde.
- [ ] `TELESCOPE_ENABLED=false`, `LOG_STACK=stderr`, sin `SANCTUM_STATEFUL_DOMAINS`.
- [ ] Backup diario de la DB **y** del volumen de `storage/app` **probados** (restauración verificada, fuera del servidor).
- [ ] Fix de doble-venta desplegado.
- [ ] Panel de Coolify por HTTPS y puerto `8000` cerrado.
- [ ] Plan de internet de respaldo por sucursal (POS web = sin internet, sin ventas).
- [ ] (Opcional) Web push: requiere `EXPO_PUBLIC_FIREBASE_VAPID_KEY` y `firebase-messaging-sw.js`. El beta puede salir sin esto.

---

## Costo mensual

| Concepto | Costo |
|---|---|
| Hetzner CPX31 (4 vCPU / 8 GB) | ~€16 (CPX21 4 GB ~€12.49 si aprietas) |
| Coolify | $0 (open source, self-hosted) |
| Cloudflare Pages / DNS / SSL | $0 |
| Cloudflare R2 (backups, <10 GB) | $0 |
| Firebase push (plan Spark) | $0 |
| Dominio | ~$1 |

**Total: ~€16 + dominio ≈ $18/mes** (o ~$14-15 con CPX21).

Coolify vs. deploy manual: pagas un poco más de RAM y ganas SSL automático, deploys por `git push`, backups de DB integrados y rollback por panel. A cambio, dependes de que Coolify (Docker/Traefik) se mantenga sano en el host.

---

### Cadencia estimada

Fases 1-7 (Coolify + backend + cola + scheduler) ≈ 2-3 h la primera vez (mucho menos que el manual). Fases 8-9 (DNS + frontend) ≈ 1 h. El resto es pruebas y respaldos. Como en el plan manual: el cuello de botella real será el internet de cada sucursal y los respaldos.
