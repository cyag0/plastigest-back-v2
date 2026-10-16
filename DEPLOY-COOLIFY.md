# Plan de despliegue — PlastiGest (Coolify + Hetzner + Cloudflare)

Runbook para sacar el beta a producción usando **Coolify** (PaaS self-hosted) sobre un VPS Hetzner. Coolify reemplaza el trabajo manual de Nginx, SSL, queue worker, cron y deploys: instalas Coolify **una vez** y todo lo demás se configura por panel/Docker. (La alternativa sin Coolify, montando el VPS a mano, está en `DEPLOY.md`.)

- **Backend:** Laravel 12 en **PHP 8.4**, Sanctum (solo token Bearer, sin cookies), cola y caché en `database`, scheduler (avisos, tareas recurrentes, cuadre de inventario y limpiezas; Fase 7), PDFs con dompdf, Excel de los reportes con OpenSpout, push con Firebase (kreait). La imagen la arma **Nixpacks**, sin build de Node (Fase 4).
- **Frontend:** Expo SDK 56 / Expo Router. Web con `output: "single"` (SPA: un solo `index.html`) → Cloudflare Pages (Fase 9). Apps Android/iOS con EAS Build (Fase 9.5).
- **Objetivo:** 8 sucursales, ~30 usuarios. Carga pequeña: el VPS va sobrado.

