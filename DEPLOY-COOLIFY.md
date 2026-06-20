# Plan de despliegue — PlastiGest (Coolify + Hetzner + Cloudflare)

Runbook para sacar el beta a producción usando **Coolify** (PaaS self-hosted) sobre un VPS Hetzner. Coolify reemplaza el trabajo manual de Nginx, SSL, queue worker, cron y deploys: instalas Coolify **una vez** y todo lo demás se configura por panel/Docker.

- **Backend:** Laravel 12, PHP 8.3, Sanctum (token Bearer), cola y caché en `database`, scheduler (`tasks:generate-recurring` diario 06:00), PDFs con dompdf, push con Firebase (kreait).
- **Frontend:** Expo 54 / Expo Router (web `output: "static"`) → Cloudflare Pages.
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
- [ ] Decidir qué seeders corren en producción (Fase 5): solo referencia (permisos, unidades), **no** datos demo.

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

> Alternativa: instalar MySQL a mano en el host como en el deploy clásico. Pero dejar que Coolify lo gestione (backups incluidos, Fase 9) es más simple.

---

## Fase 4 — Crear la app del backend

1. [ ] *+ New* → *Application* → *Public/Private Repository* → conectar `plastigest-back-v2` (rama `main`).
2. [ ] **Build Pack: Nixpacks** (autodetecta Laravel/PHP/Composer). No hay Dockerfile propio en el repo, así que Nixpacks es la ruta directa.
3. [ ] **Dominio:** poner `https://api.cocos-francisco.com`. Coolify emite el SSL automático cuando el DNS resuelva (Fase 8).
4. [ ] **Puerto expuesto:** el que sirva PHP (Nixpacks/Laravel usa 8080 por defecto en su runtime; Coolify suele detectarlo — si no, configúralo).
5. [ ] `client_max_body_size` equivalente: en Coolify se ajusta en la config del proxy si las subidas de imágenes (20 MB) dan 413. Por defecto Traefik no limita tan bajo, así que normalmente no hace falta.

---

## Fase 5 — Variables de entorno y arranque

1. [ ] En la app → *Environment Variables*, pega la config de producción (Coolify las inyecta; **no** subas `.env` al repo):
   ```env
   APP_NAME=GCStock
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=            # genera uno (ver abajo) y pégalo aquí
   APP_URL=https://api.cocos-francisco.com

   DB_CONNECTION=mysql
   DB_HOST=<host-interno-de-la-db-de-coolify>
   DB_PORT=3306
   DB_DATABASE=plastigest
   DB_USERNAME=plastigest
   DB_PASSWORD=<el-de-coolify>

   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   FILESYSTEM_DISK=local

   FRONTEND_URL=https://app.cocos-francisco.com

   # Correo (ver Fase 7.5 para el detalle del proveedor). Ejemplo con Resend:
   MAIL_MAILER=resend
   RESEND_KEY=re_xxxxxxxxxxxxxxxxx
   MAIL_FROM_ADDRESS=no-reply@mail.cocos-francisco.com
   MAIL_FROM_NAME=GCStock

   # Firebase — la lee config/services.php → services.firebase.credentials
   FIREBASE_CREDENTIALS=/var/www/html/storage/app/firebase/service-account.json
   ```
   > `APP_KEY`: si lo dejas vacío, genera uno en tu máquina con `php artisan key:generate --show` y pégalo. Debe ser **fijo** (no regenerar en cada deploy o invalidas datos cifrados).
   > La ruta base de la app dentro del contenedor Nixpacks suele ser `/var/www/html`. Ajusta `FIREBASE_CREDENTIALS` si tu build usa otra.

2. [ ] **Credencial de Firebase** (es un archivo, no una env var): usa un **Persistent Storage / Volume Mount** en Coolify para montar el JSON dentro del contenedor en la ruta de `FIREBASE_CREDENTIALS`. Así sobrevive a los redeploys. Súbelo por el panel o por SSH al host y móntalo. **Nunca lo commitees.**

3. [ ] **Storage persistente:** como `FILESYSTEM_DISK=local`, monta también un volumen para `storage/app` (imágenes de productos, PDFs) para que no se borren al redesplegar. (O salta a R2, Fase 10.)

4. [ ] **Comandos post-deploy** (en Coolify, *Pre/Post Deployment Commands* o un script de release):
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=UnitsSeeder --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
   > Los `db:seed` solo la **primera** vez (o hazlos idempotentes). Las siguientes, deja solo `migrate --force` + los `*:cache`.

5. [ ] Primer **Deploy**. Verifica logs en Coolify hasta ver la app arriba.

6. [ ] Crear el primer **admin / empresa / 8 sucursales** (seeder propio o `php artisan tinker` desde la terminal de la app en Coolify).

---

## Fase 6 — Cola (queue worker)

En el deploy manual esto era un servicio systemd; en Coolify es un **recurso aparte que comparte el mismo repo/imagen**:

1. [ ] *+ New* → *Application* (o "Additional service" sobre la misma fuente) apuntando al mismo repo.
2. [ ] **Command override:**
   ```bash
   php artisan queue:work --sleep=3 --tries=3 --max-time=3600
   ```
3. [ ] Mismas variables de entorno y conexión a la DB. Coolify lo mantiene **Restart: always**.

> Alternativa más simple: un proceso con **Supervisor** dentro del mismo contenedor. Pero separarlo como recurso propio es lo más "Coolify".

---

## Fase 7 — Scheduler (cron)

El scheduler dispara `tasks:generate-recurring` a las 06:00 (definido en `routes/console.php`).

- [ ] En Coolify → app → *Scheduled Tasks* → nueva tarea:
  - **Frecuencia:** `* * * * *` (cada minuto, como Laravel espera).
  - **Comando:** `php artisan schedule:run`
- [ ] Verifica tras un día que se generaron las tareas recurrentes (o fuérzalo con `php artisan tasks:generate-recurring` desde la terminal de la app).

---

## Fase 7.5 — Correo saliente (SMTP / Resend)

La app **manda correos** y hay que configurarlos: reset de contraseña con OTP (`PasswordResetMail`), notificaciones (`GenericNotificationMail`) y el **cierre de caja con PDF adjunto** (`CashClosingMail`).

> ✅ **Resuelto en código:** el cierre de caja (`CashClosingController`) y el reset de contraseña (`PasswordResetController`) ahora usan `Mail::to(...)->queue(...)`, así que el envío SMTP corre en el **queue worker** (Fase 6) y **no bloquea** la petición HTTP. Por eso el worker de la Fase 6 es **obligatorio** en producción: si no está corriendo, los correos se quedan en la tabla `jobs` sin enviarse. (En dev sin worker, `QUEUE_CONNECTION=sync` los corre inline.)

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
   MAIL_SCHEME=tls            # "ssl" para puerto 465
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
   EXPO_PUBLIC_APP_NAME=PlastiGest
   EXPO_PUBLIC_APP_VERSION=1.0.0
   EXPO_PUBLIC_FIREBASE_API_KEY=...
   EXPO_PUBLIC_FIREBASE_AUTH_DOMAIN=...
   EXPO_PUBLIC_FIREBASE_PROJECT_ID=...
   ```
4. [ ] Deploy. Si rutas dinámicas (`sales/[id]`) dan 404 al recargar, agregar fallback SPA (servir `index.html` en 404).

---

## Fase 10 — CORS

- [x] `config/cors.php` ya restringe el origen según el entorno: en `production` solo permite `FRONTEND_URL` (sin orígenes ni patrones de dev). Si `FRONTEND_URL` no está puesta, la lista queda vacía y bloquea todo (a prueba de fallos).
- [ ] **Único requisito en Coolify:** que la variable `FRONTEND_URL=https://app.cocos-francisco.com` esté puesta (ya está en la Fase 5). El `config:cache` del post-deploy la aplica.

---

## Fase 11 — (Opcional) Almacenamiento en R2

Para el beta el volumen local del VPS sirve. Para sacar imágenes/PDF del servidor:

1. [ ] Crear bucket en Cloudflare R2 + token S3.
2. [ ] Variables en Coolify: `FILESYSTEM_DISK=s3` + claves `AWS_*` al endpoint de R2. Redeploy.
3. [ ] Verificar subidas y apertura de PDFs.

Ventaja con R2: ya no dependes del volumen persistente para `storage/app`.

---

## Fase 12 — Respaldos (NO opcional)

- [ ] **Base de datos:** Coolify tiene backups programados nativos para sus DBs → activa backup **diario** y configúralo para subir a **S3/R2** (no dejes el único respaldo en el mismo VPS).
- [ ] **Archivos:** si usas volumen local para `storage/app`, respáldalo también (o usa R2, Fase 11, y deja de preocuparte).
- [ ] **Probar una restauración** al menos una vez.

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
- [ ] Queue worker (Fase 6) **running** y scheduled task (Fase 7) puesto.
- [ ] **Correo (Fase 7.5)** funcionando: proveedor configurado, SPF/DKIM/DMARC en DNS y prueba real (reset de contraseña + cierre de caja con PDF).
- [ ] Volúmenes persistentes para Firebase JSON y `storage/app` (o R2).
- [ ] Backup diario de la DB **probado** (restauración verificada, fuera del servidor).
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