Arquitectura objetivo:
- `api.cocos-francisco.com` → app de Laravel en **Coolify** (VPS Hetzner).
- `app.cocos-francisco.com` → Cloudflare Pages (build estático de Expo web).
- `coolify.cocos-francisco.com` → panel de Coolify por HTTPS (para cerrar el puerto 8000, Fase 2).
- DNS en Cloudflare; SSL del backend **automático** vía Coolify (Let's Encrypt / Traefik).

> Diferencia clave con el deploy manual: aquí **no** tocas Nginx ni Certbot ni systemd. Coolify lo gestiona. Tú solo conectas el repo, pones las variables y das deploy.

---

## Estado al 15-oct-2026 y pendientes del dueño

**Nada está subido todavía.** Todo lo que describe este runbook vive en ramas locales:
- Backend `plastigest-back-v2`: rama `feat/produccion-empaque-y-roles`, unos 145 commits por delante de `origin/main` (que sigue en junio). `origin/main` es ancestro de la rama: el merge es *fast-forward*.
- Frontend `plastigest-app-v3`: rama `feat/produccion-empaque-y-roles`, unos 130 commits por delante de `origin/master` (mayo); también *fast-forward*.
- Hasta seguir el **Orden de salida** (abajo), Coolify desplegaría el `main` de junio y Cloudflare el `master` de mayo.

### Resuelto en código (llega con el deploy)
- Build de producción reproducible: Nixpacks 1.41.0, PHP 8.4, `composer install --no-dev`, sin build de Node; CI que corre las pruebas y construye la imagen de producción (Fase 4).
- **Doble venta:** cobros idempotentes en el servidor (`client_request_id`: un reintento devuelve la misma venta, no otra) y candado en la app (punto de venta, venta de mostrador, cobro de pedidos, kiosco y abonos).
- Logs de la API, del worker y del scheduler en Coolify; alerta a los administradores del sistema cuando falla un job o una tarea programada; reporte de errores de la app; versión mínima de la app nativa (Fases 7.6 y 9.5).
- Telescope e IDE Helper solo en local; ningún seeder trae contraseñas ni datos fiscales inventados; el webhook de WhatsApp rechaza todo sin `WHATSAPP_VERIFY_TOKEN`.
- IP real del cliente detrás de Cloudflare y límites de intentos de login por correo y por IP.
- Caja: el efectivo siempre pasa por un turno abierto, el crédito no cuenta como cobrado y una compra recibida incompleta paga solo lo que llegó.
- Transferencias ("Pedir a", cada paso desde la sucursal que le toca), conteos ("Correcto" toma la existencia del servidor), producción (folios por empresa, responsable) y aislamiento por empresa y sucursal en toda la API.
- Roles y usuarios: nadie modifica a quien tiene más permisos que él; el acceso a todas las sucursales es un permiso propio (`locations_all`).
- App: token en el almacenamiento seguro del teléfono, fotos reducidas antes de subir, pantalla de error por sección con "Reintentar", sucursal por pestaña en la web, cabeceras de seguridad en Pages.
- Pruebas: 568 en verde en MySQL 8.0.32 y 8.4.11 (en local, PHP 8.4, al commit `cd990c4`), y la instalación de producción (migraciones + seeders) probada desde cero en MySQL 8.4. **En GitHub el CI todavía no ha corrido.**

### Pendientes del dueño (solo tú puedes hacerlos)
1. [ ] **Revocar el token de WhatsApp** publicado en el historial del repo (`get-phone-info.php` en `origin/main`, commit `7eb963a`): Meta Business Manager → usuarios del sistema → revocar y generar uno nuevo, que va **solo** en Coolify (`WHATSAPP_CLOUD_API_TOKEN`). Subir los commits nuevos no lo borra del historial.
2. [ ] **Hacer privados los dos repos** de GitHub (`plastigest-back-v2` y `plastigest-app-v3`) **antes de subir nada**: hoy publican contactos de proveedores (seeders), archivos del negocio (en el historial) y estos runbooks. Coolify (GitHub App o deploy key) y Cloudflare Pages funcionan con repos privados. Purgar el historial (`git filter-repo`) es opcional una vez revocado el token.
3. [ ] **La contraseña vieja del Super Admin está quemada:** estuvo escrita en `UserSeeder` (historial de `origin/main`, commit `47b2e2f`). No la reutilices ni una variante (revisa Coolify, Hetzner, Cloudflare, GitHub y el correo); cámbiala en toda base sembrada desde `origin/main`, incluida la de desarrollo. Producción se crea desde cero con un `ADMIN_PASSWORD` nuevo (Fase 5); comprueba que la vieja no entra.
4. [ ] **Datos fiscales de la empresa:** el seeder ya no los inventa. Captura razón social, RFC, correo y teléfono en *Administración → Datos de la empresa*, y el teléfono de cada sucursal (Fase 13). Mientras falten, el ticket simplemente no los muestra.
5. [ ] **Aviso de privacidad y baja de cuenta:** en el frontend, `public/privacidad.html` y `public/eliminar-cuenta.html` tienen marcadores (`[RAZÓN SOCIAL O NOMBRE DEL RESPONSABLE]`, `[DOMICILIO COMPLETO]`, `[CORREO DE CONTACTO]` con `privacidad@REEMPLAZAR.com`, `[FECHA DE PUBLICACIÓN]`) y un recuadro "Borrador". Llénalos (idealmente con revisión legal) y quita los recuadros. Las tiendas piden `https://app.cocos-francisco.com/privacidad` y `/eliminar-cuenta`.
6. [ ] **Roles con `locations_all` en bases actualizadas:** la migración `2026_10_15_230100` da "Acceso a todas las sucursales" a todo rol que tenía `locations_list` (en la copia de desarrollo: Super Admin y Encargado de sucursal). En una instalación nueva solo lo tiene el Super Admin. Si actualizas una base existente, revisa *Roles y permisos* y quítalo a los roles que solo deben ver su sucursal.
7. [ ] **Política de contraseñas del personal:** hoy mínimo 8 caracteres (el Super Admin del seed, 12). Subirla (p. ej. 10 caracteres y rechazar contraseñas filtradas, lo que consulta un servicio externo) es decisión tuya; el cambio de código es pequeño.
8. [ ] **CI en MySQL 8.4 al subir:** el workflow `Tests` (pruebas en MySQL 8.4 + imagen Nixpacks) nunca ha corrido en GitHub. Tras el primer push confirma los dos jobs en verde antes de tocar `main`.
9. [ ] (Menor) Borrar o mover notas sueltas del repo del backend: `DIAGNOSTICO_SEEDER_PRODUCTOS.md`, `tasks-checklist.md`, `tables.md`, `PURCHASE_V2_IMPLEMENTATION.md`, `_ide_helper_models.php`, `.gitlab/duo/mcp.json`, `.claude/settings.local.json` y `.copilot-instructions.md`.

Las decisiones de negocio que ya quedaron en el código están en **Decisiones tomadas**, al final: revisa que estés de acuerdo.

### Pendientes técnicos que no bloquean el beta
- **Expo SDK 57** en su propia rama (regresión de memoria de Hermes V1 en Android; Fase 9.5).
- **iOS:** `GoogleService-Info.plist` real y llave APNs, solo si se publica iOS (Fase 9.5).
- Sin actualizaciones OTA (`expo-updates`): la versión mínima de la Fase 9.5 cubre el caso que bloquea.
- Mejoras del deploy que requieren código: correr `migrate` al arrancar el contenedor en vez de en el post-deploy, y que `/up` pruebe la base de datos (Fase 16).
- CSP completa en la web (hoy solo `frame-ancestors`), después de probarla en un entorno de pruebas.
- El frontend arrastra errores previos de TypeScript y ESLint y avisos de `npm audit` en herramientas de build: no frenan el build.

---

## Orden de salida a producción

Nada de esto se ha hecho todavía. Cada paso supone el anterior.

1. [ ] Pendientes del dueño 1 y 2 (token revocado, repos privados).
2. [ ] **Backend:** `git push -u origin feat/produccion-empaque-y-roles` y abrir un PR a `main`. Esperar el workflow `Tests` en verde (jobs `phpunit` y `nixpacks`).
3. [ ] **Merge a `main`** (fast-forward): `git checkout main && git merge --ff-only feat/produccion-empaque-y-roles && git push origin main` (o mergear el PR). Desde aquí, a `main` solo llegan commits con CI en verde (Fase 4).
4. [ ] **Coolify** (Fases 1–8): servidor, firewall, Coolify, MySQL, app, variables, worker y scheduler. Crea la app, o dale *Deploy*, solo cuando `main` ya tenga este código. El post-deploy corre las migraciones (Fase 5).
5. [ ] Revisar el log del deploy (el post-deploy sin errores) y `php artisan migrate:status` (nada pendiente).
6. [ ] **Seeds de primera instalación** (Fase 5, paso 6).
7. [ ] **Verificar** `https://api.cocos-francisco.com/up` → 200, el error de prueba en los logs (Fase 7.6), y worker y scheduler corriendo.
8. [ ] **Frontend:** en `plastigest-app-v3`, `git checkout master && git merge --ff-only feat/produccion-empaque-y-roles && git push origin master`, solo cuando la API nueva ya responda (el kiosco usa el cobro rápido que solo existe en la API nueva).
9. [ ] **Cloudflare Pages** construye `master` (Fase 9); el log del build debe decir `✓ API del build web: https://api.cocos-francisco.com/api`.
10. [ ] **Primer día en la app** (Fase 13) y **prueba de humo** (Fase 14).
11. [ ] **Apps nativas:** builds de EAS (Fase 9.5); cuando estén instaladas, fijar `LATEST_NATIVE_APP_VERSION` / `MIN_NATIVE_APP_VERSION` si aplica.
12. [ ] **Go-live checklist** (Fase 15).

> Rollback: vuelve solo a commits que ya estuvieron desplegados. Coolify puede regresar a una imagen anterior, pero las migraciones ya aplicadas no se revierten solas (Fase 16).

---

## Fase 0 — Antes de tocar nada

- [x] ~~Comprar dominio y mover su DNS a Cloudflare~~ — `cocos-francisco.com` comprado en **Cloudflare Registrar**; el DNS ya está en Cloudflare (solo falta crear los registros, Fase 8).
- [ ] Crear cuentas: [Hetzner Cloud](https://www.hetzner.com/cloud) y [Cloudflare](https://dash.cloudflare.com) (gratis). ✅ Cloudflare ya la tienes (el dominio está ahí).
- [ ] **Decidir proveedor de correo** (Resend recomendado) — necesario para reset de contraseña, notificaciones, cierre de caja y alertas del sistema (Fase 7.5).
- [ ] Llave SSH lista (`~/.ssh/id_ed25519.pub`). ✅ (ya la tienes)
- [ ] Tener el **JSON de credenciales de Firebase** (service account) del proyecto `plastigest-976ac`: el mismo de `google-services.json` y del push web.
- [ ] Pendientes del dueño 1–3 (sección anterior), antes del primer push.
- [x] ~~Commit + push del fix de doble-venta y limpiar `console.log` de debug~~ — resuelto en código: cobros idempotentes en servidor y app; en producción la app silencia sus logs (`utils/silenceLogs.ts`). Falta subirlo (Orden de salida).
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
4. [ ] **Firewall de Hetzner (Cloud Firewall), no solo ufw.** Docker publica los puertos de Coolify (8000 panel, 6001 tiempo real, 6002 terminal) con reglas propias que se saltan ufw: aunque ufw diga que están cerrados, siguen abiertos a internet, y el panel tiene control total del servidor, de los secretos y de la base. Filtra en el proveedor:
   - Hetzner Cloud Console → *Firewalls* → *Create Firewall* → reglas de entrada:
     - TCP 22 (lo ideal: solo tu IP; si tu IP cambia, la consola web de Hetzner sirve de respaldo).
     - TCP 80 y TCP 443 desde cualquier IPv4/IPv6 (el 80 lo usa Let's Encrypt).
     - TCP 8000 **solo desde tu IP y solo mientras registras el admin de Coolify** (Fase 2).
     - Nada más: ni 3306, ni 6001, ni 6002.
   - *Apply to* → el servidor.
   - ufw es opcional y no protege los puertos de Docker. Si lo activas, permite `OpenSSH` antes de `ufw enable`.

> Coolify recomienda instalarse en un servidor **limpio**, como root. No montes el stack LEMP a mano: lo hace él.

---

## Fase 2 — Instalar Coolify

En el VPS, como root:
```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```
El instalador pone Docker, Traefik (proxy + SSL) y Coolify. Tarda unos minutos.

1. [ ] Abrir el panel: `http://IP:8000` (desde tu IP, con la regla temporal de la Fase 1).
2. [ ] Crear la **cuenta admin** (primer registro = dueño de la instancia). Hazlo rápido: queda expuesto hasta que registres.
3. [ ] El servidor donde corre Coolify aparece como **localhost**; ya está listo como destino de deploy.
4. [ ] **Dominio del panel:** crea el registro `coolify.cocos-francisco.com` (Fase 8), pon `https://coolify.cocos-francisco.com` como dominio de la instancia en *Settings*, entra por HTTPS y **borra la regla del 8000** en el firewall de Hetzner. Con dominio, el tiempo real y la terminal web pasan por el 443: 6001 y 6002 nunca se abren. Comprueba que la terminal web sigue funcionando.
5. [ ] Activa la **verificación en dos pasos (2FA)** en tu perfil de Coolify.
6. [ ] Desde fuera del servidor (tu PC), comprueba que solo responden 22, 80 y 443:
   ```bash
   nmap -Pn -p 22,80,443,3306,6001,6002,8000 <IP>   # 3306, 6001, 6002 y 8000 deben salir "filtered"
   curl -m 5 http://<IP>:8000                         # debe agotar el tiempo
   ```

---

## Fase 3 — Base de datos (MySQL gestionado por Coolify)

1. [ ] En tu proyecto Coolify → *+ New* → *Database* → **MySQL**, con imagen `mysql:8.4` (la serie con la que corre CI).
2. [ ] Anota credenciales que genera (host interno, user, password, db). El **host** será el nombre de servicio interno de Docker (ej. el que muestre Coolify), no `127.0.0.1`.
3. [ ] No expongas el puerto público de la DB. Si algún día lo activas para una restauración puntual, ábrelo en el firewall de Hetzner solo para tu IP y ciérralo al terminar.
4. [ ] Ajusta la memoria de MySQL en la configuración personalizada del recurso (*Custom MySQL Configuration*) y reinicia la base:
   ```ini
   [mysqld]
   innodb_buffer_pool_size=2G      # CPX31 (8 GB); en un CPX21 (4 GB) usa 1G
   innodb_redo_log_capacity=512M
   slow_query_log=ON
   long_query_time=1               # consultas de más de 1 s al log de MySQL
   ```
   El valor por defecto (128 MB) no alcanza para los ~1.5–2 GB de datos que se consultan a diario desde el primer año: sin esto, reportes, caja y listas terminan leyendo de disco.
5. [ ] Verifica tras reiniciar, desde la terminal de la base en Coolify: `mysql -uroot -p -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size'; SHOW VARIABLES LIKE 'slow_query_log'"`. El log de consultas lentas queda dentro del contenedor (`/var/lib/mysql/<host>-slow.log`).

> Alternativa: instalar MySQL a mano en el host como en el deploy clásico. Pero dejar que Coolify lo gestione (backups incluidos, Fase 12) es más simple.

---

## Fase 4 — Crear la app del backend

1. [ ] *+ New* → *Application* → *Private Repository* (GitHub App o deploy key; el repo será privado) → conectar `plastigest-back-v2` (rama `main`). Ver el Orden de salida: `main` debe tener ya este código.
2. [ ] **Build Pack: Nixpacks** (autodetecta Laravel/PHP/Composer). No hay Dockerfile propio en el repo, así que Nixpacks es la ruta directa.
3. [ ] **Dominio:** poner `https://api.cocos-francisco.com`. Coolify emite el SSL automático cuando el DNS resuelva (Fase 8).
4. [ ] **Puerto expuesto (Ports Exposes): `80`.** El nginx del contenedor Nixpacks escucha en `$PORT`, que Nixpacks fija en `80` (no 8080). Si cambias `PORT`, cambia también *Ports Exposes*.
5. [ ] **Health check:** ruta `/up` (la define `bootstrap/app.php`), puerto 80. Ojo: `/up` solo confirma que la app arranca, no que llegue a MySQL (Fase 16).
6. [x] ~~Límite de subida~~ — resuelto en el repo (ver "Cómo se construye la imagen").

> **Rutas dentro del contenedor:** Nixpacks copia la app a **`/app`** (no `/var/www/html`); el *document root* es `/app/public`, así que los scripts de la raíz del repo no se pueden pedir por HTTP. Todas las rutas de esta guía usan `/app`.

### Cómo se construye la imagen (no hay que configurar nada)

- **Nixpacks 1.41.0** (el que trae Coolify; CI usa el mismo). `nixpacks.toml` solo ajusta lo necesario:
  - `[phases.install]`: `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`, **sin** `--ignore-platform-reqs`: si a la imagen le falta una extensión que piden los paquetes, falla el build y no producción. Telescope, IDE Helper, Sail y PHPUnit no entran a la imagen, y la app ya arranca sin ellos (solo se registran en local).
  - `NIXPACKS_NODE_VERSION = "22"`: Node solo se usa al arrancar el contenedor (el script de Nixpacks que genera `/nginx.conf`); sin esto Nixpacks instala Node 18, que ya no tiene soporte.
  - `php-fpm.conf` propio (el de Nixpacks 1.41.0 más `[global]`): `error_log = /proc/self/fd/2`, `log_limit = 8192` y `decorate_workers_output = no`. Sin esto los errores de Laravel se quedaban en un archivo dentro del contenedor y nunca llegaban a Coolify (Fase 7.6).
- **Sin build de Node/Vite:** la API no tiene frontend (se quitaron `package.json`, `vite.config.js` y `resources/js|css`) y el plan de Nixpacks no corre `npm` (CI lo revisa). Antes el build fallaba por Node 18.
- **PHP 8.4.2:** Nixpacks elige PHP por `"php": "^8.4"` de `composer.json`. `config.platform.php` está fijo en `8.4.2` para que `composer update` no traiga paquetes que pidan un PHP más nuevo que el de producción, y el chequeo de plataforma de Composer no deja arrancar la app con un PHP más viejo. (La 8.2 pierde soporte de seguridad el 31/12/2026.)
- **Extensiones:** `ext-zip` y `ext-xmlreader` (Excel con OpenSpout, fijo en `~4.28.5`) están declaradas en `composer.json`; la imagen trae además `pdo_mysql`, `gd`, `intl`, `bcmath`, `pcntl`, `sodium` y `mbstring` (CI lo verifica).
- **Límites:** `nginx.template.conf` (el nginx del contenedor) tiene `client_max_body_size 30M`; `public/.user.ini` sube `upload_max_filesize=6M`, `post_max_size=30M`, `memory_limit=256M` (fotos de 5 MB, varias por petición; dompdf) y `max_input_vars=10000` (completar un conteo manda 7 campos por producto; con el default de 1000 se perdían datos a partir de ~142 productos). Traefik no limita el tamaño por defecto.

### Integración continua (GitHub Actions)

`.github/workflows/tests.yml` (workflow `Tests`) corre en cada push y en cada PR:
- **`phpunit`:** PHP 8.4 con las extensiones de producción, MySQL 8.4 y la suite completa.
- **`nixpacks`:** descarga Nixpacks 1.41.0 (verifica su sha256), revisa que el plan no corra `npm`, construye la imagen y comprueba `php -v`, las extensiones, `artisan about`, `config:cache`/`route:cache`/`view:cache` y `GET /up`.

- [ ] **A `main` solo llegan commits en verde.** Coolify despliega cada push a `main` sin esperar al CI: trabaja en ramas, abre un PR y mergea solo con los dos jobs en verde. Si tu plan de GitHub lo permite (en repos privados la protección de ramas requiere GitHub Pro o Team), crea una regla para `main` que exija PR y los checks `phpunit` y `nixpacks`; si no, es regla de proceso.

---

## Fase 5 — Variables de entorno y arranque

1. [ ] En la app → *Environment Variables*, pega la config de producción (Coolify las inyecta; **no** subas `.env` al repo). La plantilla completa y comentada está en `.env.production.example`. Lo esencial:
   ```env
   APP_NAME=GCStock
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=            # genera uno (ver abajo) y pégalo aquí
   APP_URL=https://api.cocos-francisco.com
   APP_TIMEZONE=America/Mexico_City

   LOG_CHANNEL=stack
   LOG_STACK=stderr    # app y worker: los logs salen en el panel de Coolify (Fase 7.6)
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
   TELESCOPE_ENABLED=false            # ya viene apagado fuera de local; explícito por si acaso
   SANCTUM_TOKEN_EXPIRATION=43200     # minutos (30 días)

   FRONTEND_URL=https://app.cocos-francisco.com   # exacto, sin "/" final (CORS, Fase 10)

   # Super Admin del primer seed (reglas abajo)
   ADMIN_EMAIL=admin@cocos-francisco.com
   ADMIN_PASSWORD=<contraseña-nueva-de-12-o-más-caracteres>

   # Correo (ver Fase 7.5 para el detalle del proveedor). Ejemplo con Resend:
   MAIL_MAILER=resend
   RESEND_KEY=re_xxxxxxxxxxxxxxxxx
   MAIL_FROM_ADDRESS=no-reply@mail.cocos-francisco.com
   MAIL_FROM_NAME=GCStock
   MAIL_TIMEOUT=15                    # segundos de espera al proveedor (el worker corta los jobs a los 60)

   # Versiones de la app nativa que acepta la API (Fase 9.5). Vacías = sin restricción
   MIN_NATIVE_APP_VERSION=
   LATEST_NATIVE_APP_VERSION=

   # WhatsApp: órdenes de compra al proveedor y webhook. El token es el NUEVO (pendiente 1)
   WHATSAPP_CLOUD_API_TOKEN=
   WHATSAPP_PHONE_NUMBER_ID=
   WHATSAPP_APP_SECRET=               # firma de Meta del webhook
   WHATSAPP_VERIFY_TOKEN=             # el mismo que capturas en Meta (p. ej. openssl rand -hex 32)
   ```
   > **¿Quién las necesita?** La app y el worker (Fase 6), las mismas. El scheduler corre dentro del contenedor de la app y usa las suyas. Si no quieres copiarlas a mano, Coolify permite definirlas una vez como *Shared Variables* y referenciarlas en cada recurso.
   >
   > `APP_KEY`: genera uno en tu máquina con `php artisan key:generate --show` y pégalo. Debe ser **fijo** (no regenerar en cada deploy o invalidas datos cifrados).
   >
   > **`ADMIN_PASSWORD`:** mínimo 12 caracteres; fuera de local/testing el seeder aborta sin ella. Solo se usa al **crear** el Super Admin (repetir `db:seed` no la pide ni la cambia). Tiene que ser **nueva**, nunca la del historial de git (pendiente 3). Bórrala de las variables cuando entres y cambies la contraseña desde la app (Fase 13).
   >
   > **`ADMIN_EMAIL`** recibe las alertas del sistema (Fase 7.6): que sea un buzón que alguien lea (p. ej. reenviado a tu Gmail con *Email Routing* de Cloudflare).
   >
   > **WhatsApp:** el webhook es `https://api.cocos-francisco.com/api/webhooks/whatsapp` y sin `WHATSAPP_APP_SECRET` y `WHATSAPP_VERIFY_TOKEN` rechaza todo (ya no hay valores por defecto). Usa un token de verificación propio: el `plastigest_webhook_token_2024` que aparece en `docs/WHATSAPP_WEBHOOK_SETUP.md` es público.
   >
   > **Cloudflare y proxies de confianza:** no hay variable. `config/trustedproxy.php` confía en el salto inmediato (el Traefik de Coolify) y en los rangos publicados de Cloudflare (consultados el 2026-10-15), y el middleware `RestoreCloudflareClientIp` toma `CF-Connecting-IP` solo cuando la petición viene de Cloudflare (Fase 8).
   >
   > **No definas** `SANCTUM_STATEFUL_DOMAINS` (la API es solo Bearer; con el dominio de la web ahí, todo POST desde la web respondería 419), ni `FIREBASE_CREDENTIALS` (la ruta por defecto de `config/services.php` es `/app/storage/app/firebase/service-account.json`, justo donde se monta el archivo en el paso 3), ni `LOG_SCHEDULE_OUTPUT` (su default, `/proc/1/fd/1`, es el correcto en Coolify).

2. [ ] **Storage persistente:** las imágenes, evidencias y recibos se guardan en `storage/app/public`. Monta un **Persistent Storage (volumen)** en **`/app/storage/app`** para que no se borren al redesplegar.

3. [ ] **Credencial de Firebase** (es un archivo, no una env var): súbela al volumen anterior (o como *File Mount*) para que quede en **`/app/storage/app/firebase/service-account.json`**. El **queue worker (Fase 6) también la necesita** (los push salen desde el worker): monta el mismo volumen/archivo ahí. **Nunca la commitees.**

4. [ ] **Comandos post-deploy** (en Coolify, *Post-deployment Command*). Corren en **cada** deploy: cada deploy crea un contenedor nuevo y ni el symlink de `storage:link` ni los cachés vienen en la imagen.
   ```bash
   php artisan migrate --force && php artisan storage:link --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   > Coolify los corre cuando el contenedor nuevo ya recibe tráfico, y si fallan el deploy se ve verde igual: revisa siempre el log del deploy (Fase 16).

5. [ ] Primer **Deploy**. Verifica logs en Coolify hasta ver la app arriba, que `/up` responda 200 y, en la terminal de la app, que `php artisan migrate:status` no tenga nada pendiente.

6. [ ] **Solo la primera vez** (BD vacía), desde la terminal de la app en Coolify, **en este orden** (el seeder del Super Admin lo asocia a la empresa y a las sucursales que ya existan):
   ```bash
   php artisan db:seed --class=CompaniesSeeder --force   # empresa + 8 sucursales reales (se niega si ya hay datos)
   php artisan db:seed --force                           # recursos, permisos, unidades y Super Admin (requiere ADMIN_PASSWORD)
   php artisan db:seed --class=InitialCatalogSeeder --force   # catálogo real: categorías, proveedores, productos y paquetes (stock en 0)
   ```
   > `InitialCatalogSeeder` carga el catálogo de Cocos Francisco una sola vez: si la empresa ya tiene productos no hace nada, así no pisa precios cambiados desde la app. El stock queda en 0: la existencia inicial se captura con un conteo por sucursal (Fase 13).
   >
   > `CompaniesSeeder` crea la empresa y las sucursales **sin razón social, RFC, correo ni teléfonos** (no hay datos verificados y salen en los tickets). Se capturan desde la app (Fase 13); mientras estén vacíos, el ticket simplemente no los muestra.
   >
   > Los seeders del catálogo (`CategoriesSeeder`, `SupplierSeeder`, `ProductsSeeder`, `PackageSeeder`, `FormulasSeeder`) no hacen nada si se corren sueltos en producción: solo a través de `InitialCatalogSeeder`.
   >
   > En producción `db:seed` solo corre los seeders de referencia; nunca catálogos ni datos demo, y `CompaniesSeeder` no borra nada si ya existen empresas. Correr `db:seed --force` otra vez es seguro (idempotente; no pide ni cambia la contraseña del admin ya creado).

---

## Fase 6 — Cola (queue worker)

En el deploy manual esto era un servicio systemd; en Coolify es un **recurso aparte que comparte el mismo repo/imagen**:

1. [ ] *+ New* → *Application* (o "Additional service" sobre la misma fuente) apuntando al mismo repo.
2. [ ] **Command override (Start Command):**
   ```bash
   php artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=60 --max-time=3600
   ```
   > `--queue=notifications,default` es obligatorio: las notificaciones (push, correo, in-app) se encolan en la cola `notifications` y el resto (reset de contraseña, cierre de caja) en `default`. Sin la primera, las notificaciones nunca salen. `--timeout` (60 s) debe quedar por debajo de `retry_after` (90 s) para no procesar un job dos veces.
   >
   > Los reintentos los define cada job y mandan sobre `--tries=3`: notificaciones 4 intentos, correo del corte 5 y OTP 4, con esperas de 10 s, 1 min y 5 min. Un reintento no vuelve a avisar a quien ya recibió el aviso. Si un job agota sus intentos, se avisa a los administradores (Fase 7.6).
3. [ ] **Las mismas variables de entorno** que la app (DB, `MAIL_*`/`RESEND_KEY`, `APP_KEY`, `LOG_CHANNEL=stack` y `LOG_STACK=stderr`, etc.) y el **JSON de Firebase** montado en `/app/storage/app/firebase/service-account.json` (Fase 5, paso 3). Sin dominio y con el *health check* desactivado (no escucha HTTP). Coolify lo mantiene **Restart: always**. Sin post-deploy: solo la app migra.
4. [ ] Activa *Auto Deploy* también en el worker: corre en su propio contenedor, así que solo toma el código nuevo cuando se redespliega.

> Alternativa más simple: un proceso con **Supervisor** dentro del mismo contenedor. Pero separarlo como recurso propio es lo más "Coolify".

---

## Fase 7 — Scheduler (cron)

El scheduler está definido en `routes/console.php`. Horas en `APP_TIMEZONE` (America/Mexico_City):

| Cuándo | Comando | Qué hace |
|---|---|---|
| Cada hora, de 07:00 a 21:00 | `notifications:scheduled` | Avisos de tareas por vencer o vencidas y de recordatorios; cada aviso sale una sola vez. Candado de 10 min (`withoutOverlapping(10)`): un deploy a media corrida ya no lo deja trabado hasta el día siguiente. **Sin el scheduler esos avisos nunca salen.** |
| 03:30 | `queue:prune-failed --hours=168` | Borra los jobs fallidos de más de 7 días. |
| 03:45 | `sanctum:prune-expired --hours=24` | Borra los tokens vencidos hace más de 24 h. |
| 04:00 | `inventory:reconcile` | Cuadre: compara la existencia de cada producto por sucursal con su libro mayor. Solo lee. Si algo no cuadra deja en el log el error `Inventario: la existencia no cuadra con el libro mayor`, termina con código 1 y eso dispara la alerta a los administradores (Fase 7.6). |
| 06:00 | `tasks:generate-recurring` | Genera las tareas recurrentes del día. |

La salida de cada tarea va a los Logs de la app en Coolify (`logging.schedule_output`).

- [ ] En Coolify → app → *Scheduled Tasks* → nueva tarea:
  - **Frecuencia:** `* * * * *` (cada minuto, como Laravel espera).
  - **Comando:** `php artisan schedule:run`
- [ ] Verifica que corre: desde la terminal de la app, `php artisan schedule:test --name=inventory:reconcile`. La salida no aparece en la terminal sino en los Logs de la app: `Inventario cuadrado: N producto(s) por sucursal con movimientos, sin diferencias.` Al día siguiente, esa misma línea de las 04:00 debe estar en los logs, y las tareas recurrentes generadas (o fuérzalo con `php artisan tasks:generate-recurring`).
- [ ] Los avisos a usuarios (campana y push) los procesa el **worker** de la cola `notifications` (Fase 6); el scheduler solo agenda los que dependen de la hora. Prueba rápida: `php artisan notifications:scheduled` debe imprimir cuántos avisos envió.

---

## Fase 7.5 — Correo saliente (SMTP / Resend)

La app **manda correos** y hay que configurarlos: reset de contraseña con OTP (`PasswordResetMail`), notificaciones (`GenericNotificationMail`), el **cierre de caja con PDF adjunto** (`CashClosingMail`) y las alertas del sistema a los administradores (`SystemAlertMail`, Fase 7.6).

> ✅ **Resuelto en código:** el cierre de caja (`CashClosingController`) y el reset de contraseña (`PasswordResetController`) usan `Mail::to(...)->queue(...)`, así que el envío corre en el **queue worker** (Fase 6) y **no bloquea** la petición HTTP. Por eso el worker de la Fase 6 es **obligatorio** en producción: si no está corriendo, los correos se quedan en la tabla `jobs` sin enviarse. (En dev sin worker, `QUEUE_CONNECTION=sync` los corre inline.) El PDF del corte viaja en base64 dentro del job (`CashClosingMail`): el payload de la cola es JSON y no admite el binario crudo. SMTP y Resend esperan como máximo `MAIL_TIMEOUT` (15 s) al proveedor. Las alertas del sistema, en cambio, se mandan directo (sin cola), para llegar aunque lo que falle sea la cola.

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
   MAIL_TIMEOUT=15
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
   MAIL_TIMEOUT=15
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

## Fase 7.6 — Logs, errores y alertas

Dónde mirar:
- **App → *Logs*:** errores de la API y la salida del scheduler.
- **Worker → *Logs*:** los jobs (push, correos).
- Con `LOG_LEVEL=info` salen `info` y lo más grave. Al redeployar, el contenedor viejo se elimina y con él sus logs.

Cómo llegan: `LOG_CHANNEL=stack` + `LOG_STACK=stderr` en app y worker. En la app, el `php-fpm.conf` de `nixpacks.toml` manda el stderr de PHP-FPM al del contenedor (antes se perdía en un archivo); las tareas programadas añaden su salida a `/proc/1/fd/1` (`routes/console.php`).

1. [ ] **Prueba con un error a propósito.** Desde tu PC, usando el reporte de errores de la app (solo escribe una línea en el log):
   ```bash
   API=https://api.cocos-francisco.com/api
   curl -s -X POST $API/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"email":"admin@cocos-francisco.com","password":"<contraseña>"}'
   # copia el "access_token" de la respuesta
   TOKEN='<access_token>'
   curl -s -o /dev/null -w '%{http_code}\n' -X POST $API/auth/admin/client-errors \
     -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"message":"Prueba de logs del runbook","platform":"web"}'    # debe imprimir 204
   curl -s -X POST $API/auth/logout -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
   ```
   En los Logs de la app debe aparecer `production.ERROR: Error en la app: Prueba de logs del runbook`, con `user_id`, `company_id` y `location_id`. Si no aparece, revisa `LOG_STACK=stderr` y que la imagen se haya construido con el `nixpacks.toml` del repo.
2. [ ] **Alertas de operación.** Cuando un job agota sus reintentos (correo del corte, push, OTP) o falla una tarea programada (incluido un cuadre que no cuadra), la app:
   - deja una línea `CRITICAL` en el log;
   - crea el aviso "Falló un trabajo en segundo plano" o "Falló una tarea programada" en la campana de cada administrador del sistema (usuarios activos con `system_admin`, como el Super Admin);
   - les manda correo directo si hay proveedor de correo configurado.

   Como mucho un aviso cada 30 min por tipo de falla (el siguiente dice cuántas veces se repitió). Prueba, desde la terminal de la app:
   ```bash
   php artisan tinker --execute="app(\App\Support\SystemAlerts::class)->raise('prueba:runbook', 'Prueba de alerta', 'Alerta de prueba del runbook');"
   ```
   Debe aparecer en la campana del Super Admin y llegar a `ADMIN_EMAIL`. Jobs fallidos: `php artisan queue:failed` (reintentar: `php artisan queue:retry all`); se borran a los 7 días.
3. [ ] **Errores de la app (web y nativa).** Cada sección de la app tiene su pantalla de error con "Reintentar", y el error se reporta solo a `POST /api/auth/admin/client-errors` (máximo 20 por minuto por usuario). En los Logs de la app busca `Error en la app:`; el contexto trae ruta, plataforma, versión de la app, usuario, empresa y sucursal.
4. [ ] **Salida del scheduler.** Busca la línea del cuadre de las 04:00 (`Inventario cuadrado: …`). Si no aparece, la *Scheduled Task* no está corriendo (Fase 7).

---

## Fase 8 — DNS y SSL

Cloudflare DNS:
- [ ] `api.cocos-francisco.com` → registro **A** a la IP del VPS.
  - Ponlo en **DNS only** (nube gris) la primera vez para que Coolify/Let's Encrypt emita el cert sin interferencia.
  - Una vez emitido, puedes proxearlo (nube naranja) con SSL mode **Full (strict)** en Cloudflare.
  - Con la nube naranja la API sigue viendo la IP real de cada usuario (límites de intentos del login, logs): confía en los rangos de Cloudflare de `config/trustedproxy.php` y lee `CF-Connecting-IP` (middleware `RestoreCloudflareClientIp`). Si Cloudflare publica rangos nuevos (https://www.cloudflare.com/ips/), actualiza esa lista (revísala una vez al año).
- [ ] `app.cocos-francisco.com` → lo crea Cloudflare Pages al añadir el *Custom domain* (Fase 9).
- [ ] `coolify.cocos-francisco.com` → **A** a la IP, para el panel por HTTPS (necesario para cerrar el 8000, Fase 2).
- [ ] **Correo (Fase 7.5):** los **TXT de SPF y DKIM** del proveedor + **DMARC** en `_dmarc.cocos-francisco.com`. Van **DNS only** (no se proxean). Sin ellos los correos caen en spam.

SSL del backend: **automático** en Coolify una vez que el DNS resuelve. No hay Certbot manual.

---

## Fase 9 — Frontend web (Cloudflare Pages)

Coolify no toca el frontend:

1. [ ] Cloudflare Dashboard → *Workers & Pages* → *Create* → **Pages** (no Workers) → conectar `plastigest-app-v3` (repo privado); rama de producción **`master`**.
2. [ ] Build settings:
   - **Framework preset:** ninguno.
   - **Build command:** `npm run build:web` (= `node scripts/check-web-env.mjs && expo export --platform web --clear`). El chequeo detiene el build si falta `EXPO_PUBLIC_API_URL`, si no es una dirección válida o si apunta a un servidor local, y avisa si faltan las variables del push web.
   - **Output directory:** `dist`.
   - **Node 22:** lo toma de `.node-version` (`engines` de `package.json`: `^22.13.0 || >=24.3.0`). Si el build usa otra versión, agrega la variable `NODE_VERSION=22`.
3. [ ] Variables (*Settings → Variables*, entorno Production). Se **incrustan en el build**: cambiar una = volver a desplegar. Lista completa de las que lee el código:

   | Variable | ¿Hace falta? | Valor / nota |
   |---|---|---|
   | `EXPO_PUBLIC_API_URL` | **Obligatoria** | `https://api.cocos-francisco.com/api` |
   | `EXPO_PUBLIC_FIREBASE_API_KEY`, `EXPO_PUBLIC_FIREBASE_AUTH_DOMAIN`, `EXPO_PUBLIC_FIREBASE_PROJECT_ID`, `EXPO_PUBLIC_FIREBASE_STORAGE_BUCKET`, `EXPO_PUBLIC_FIREBASE_MESSAGING_SENDER_ID`, `EXPO_PUBLIC_FIREBASE_APP_ID` | Para push web | Firebase → Configuración del proyecto → app web del proyecto `plastigest-976ac` (el de `public/firebase-messaging-sw.js`, `google-services.json` y el service account del backend). |
   | `EXPO_PUBLIC_FIREBASE_VAPID_KEY` | Para push web | Firebase → Cloud Messaging → certificados de push web (par de claves). |
   | `EXPO_PUBLIC_FIREBASE_MEASUREMENT_ID` | Opcional | Analytics de Firebase; no hace falta. |
   | `EXPO_PUBLIC_API_TIMEOUT` / `EXPO_PUBLIC_API_LONG_TIMEOUT` | Opcional | En ms; por defecto 10000 / 60000 (el largo, para cobros y PDFs). |
   | `EXPO_PUBLIC_DEBUG_MODE` | Opcional | Por defecto `false`; déjalo así. |
   | `EXPO_PUBLIC_WEB_URL` | Solo apps nativas | Base de los enlaces del login a privacidad y baja; por defecto `https://app.cocos-francisco.com` (ya en `eas.json`). |
   | `EXPO_PUBLIC_ALLOW_LOCAL_API` | **Nunca en producción** | Solo para builds de prueba contra un backend local. |
   | `EXPO_PUBLIC_AUTH_TOKEN_KEY`, `EXPO_PUBLIC_USER_DATA_KEY`, `EXPO_PUBLIC_COMPANIES_DATA_KEY` | No las pongas | Nombres de las llaves de almacenamiento; cambiarlas cierra la sesión de todos. |

   Sin las de push web la app funciona (la campana se actualiza sola), pero las notificaciones del navegador quedan apagadas. `EXPO_PUBLIC_APP_ENV`, `EXPO_PUBLIC_APP_NAME`, `EXPO_PUBLIC_APP_VERSION` y `EXPO_PUBLIC_LOG_REQUESTS` ya no las lee el código (la versión que se muestra sale de `version` en `app.json` más el hash del bundle).
4. [ ] *Custom domain* → `app.cocos-francisco.com`.
5. [ ] Deploy. En el log del build debe salir `✓ API del build web: https://api.cocos-francisco.com/api`.
6. [ ] Archivos de `public/` que se publican tal cual (`expo export` los copia a `dist/`):
   - `_redirects` (`/* /index.html 200`): fallback de la SPA. Si el log de Pages dice que ignoró la regla por "Infinite loop", no pasa nada: sin `404.html`, Pages ya responde `index.html` en cualquier ruta.
   - `_headers`: `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY`, CSP solo con `frame-ancestors 'none'`, `Permissions-Policy` (cámara solo del sitio; sin micrófono ni ubicación), HSTS de 1 año sin subdominios; caché inmutable de 1 año para `/_expo/static/*` y `/assets/*`, y `no-cache` para `/index.html` y `/firebase-messaging-sw.js`.
   - `privacidad.html` y `eliminar-cuenta.html`: borradores con marcadores (pendiente 5 del dueño).
   - `firebase-messaging-sw.js`: service worker del push web, con la configuración pública del proyecto `plastigest-976ac`.
7. [ ] Verifica: recargar una ruta interna (p. ej. `/home/sales`) no da 404; `/privacidad` muestra el aviso y no la app; `curl -sI https://app.cocos-francisco.com/ | grep -i strict-transport` devuelve la cabecera HSTS.

> Tras cada despliegue, la web avisa a quien la tenga abierta: "Hay una nueva versión de GCStock · Recargar" (revisa al volver a la pestaña y cada 5 min).

---

## Fase 9.5 — Apps nativas (EAS Build)

Perfiles de `eas.json` (bundle/package `com.alejandrotrejo.plastigest`):

| Perfil | Para qué | API |
|---|---|---|
| `development` | Dev client con Metro, distribución interna | La del `.env` local |
| `preview` | Instalable directo para pruebas en sucursal (distribución interna) | Producción |
| `production` | Tiendas; EAS sube solo el número de build (`autoIncrement`, `appVersionSource: remote`) | Producción |

1. [ ] La versión visible es `version` de `app.json` (hoy `1.0.0`) y es la que compara la API: súbela (x.y.z) en cada versión que distribuyas.
2. [ ] `eas build -p android --profile production` (o `--profile preview` para instalar directo), con `eas login` en la cuenta de Expo dueña del proyecto.
3. [ ] **Push Android:** usa `google-services.json` del proyecto Firebase `plastigest-976ac` (ya en el repo); el service account del backend debe ser del mismo proyecto.
4. [ ] **iOS, solo si se publica:** el `GoogleService-Info.plist` del repo es de relleno (proyecto `plastigest-temp`, IDs falsos). Reemplázalo por el real de `plastigest-976ac` (app iOS `com.alejandrotrejo.plastigest`) y sube una llave APNs (`.p8`) en Firebase → Configuración del proyecto → Cloud Messaging. Requiere cuenta de Apple Developer.
5. [ ] **Builds locales:** corre `npx expo prebuild --clean` antes de `npx expo run:android`. La carpeta `android/` local está vieja (respaldo automático y permisos que ya se bloquean); EAS la regenera sola en cada build.

**Versión mínima (`MIN_NATIVE_APP_VERSION`).** La app nativa consulta `GET /api/app/version` al abrir, al volver a primer plano y cada 30 min, y compara con su `version`:
- menor que `MIN_NATIVE_APP_VERSION` → pantalla "Actualiza la app" que no deja operar;
- menor que `LATEST_NATIVE_APP_VERSION` → aviso que se puede cerrar;
- vacías = sin restricción; si la consulta falla, no bloquea. En la web no aplica (ahí está el aviso de "nueva versión").

Procedimiento:
1. [ ] Sube `version` en `app.json` (p. ej. `1.0.0` → `1.1.0`) y haz el build de EAS.
2. [ ] Distribuye e instala (tienda o instalador interno).
3. [ ] En Coolify (app): `LATEST_NATIVE_APP_VERSION=1.1.0` → redeploy (el `config:cache` del post-deploy la aplica).
4. [ ] Solo cuando un cambio de la API ya no funcione con las apps viejas: `MIN_NATIVE_APP_VERSION=1.1.0` → redeploy. Las apps más viejas quedan bloqueadas hasta actualizar.

> **Recomendación: Expo SDK 57 en su propia rama.** `expo-doctor` marca la regresión de memoria de Hermes V1 en Android: SDK 56 trae Hermes `250829098.0.10`, y la corrección (`250829098.0.16`) necesita React Native ≥ 0.86.2, es decir SDK 57 (`expo` ≥ 57.0.9). Haz la actualización en una rama aparte, un build de desarrollo y una prueba de un turno completo en el dispositivo Android del punto de venta antes de depender del POS nativo. La web no se ve afectada. (El aviso de `prebuild` "Using expo@~56.0.23 instead of recommended expo@~57.0.7" es inofensivo.)

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
- [ ] **Probar una restauración** de ambos al menos una vez: la BD en una base nueva (p. ej. `plastigest_restore`; compara el conteo de ventas y productos con producción y bórrala al terminar) y el volumen en un directorio temporal (`mkdir -p /tmp/restore && tar xzf storage-AAAA-MM-DD.tar.gz -C /tmp/restore`: debe estar `public/`, y `firebase/` si el JSON vive en el volumen).

---

## Fase 13 — Primer día en la app (configuración inicial)

Con la API y la web arriba, como Super Admin y antes de la prueba de humo:

1. [ ] Entrar con `ADMIN_EMAIL` / `ADMIN_PASSWORD` y cambiar la contraseña (*Perfil → Cambiar contraseña*). Después borra `ADMIN_PASSWORD` de las variables de Coolify.
2. [ ] **Empresa:** *Administración → Datos de la empresa*: razón social, RFC, correo y teléfono (salen en los tickets; el formulario los pide). En cada sucursal (*Administración → Sucursales*), su teléfono.
3. [ ] **Roles:** *Administración → Roles y permisos* → nuevo rol a partir de las plantillas **Encargado de sucursal**, **Cajero**, **Producción** y **Almacén y compras** (también hay "Acceso total", "Operativo" y "Solo lectura"); ajusta y guarda. Solo "Acceso total" incluye "Acceso a todas las sucursales".
4. [ ] **Usuarios por sucursal:** *Administración → Usuarios*: cada empleado con su rol en su sucursal (contraseña de al menos 8 caracteres). Después, cada encargado puede dar de alta a su personal en *Esta sucursal → Usuarios*.
5. [ ] **Cajas:** una caja por sucursal (*Administración → Cajas*, o *Esta sucursal → Cajas*).
6. [ ] **Existencia inicial:** en cada sucursal, *Inventario → Inv. semanal* → nuevo conteo → capturar lo contado → completar. Las diferencias se vuelven ajustes y la existencia queda igual a lo contado (el catálogo inicial entra con stock 0).
7. [ ] **Notificaciones:** *Administración → Notificaciones* define quién recibe cada aviso en toda la empresa (solo quien ve todas las sucursales); cada encargado ajusta la de su sucursal en *Esta sucursal → Notificaciones*. Cada usuario acepta el permiso de notificaciones en su teléfono o navegador.

---

## Fase 14 — Prueba de humo antes de abrir a usuarios

En `app.cocos-francisco.com` y en un teléfono con la app de EAS, con al menos dos sucursales configuradas (Fase 13). Ten abiertos los Logs de la app en Coolify: no debe aparecer ningún `ERROR` inesperado.

**Ventas y caja**
1. [ ] Login real → seleccionar empresa y sucursal.
2. [ ] **Abrir turno** de caja (*Caja*) con su fondo inicial.
3. [ ] **Venta en el punto de venta** en efectivo → el **stock baja** y la venta **entra al turno**.
4. [ ] **Protección contra doble venta:** doble clic en cobrar → una sola venta. Corta la red justo al cobrar (modo avión, o DevTools → *Offline*) y reintenta: la app verifica y muestra la misma venta con su folio, sin duplicarla.
5. [ ] **Kiosco** (*Pedidos → Modo Quiosco*): cobro rápido en un solo paso (pedido entregado y venta creada); con "pagar después" la venta queda pendiente.
6. [ ] **Cancelar** una venta en efectivo con el turno abierto → el stock regresa y sale el egreso en el turno. Sin turno abierto en esa sucursal → el mensaje "Para devolver efectivo abre un turno de caja en esta sucursal o pide al cajero en turno que cancele la venta." (ver Decisiones tomadas).
7. [ ] **Cerrar turno:** cuadra, y llega el correo del corte con el **PDF** adjunto.

**Inventario, transferencias, compras y producción**
8. [ ] **Transferencia:** desde una sucursal, *Transferencias* → nueva solicitud → **"Pedir a"** Matriz. En Matriz: aprobar y enviar. En la sucursal: recibir (todos los renglones enviados). Las existencias se mueven en las dos sucursales, y cada paso solo se puede hacer desde la sucursal que le toca.
9. [ ] **Conteo** (*Inventario → Inv. semanal*): marcar productos con **"Correcto"** y capturar otros, completar → las diferencias quedan como ajustes; el conteo cerrado ya no se puede editar.
10. [ ] **Compra con recepción parcial:** recibir menos de lo pedido → entra a stock solo lo recibido y, si se pagó de caja, el egreso es por lo recibido.
11. [ ] **Producción con responsable:** crear la orden eligiendo responsable, registrar consumos y salida → existencias de insumos y de producto terminado correctas.
12. [ ] **Ajuste de inventario** y **kárdex** (sin fechas muestra los últimos 30 días).

**Reportes, avisos y aislamiento**
13. [ ] **Reportes del negocio** en PDF y en Excel, y el corte de turno en PDF: descargan y abren.
14. [ ] **Push:** un aviso (p. ej. un paso de la transferencia, o un recordatorio asignado) llega como push al teléfono, y al navegador si configuraste el push web.
15. [ ] **Dos sucursales:** las existencias están aisladas y un usuario de sucursal no ve ni edita las otras.
16. [ ] **Web:** recargar una ruta interna no da 404; `/privacidad` y `/eliminar-cuenta` se ven.
17. [ ] Al final: `php artisan queue:failed` vacío y ningún `CRITICAL` en los Logs.

---

## Fase 15 — Go-live checklist

- [ ] Pendientes del dueño 1–3 hechos (token de WhatsApp revocado, repos privados, contraseña vieja descartada).
- [ ] CI en verde en GitHub (`phpunit` + `nixpacks`) para el commit desplegado.
- [ ] `APP_DEBUG=false` y `APP_KEY` fijo.
- [x] CORS restringido al dominio de la web (no `*`) — en código; solo falta `FRONTEND_URL` (Fase 5).
- [ ] Queue worker (Fase 6) **running** con `--queue=notifications,default` y scheduled task (Fase 7) puesto.
- [ ] **Correo (Fase 7.5)** funcionando: proveedor configurado, SPF/DKIM/DMARC en DNS, prueba real (reset de contraseña + cierre de caja con PDF) y `ADMIN_EMAIL` es un buzón que alguien lee.
- [ ] Volumen persistente en `/app/storage/app` y JSON de Firebase en `/app/storage/app/firebase/service-account.json`, en la app **y** en el worker.
- [ ] Post-deploy con `migrate`, `storage:link` y cachés en **cada** deploy; health check `/up` en verde y `migrate:status` sin pendientes.
- [ ] `LOG_STACK=stderr` en app y worker, sin `SANCTUM_STATEFUL_DOMAINS`; el error de prueba y la alerta de prueba de la Fase 7.6 vistos.
- [x] Logs de la API en Coolify (`php-fpm.conf` de `nixpacks.toml`), salida del scheduler en los logs, alertas de jobs y tareas fallidas y reporte de errores de la app — en código.
- [x] Telescope e IDE Helper fuera de producción (`--no-dev` y registro solo en local); `TELESCOPE_ENABLED=false` queda como refuerzo.
- [x] Fix de doble-venta (servidor + app) — en código; llega con el deploy y se prueba en la Fase 14.
- [x] Límites de subida y de campos (`client_max_body_size 30M`, `.user.ini`) — en código.
- [ ] **Firewall de Hetzner:** desde fuera solo responden 22, 80 y 443 (`nmap`); panel de Coolify por HTTPS con 2FA; 8000, 6001, 6002 y 3306 cerrados.
- [ ] Backup diario de la DB **y** del volumen de `storage/app` **probados** (restauración verificada, fuera del servidor).
- [ ] MySQL con `innodb_buffer_pool_size` ajustado y `slow_query_log` encendido (Fase 3, paso 4).
- [ ] Datos fiscales capturados (Fase 13) y páginas de privacidad sin "Borrador" (antes de publicar en tiendas).
- [ ] Prueba de humo (Fase 14) completa.
- [ ] Apps nativas instaladas en los dispositivos; `LATEST_NATIVE_APP_VERSION` / `MIN_NATIVE_APP_VERSION` definidos si aplica (Fase 9.5).
- [ ] Plan de internet de respaldo por sucursal (POS web = sin internet, sin ventas).
- [ ] (Opcional) Web push: las `EXPO_PUBLIC_FIREBASE_*` y `EXPO_PUBLIC_FIREBASE_VAPID_KEY` en Pages (el service worker ya está en `public/`). El beta puede salir sin esto.

---

## Fase 16 — Actualizaciones futuras y migraciones

Con Coolify el deploy es **automático**:
- [ ] Activa *Auto Deploy* (webhook de GitHub): cada `push` a `main` redeploya. Por eso a `main` solo llegan commits con CI en verde (Fase 4).
- Si lo prefieres manual, botón **Deploy** en el panel.
- Los comandos de release (migrate, `storage:link`, `*:cache`) ya están en el post-deploy (Fase 5), así que no hay script manual.

**Cómo corre el post-deploy (importante).** Coolify levanta el contenedor nuevo, espera a que `/up` responda, detiene el viejo y **después** corre el post-deploy dentro del nuevo. Consecuencias:
- Mientras corre `migrate`, el código nuevo ya atiende peticiones con el esquema viejo (normalmente segundos).
- Si `migrate` falla, el deploy se ve verde igual, y el `&&` se salta `storage:link` y los cachés. Después de cada deploy con migraciones revisa el log del deploy y corre `php artisan migrate:status` (nada pendiente).
- `/up` no prueba la base: una contraseña de DB equivocada también pasa el health check. Confirma con un login real o con `migrate:status`.

Reglas para las migraciones (expandir y contraer):
1. **Expandir:** tablas y columnas nuevas nullable o con valor por defecto, e índices nuevos sobre datos que ya los cumplen. El código de esa versión debe funcionar con el esquema viejo y con el nuevo.
2. **Contraer** en un deploy posterior: borrar o renombrar columnas solo cuando ningún código en producción las use.
3. Un rollback de Coolify regresa la imagen, no el esquema: el código anterior tiene que tolerar el esquema nuevo (otra razón para expandir primero).
4. Índices únicos sobre datos existentes: revisa duplicados antes de mergear.
5. Deploys con migraciones fuera del horario de las sucursales (con *Auto Deploy*, eso es mergear a `main` fuera de horario, o desactivarlo y usar el botón Deploy).

> Migraciones destructivas o seeders nuevos: revísalos antes de mergear a `main`, porque el auto-deploy los corre solo.

> Si `migrate` se detiene con una lista de filas repetidas (p. ej. dos existencias del mismo producto en la misma sucursal en `product_location`), no se borró nada: deja una sola fila correcta por cada caso y vuelve a desplegar. Esas migraciones no fusionan datos solas para no duplicar ni perder stock. (En una instalación nueva no puede pasar; solo al actualizar una base que ya tenía datos.)

> Mejoras posibles (requieren código): correr `migrate` y los cachés en el arranque del contenedor, antes de php-fpm, para que una migración fallida tumbe el contenedor nuevo y Coolify conserve el viejo; y un listener de `DiagnosingHealth` para que `/up` falle si no hay base.

---

## Decisiones tomadas

Quedaron así en el código; si alguna no te convence, es un cambio acotado:

- **La devolución en efectivo necesita un turno abierto.** Cancelar una venta cobrada en efectivo devuelve el dinero desde el turno abierto de quien cancela en esa sucursal (o desde el turno de la venta, si sigue abierto). Si no hay ninguno, la app pide abrir turno o que cancele el cajero en turno; aplica también al dueño. Alternativas si resulta estricto: un permiso para registrar la devolución fuera de turno (con motivo y visible en reportes) o elegir uno de los turnos abiertos de la sucursal.
- **El efectivo fuera del punto de venta también pasa por un turno:** venta de mostrador, cobro de pedidos, kiosco y abonos en efectivo piden turno abierto; tarjeta y transferencia no. En el kiosco, "pagar después" deja la venta pendiente, sin pago ni movimiento de caja.
- **Límites de intentos por correo y por IP.** Login: 5 por minuto por correo+IP, 30 por hora por correo y 30 por minuto por IP. "Olvidé mi contraseña": 3 por hora y 10 por día por correo, 20 por hora por IP; validar código y cambiar contraseña: 5 por minuto por correo y 10 por IP. Una sucursal detrás de una sola IP ya no comparte 5 intentos; a cambio, quien conozca un correo puede bloquear por un rato (hasta una hora) el login o la recuperación de esa cuenta. Las sesiones abiertas siguen funcionando.
- **Reglas para editar roles y usuarios.** Solo un administrador del sistema modifica a otro. Nadie cambia los accesos (contraseña, correo, activo, roles, sucursales) de alguien con permisos que él no tiene, ni da o quita permisos que no tiene, ni edita un rol que tenga un usuario con más permisos. Los roles del sistema no se editan. Un encargado de sucursal solo asigna el rol de su sucursal, no roles para todas. Nadie puede desactivar su propia cuenta. La API marca cada usuario con `can_manage` para que la app oculte los controles que el servidor rechazaría.
- **Notificaciones:** la configuración de avisos de toda la empresa solo la cambia quien ve todas las sucursales (`locations_all` o administrador del sistema); un encargado solo la de su sucursal. Puede silenciar avisos de su propia sucursal (mermas, diferencias de conteo); si no lo quieres, quita `notifications_manage` de su rol.
- **Sucursales:** quien solo opera su sucursal no ve ni edita las demás, ni puede desactivar o mover la suya.
- **30 días por omisión:** las estadísticas de ventas y el kárdex, sin rango de fechas, muestran los últimos 30 días (la pantalla lo dice).
- **Venta en otra unidad:** un renglón (sin paquete) en otra unidad del mismo tipo se guarda convertido a la unidad del producto: la cantidad, a milésimas, y el precio por unidad del producto, que se compara con el de catálogo en centavos exactos, sin tolerancia. Ejemplo: 500 g de un producto de $25/kg quedan como 0.5 kg a $25.00 = $12.50; mandar $0.03 por gramo equivale a $30/kg y se rechaza a quien no tiene permiso de descuento. Así el total, el stock y el ticket dicen lo mismo. Una unidad de otro tipo, o una cantidad que convertida queda por debajo de 0.001, se rechaza. Los paquetes conservan su propio precio.
- **Turno que se cierra a la vez que un gasto, una compra o un movimiento manual:** el movimiento queda fuera de turno, nunca dentro de un turno ya cerrado.

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

Fases 1-7 (Coolify + backend + cola + scheduler) ≈ 2-3 h la primera vez (mucho menos que el manual). Fases 8-9 (DNS + frontend) ≈ 1 h. Fase 13 (primer día) ≈ 1-2 h más el conteo inicial de cada sucursal; la prueba de humo, ≈ 1 h. El resto es respaldos y apps nativas. Como en el plan manual: el cuello de botella real será el internet de cada sucursal y los respaldos.
