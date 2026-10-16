# Plan de despliegue — PlastiGest (web, sin Forge)

Runbook para sacar el beta a producción montando el servidor **a mano** sobre un VPS. Stack detectado:

> **La ruta principal es `DEPLOY-COOLIFY.md`.** Ahí están el estado al 15-oct-2026, los pendientes del dueño (token de WhatsApp, repos privados, contraseña vieja del Super Admin), el orden de salida, el frontend, las apps nativas, logs y alertas, el primer día en la app, la prueba de humo y las decisiones tomadas. Esta guía solo cubre lo que cambia al montar el servidor sin Coolify; lo común se remite allá.

- **Backend:** Laravel 12, PHP 8.4 (`composer.json` lo exige: con otra versión la app no arranca), Sanctum (token Bearer), cola y caché en `database`, scheduler (avisos cada hora de 07:00 a 21:00, cuadre de inventario 04:00, tareas recurrentes 06:00 y limpiezas; Fase 6), PDFs con dompdf, Excel con OpenSpout, push con Firebase (kreait). Sin build de Node: la API no tiene frontend.
- **Frontend:** Expo SDK 56 / Expo Router (web `output: "single"`, SPA). API por `EXPO_PUBLIC_API_URL`.
- **Objetivo:** 8 sucursales, ~30 usuarios en total. Pico concurrente ~24 (3 por sucursal), realista ~8. Carga pequeña: el VPS va sobrado.

> **Estado:** el VPS ya está aprovisionado → `vps3451515.trouble-free.net` (Contabo). Ya no hay que crear servidor (Fase 1.1). Falta el hardening del SO, el dominio y todo el stack. Ver **"Qué falta"** al final.

> **Dominio:** `cocos-francisco.com`, **comprado en Cloudflare** (Cloudflare Registrar). El DNS ya vive en Cloudflare, así que el paso de "mover DNS" de la Fase 0 ya está resuelto: solo hay que crear los registros (Fase 10).

Arquitectura objetivo:
- `api.cocos-francisco.com` → **VPS Contabo** (`vps3451515.trouble-free.net`), Ubuntu 24.04, stack LEMP a mano.
- `app.cocos-francisco.com` → Cloudflare Pages (build estático de Expo web).
- DNS en Cloudflare (dominio ya registrado ahí); SSL del backend con Certbot (Let's Encrypt) en el VPS.

> Todos los comandos del backend se corren **por SSH dentro del VPS** (`ssh root@vps3451515.trouble-free.net`). Reemplaza `cocos-francisco.com`, contraseñas y rutas por los tuyos.

---

## Fase 0 — Antes de tocar nada

- [x] ~~Crear cuenta y servidor~~ — VPS Contabo ya contratado (`vps3451515.trouble-free.net`).
- [x] ~~Comprar dominio y mover su DNS a Cloudflare~~ — `cocos-francisco.com` comprado en **Cloudflare Registrar**; el DNS ya está en Cloudflare.
- [x] ~~Crear cuenta de Cloudflare~~ — ya existe (el dominio está ahí). Sirve para DNS + Pages.
- [ ] Generar un **par de llaves SSH** y subir la pública al VPS (Contabo suele dar acceso root por contraseña; conviene pasar a llave). Si no tienes: `ssh-keygen -t ed25519`, luego `ssh-copy-id root@vps3451515.trouble-free.net`.
- [ ] Tener el **JSON de credenciales de Firebase** (service account) del proyecto `plastigest-976ac`, el que ya usas en dev.
- [ ] Pendientes del dueño de `DEPLOY-COOLIFY.md` (token de WhatsApp revocado, repos privados, contraseña vieja del Super Admin descartada) antes de subir nada.
- [x] ~~Commit + push del fix de doble-venta y limpiar los `console.log`~~ — resuelto en código (cobros idempotentes en servidor y app; la app silencia sus logs en producción). Falta subirlo: ver "Orden de salida" en `DEPLOY-COOLIFY.md`.
- [x] ~~Decidir qué seeders corren en producción~~ — resuelto en código: fuera de local `db:seed` solo corre los de referencia (Fase 4).

---

## Fase 1 — Asegurar el servidor

> El VPS **ya existe** (`vps3451515.trouble-free.net`, Contabo). Te saltas la creación; empieza por entrar y endurecerlo.

1. [x] ~~Crear servidor~~ — ya aprovisionado (Contabo). Verifica que sea **Ubuntu 24.04** (`lsb_release -a`); si es otra versión, los comandos de PHP/Nginx siguen igual pero anótalo.
2. [ ] Entrar como root y anotar la IP pública (`curl ifconfig.me`): `ssh root@vps3451515.trouble-free.net`.
3. [ ] Actualizar y crear un usuario de trabajo con sudo:
   ```bash
   apt update && apt upgrade -y
   adduser deploy
   usermod -aG sudo deploy
   rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy   # copia tu llave al nuevo user
   ```
4. [ ] Firewall (deja solo SSH + web):
   ```bash
   ufw allow OpenSSH
   ufw allow 80
   ufw allow 443
   ufw enable
   ```
   > ufw basta aquí porque este stack no usa Docker. Si algún día instalas Docker (o Coolify) en este VPS, los puertos que publica Docker se saltan ufw: filtra entonces en el firewall del proveedor (ver Fases 1 y 2 de `DEPLOY-COOLIFY.md`). Comprueba desde fuera que solo responden 22, 80 y 443 (`nmap -Pn -p 22,80,443,3306 <IP>`).
5. [ ] Salir y volver a entrar como `deploy`: `ssh deploy@IP`. (Opcional pero recomendado: deshabilitar login root y por contraseña en `/etc/ssh/sshd_config`.)

---

## Fase 2 — Instalar el stack (Nginx, PHP 8.4, MySQL, Composer)

```bash
# Nginx
sudo apt install -y nginx

# PHP 8.4 + extensiones que necesita el proyecto
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl

# MySQL
sudo apt install -y mysql-server
sudo mysql_secure_installation     # pon contraseña root y responde "Y" a los hardening

# Composer (global)
cd ~ && curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Git
sudo apt install -y git
```

> dompdf usa `gd` (ya incluido arriba) para imágenes en PDF. Firebase (kreait) usa curl/openssl (ya disponibles). El Excel de los reportes (OpenSpout) necesita `zip` y `xmlreader` (este último viene en `php8.4-xml`). Si falta una extensión que piden los paquetes, `composer install` se detiene: no uses `--ignore-platform-reqs`.

---

## Fase 3 — Base de datos

```bash
sudo mysql
```
```sql
CREATE DATABASE plastigest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'plastigest'@'localhost' IDENTIFIED BY 'CONTRASEÑA_FUERTE';
GRANT ALL PRIVILEGES ON plastigest.* TO 'plastigest'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Memoria de MySQL: el valor por defecto del buffer pool (128 MB) no alcanza para los datos que se consultan a diario. Crea `/etc/mysql/mysql.conf.d/zz-plastigest.cnf` y reinicia (`sudo systemctl restart mysql`):
```ini
[mysqld]
innodb_buffer_pool_size=1G      # con 4 GB de RAM; con 8 GB, 2G (comparte el servidor con PHP-FPM)
innodb_redo_log_capacity=512M
slow_query_log=ON
long_query_time=1
```

---

## Fase 4 — Desplegar el código

1. [ ] Clonar el repo (usa un *deploy key* o token de GitHub):
   ```bash
   sudo mkdir -p /var/www && sudo chown deploy:deploy /var/www
   cd /var/www
   git clone https://github.com/TU_USUARIO/plastigest-back-v2.git plastigest
   cd plastigest
   composer install --no-dev --optimize-autoloader
   ```
   > `--no-dev` ya funciona: Telescope e IDE Helper solo se registran en local, así que la app arranca sin las dependencias de desarrollo.
2. [ ] Crear `.env`:
   ```bash
   cp .env.example .env
   php artisan key:generate
   nano .env
   ```
   Ajustes de producción:
   ```env
   APP_NAME=GCStock
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.cocos-francisco.com
   APP_TIMEZONE=America/Mexico_City

   # Logs en archivos diarios (storage/logs/laravel-AAAA-MM-DD.log, 14 días)
   LOG_CHANNEL=stack
   LOG_STACK=daily
   LOG_LEVEL=info

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=plastigest
   DB_USERNAME=plastigest
   DB_PASSWORD=CONTRASEÑA_FUERTE

   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   FILESYSTEM_DISK=local
   TELESCOPE_ENABLED=false
   SANCTUM_TOKEN_EXPIRATION=43200
   # NO definir SANCTUM_STATEFUL_DOMAINS (la API es solo Bearer)

   FRONTEND_URL=https://app.cocos-francisco.com

   # Solo para el primer seed (crea el Super Admin); mínimo 12 caracteres.
   # Nueva: nunca la contraseña vieja que quedó en el historial de git.
   ADMIN_EMAIL=admin@cocos-francisco.com
   ADMIN_PASSWORD=<contraseña-larga>

   # Firebase: ruta ABSOLUTA del JSON (config/services.php → services.firebase.credentials)
   FIREBASE_CREDENTIALS=/var/www/plastigest/storage/app/firebase/credentials.json
   ```
   La plantilla completa y comentada está en `.env.production.example`: copia también `MAIL_*` y `MAIL_TIMEOUT` (Fase 7.5), `MIN_NATIVE_APP_VERSION` / `LATEST_NATIVE_APP_VERSION` y `WHATSAPP_*` (con el token nuevo y un `WHATSAPP_VERIFY_TOKEN` propio). Las reglas de cada variable están en la Fase 5 de `DEPLOY-COOLIFY.md`.
3. [ ] Subir el JSON de Firebase a esa ruta (con `scp` desde tu máquina):
   ```bash
   scp credentials.json deploy@IP:/var/www/plastigest/storage/app/firebase/credentials.json
   ```
   **Nunca lo commitees al repo.**
4. [ ] Migraciones, seeders de referencia y enlace de storage:
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=CompaniesSeeder --force   # solo con la BD vacía: empresa + 8 sucursales reales (se niega si ya hay datos)
   php artisan db:seed --force                           # recursos, permisos, unidades y Super Admin (requiere ADMIN_PASSWORD)
   php artisan db:seed --class=InitialCatalogSeeder --force   # una vez: catálogo real (categorías, proveedores, productos, paquetes) con stock en 0
   php artisan storage:link
   ```
   > En producción `db:seed` solo corre los seeders de referencia (nunca catálogos ni datos demo) y es seguro repetirlo. Córrelo **antes** del `config:cache` del paso 6: con la config cacheada, `ADMIN_PASSWORD` del `.env` ya no se lee.
5. [ ] Permisos para que Nginx/PHP puedan escribir:
   ```bash
   sudo chown -R deploy:www-data storage bootstrap/cache
   sudo chmod -R 775 storage bootstrap/cache
   ```
6. [ ] Cachear configuración:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
7. [ ] Entrar con `ADMIN_EMAIL` / `ADMIN_PASSWORD` (creados en el paso 4 junto con la empresa y las 8 sucursales), cambiar la contraseña y quitar `ADMIN_PASSWORD` del `.env`.

---

## Fase 5 — Nginx + SSL

1. [ ] Crear el server block en `/etc/nginx/sites-available/plastigest`:
   ```nginx
   server {
       listen 80;
       server_name api.cocos-francisco.com;
       root /var/www/plastigest/public;

       index index.php;
       charset utf-8;
       server_tokens off;

       add_header X-Frame-Options "SAMEORIGIN";
       add_header X-Content-Type-Options "nosniff";

       gzip on;
       gzip_vary on;
       gzip_min_length 1024;
       gzip_types application/json application/javascript text/css text/plain;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       # Fotos de 5 MB c/u, varias por petición. PHP-FPM toma sus límites de
       # public/.user.ini (upload_max_filesize=6M, post_max_size=30M).
       client_max_body_size 30M;

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
           # La app confía en el salto inmediato (REMOTE_ADDR) y en los rangos
           # de Cloudflare (config/trustedproxy.php). Aquí nginx da la cara
           # directamente, así que el salto inmediato es el cliente: se
           # descartan los X-Forwarded-* que mande (si no, podría falsear su
           # IP y saltarse el límite de intentos del login) y se informa el
           # esquema real.
           fastcgi_param HTTP_X_FORWARDED_FOR "";
           fastcgi_param HTTP_X_FORWARDED_HOST "";
           fastcgi_param HTTP_X_FORWARDED_PORT "";
           fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
           fastcgi_hide_header X-Powered-By;
       }

       location ~ /\.(?!well-known).* { deny all; }
   }
   ```
   > Si luego proxeas `api.` con Cloudflare (nube naranja), no hace falta tocar nginx: cuando la petición llega desde un rango de Cloudflare, la app toma la IP real de `CF-Connecting-IP` (middleware `RestoreCloudflareClientIp`). Mantén actualizada la lista de rangos de `config/trustedproxy.php`.
2. [ ] Activar y recargar:
   ```bash
   sudo ln -s /etc/nginx/sites-available/plastigest /etc/nginx/sites-enabled/
   sudo rm -f /etc/nginx/sites-enabled/default
   sudo nginx -t && sudo systemctl reload nginx
   ```
3. [ ] **Antes del SSL**, apuntar el DNS (Fase 10) para que `api.cocos-francisco.com` resuelva a la IP. Luego:
   ```bash
   sudo apt install -y certbot python3-certbot-nginx
   sudo certbot --nginx -d api.cocos-francisco.com
   ```
   Certbot edita el Nginx para HTTPS y renueva solo.

---

## Fase 6 — Cola y scheduler

**Queue worker** como servicio systemd. Crear `/etc/systemd/system/plastigest-worker.service`:
```ini
[Unit]
Description=PlastiGest queue worker
After=network.target mysql.service

[Service]
User=deploy
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/plastigest/artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=60 --max-time=3600
WorkingDirectory=/var/www/plastigest

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now plastigest-worker
sudo systemctl status plastigest-worker     # verificar que está "active (running)"
```
> `--queue=notifications,default` es obligatorio: las notificaciones (push, correo, in-app) van a la cola `notifications` y el resto (reset de contraseña, cierre de caja) a `default`; sin la primera, las notificaciones nunca salen. El worker usa el mismo `.env` y necesita el JSON de Firebase en la ruta de `FIREBASE_CREDENTIALS` (los push salen desde el worker). `--timeout` (60 s) debe quedar por debajo de `retry_after` (90 s). Los reintentos con espera los define cada job (mandan sobre `--tries=3`), y si un job agota sus intentos se avisa a los administradores del sistema (campana y correo; ver Fase 7.6 de `DEPLOY-COOLIFY.md`).

**Scheduler** vía cron. `crontab -e` (como `deploy`) y agregar:
```cron
* * * * * cd /var/www/plastigest && php artisan schedule:run >> /dev/null 2>&1
```
Esto dispara, según `routes/console.php` (hora de `APP_TIMEZONE`): `notifications:scheduled` cada hora de 07:00 a 21:00 (avisos de tareas y recordatorios: sin el cron nunca salen), `queue:prune-failed` (03:30), `sanctum:prune-expired` (03:45), el cuadre `inventory:reconcile` (04:00; si no cuadra, error en el log y alerta a los administradores) y `tasks:generate-recurring` (06:00). Detalle en la Fase 7 de `DEPLOY-COOLIFY.md`.

> Salida de las tareas: por defecto va a `/proc/1/fd/1`, que en un VPS no es escribible por `deploy`, así que se descarta (los errores de los comandos igual quedan en el log de Laravel). Para guardarla: `touch /var/www/plastigest/storage/logs/schedule.log`, `LOG_SCHEDULE_OUTPUT=/var/www/plastigest/storage/logs/schedule.log` en el `.env` y `php artisan config:cache` (el archivo debe existir y ser escribible; si no, se ignora).

---

## Fase 7 — CORS

- [x] Resuelto en `config/cors.php`: en `production` solo se permite `FRONTEND_URL` (sin orígenes ni patrones de dev); si no está puesta, se bloquea todo.
- [ ] Requisito: `FRONTEND_URL=https://app.cocos-francisco.com` exacto (sin `/` final) en el `.env`, y `php artisan config:cache` tras cambiarlo.

---

## Fase 7.5 — Correo saliente (SMTP)

La app **sí manda correos** y hay que configurarlos: recuperación de contraseña con OTP (`PasswordResetMail`), notificaciones (`GenericNotificationMail`) y el **cierre de caja con PDF adjunto** (`CashClosingMail`, `CashClosingController@…`).

> ⚠️ **Importante:** el reset de contraseña y el cierre de caja se **encolan** (`Mail::to(...)->queue(...)`) y las notificaciones salen desde un job de la cola `notifications`: todo lo envía el **queue worker** (Fase 6). Sin worker corriendo (con `--queue=notifications,default`) o sin `MAIL_*` válidos, los correos se quedan en `jobs` o terminan en `failed_jobs`. El PDF del corte viaja en base64 dentro del job (el payload de la cola es JSON).

En dev se usa **MailHog** (contenedor del `docker-compose.yml`, SMTP `1025`, UI `8025`). En producción hay que apuntar a un proveedor real.

**Recomendado: Resend** (driver `resend` ya soportado por Laravel; dominio propio en Cloudflare facilita el SPF/DKIM). Alternativas: Mailgun, Amazon SES, SendGrid, o SMTP de Gmail/Google Workspace.

### Opción A — Resend (recomendada)
1. [ ] Crear cuenta en [resend.com](https://resend.com) → *API Keys* → generar una.
2. [x] *Domains* → verificado el **subdominio** `mail.cocos-francisco.com` (DKIM/SPF/MX en verde en Cloudflare). El `from` debe usar ese subdominio.
3. [x] `resend/resend-php` ya instalado (`composer.json`) — el transporte `resend` ya está en `config/mail.php`.
4. [ ] Variables en `.env` de producción:
   ```env
   MAIL_MAILER=resend
   RESEND_KEY=re_xxxxxxxxxxxxxxxxx
   MAIL_FROM_ADDRESS="no-reply@mail.cocos-francisco.com"
   MAIL_FROM_NAME="GCStock"
   ```

### Opción B — SMTP genérico (Mailgun/SES/Gmail/Workspace)
1. [ ] Conseguir host/puerto/usuario/contraseña del proveedor (en Gmail/Workspace: una **App Password**, no la contraseña normal).
2. [ ] Verificar el dominio en el proveedor y crear sus **SPF/DKIM (TXT)** en Cloudflare DNS (Fase 10).
3. [ ] Variables en `.env` de producción:
   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.tu-proveedor.com
   MAIL_PORT=587
   MAIL_SCHEME=smtp           # STARTTLS automático en 587; "smtps" para el 465. "tls"/"ssl" NO son válidos en Laravel 12
   MAIL_USERNAME=<usuario>
   MAIL_PASSWORD=<password / app-password>
   MAIL_FROM_ADDRESS="no-reply@mail.cocos-francisco.com"
   MAIL_FROM_NAME="GCStock"
   ```

### Verificación (cualquier opción)
1. [ ] Tras editar `.env`: `php artisan config:cache`.
2. [ ] Probar el envío real desde el VPS:
   ```bash
   php artisan tinker
   >>> Mail::raw('Prueba de correo prod', fn($m) => $m->to('TU_CORREO@gmail.com')->subject('Test GCStock'));
   ```
   Debe llegar a la bandeja (revisa spam). Si falla, mira el log del día en `storage/logs/` (`laravel-AAAA-MM-DD.log` con `LOG_STACK=daily`).
3. [ ] Probar un **flujo real**: solicitar reset de contraseña (OTP) y hacer un **cierre de caja** → que llegue el correo con el **PDF adjunto**.

> **Salida de SMTP en el VPS:** Contabo no suele bloquear el puerto **587/465** saliente, pero algunos proveedores VPS sí. Si los correos no salen, prueba el otro puerto o usa la API HTTPS del proveedor (Resend/Mailgun por API evita el puerto SMTP por completo).

> **DNS anti-spam (clave para que no caigan en spam):** crea en Cloudflare (Fase 10) los **SPF**, **DKIM** y un **DMARC** básico (`v=DMARC1; p=none; rua=mailto:postmaster@cocos-francisco.com`). Sin esto, Gmail/Outlook marcan o rechazan.

---

## Fase 8 — (Opcional) Almacenamiento en R2

Para el beta el disco local del VPS (80 GB) sirve. Si quieres sacar imágenes/PDF del servidor:

1. [ ] Crear bucket en Cloudflare R2 + token S3.
2. [ ] **Requiere código:** instalar `league/flysystem-aws-s3-v3` (no está en `composer.json`) y cambiar los `Storage::disk('public')` del código por un disco configurable. Cambiar solo `FILESYSTEM_DISK=s3` no mueve las imágenes y, sin el paquete, rompe la app.
3. [ ] `.env`: las claves `AWS_*` apuntando al endpoint de R2; `php artisan config:cache`.
4. [ ] Verificar subidas y apertura de PDFs.

---

## Fase 9 — Frontend (Cloudflare Pages)

El frontend no depende de cómo se monte el backend: sigue la **Fase 9 de `DEPLOY-COOLIFY.md`** (Cloudflare Pages: `npm run build:web`, salida `dist`, Node 22 de `.node-version`, la lista completa de variables `EXPO_PUBLIC_*`, `public/_headers`, `_redirects` y las páginas de privacidad) y la **Fase 9.5** para las apps nativas (EAS y `MIN_NATIVE_APP_VERSION`).

> Desactualizado y ya corregido allá: el build command `npx expo export -p web` y las variables `EXPO_PUBLIC_APP_ENV` / `APP_NAME` / `APP_VERSION` que listaba esta fase. Sin `EXPO_PUBLIC_API_URL` el build de Pages ahora se detiene en vez de apuntar a `localhost`.

---

## Fase 10 — DNS

Cloudflare DNS (el dominio ya está en Cloudflare, solo creas registros):
- [ ] `api.cocos-francisco.com` → registro **A** a la IP del VPS. Para emitir el cert de Certbot ponlo en **DNS only** (nube gris); luego puedes proxearlo y usar SSL mode **Full (strict)**.
- [ ] `app.cocos-francisco.com` → el registro que Pages crea al añadir el *Custom domain* en el proyecto.
- [ ] **Correo (Fase 7.5):** los **TXT de SPF y DKIM** que dé tu proveedor (Resend/Mailgun/SES) + un **DMARC** TXT en `_dmarc.cocos-francisco.com`. Estos van **DNS only** (no se proxean). Sin ellos los correos caen en spam.

---

## Fase 11 — Respaldos (NO opcional)

Script `/home/deploy/backup.sh`:
```bash
#!/bin/bash
set -e
DATE=$(date +%F)
mysqldump -u plastigest -p'CONTRASEÑA_FUERTE' plastigest | gzip > /home/deploy/backups/plastigest-$DATE.sql.gz
# Archivos subidos (imágenes, evidencias, recibos) y el JSON de Firebase
tar czf /home/deploy/backups/storage-$DATE.tar.gz -C /var/www/plastigest/storage app
# Subir a R2/S3 (rclone configurado con el token S3 de R2), y borrar locales > 14 días
rclone copy /home/deploy/backups r2:plastigest-backups/ --include "*-$DATE.*"
find /home/deploy/backups -name "*.gz" -mtime +14 -delete
```
```bash
mkdir -p /home/deploy/backups
chmod +x /home/deploy/backup.sh
crontab -e
# agregar:  0 3 * * * /home/deploy/backup.sh
```
- [ ] Configurar `rclone`/`aws` para subir a R2 (no dejes el único respaldo en el mismo servidor).
- [ ] **Probar una restauración** al menos una vez.

---

## Fase 12 — Actualizaciones futuras (deploy manual)

Solo despliega commits de `main` con el CI en verde (workflow `Tests`: pruebas en MySQL 8.4 + imagen Nixpacks), y si traen migraciones hazlo fuera del horario de las sucursales siguiendo las reglas de expandir y contraer (Fase 16 de `DEPLOY-COOLIFY.md`): entre el `git pull` y el fin de `migrate`, el código nuevo corre con el esquema viejo.

Script `/var/www/plastigest/deploy.sh`:
```bash
#!/bin/bash
set -e
cd /var/www/plastigest
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart plastigest-worker
```
Cada actualización: `bash deploy.sh`.

---

## Fase 13 — Prueba de humo antes de abrir a usuarios

Es la misma que con Coolify: primero el **primer día en la app** (Fase 13 de `DEPLOY-COOLIFY.md`: datos fiscales, roles desde las plantillas, usuarios por sucursal, una caja por sucursal, conteo inicial y notificaciones) y luego la **prueba de humo completa** (Fase 14 de `DEPLOY-COOLIFY.md`): turno, venta en el punto de venta con protección contra doble venta, kiosco, devolución en efectivo, cierre de turno, transferencia con "Pedir a", conteo con "Correcto", compra parcial, producción con responsable, reportes en PDF/Excel, push y aislamiento entre sucursales. Aquí los errores se buscan en `storage/logs/` en vez de en los Logs de Coolify.

---

## Fase 14 — Go-live checklist

- [ ] Pendientes del dueño de `DEPLOY-COOLIFY.md` hechos (token de WhatsApp revocado, repos privados, contraseña vieja descartada) y CI en verde para el commit desplegado.
- [ ] `APP_DEBUG=false`.
- [x] CORS restringido al dominio de la web (no `*`) — en código; solo falta `FRONTEND_URL`.
- [ ] `plastigest-worker` activo (`systemctl status`) y cron del scheduler puesto.
- [ ] **Correo en producción funcionando** (Fase 7.5): SPF/DKIM/DMARC en DNS y prueba real de reset de contraseña y cierre de caja con PDF; `ADMIN_EMAIL` es un buzón que alguien lee (recibe las alertas del sistema).
- [ ] Desde fuera solo responden 22, 80 y 443 (`nmap`); MySQL sin puerto público.
- [ ] MySQL con el buffer pool ajustado y `slow_query_log` (Fase 3).
- [ ] Logs diarios en `storage/logs/` y la alerta de prueba recibida (Fase 7.6 de `DEPLOY-COOLIFY.md`).
- [ ] Backup diario **probado** (restauración verificada, fuera del servidor).
- [x] Fix de doble-venta (servidor + app) — en código; llega con el deploy.
- [ ] Plan de internet de respaldo por sucursal (POS web = sin internet, sin ventas).
- [ ] (Opcional) Web push: las `EXPO_PUBLIC_FIREBASE_*` y `EXPO_PUBLIC_FIREBASE_VAPID_KEY` en Pages; el service worker `firebase-messaging-sw.js` ya está en el frontend. Se puede lanzar el beta sin esto.

---

## Costo mensual

| Concepto | Costo |
|---|---|
| Hetzner CPX21 (3 vCPU / 4 GB / 80 GB) | ~€12.49 |
| Cloudflare Pages / DNS / SSL | $0 |
| Cloudflare R2 (backups, <10 GB) | $0 |
| Firebase push (plan Spark) | $0 |
| Dominio | ~$1 |

**Total: ~€12.49 + dominio ≈ $14-15/mes.** Sin Forge, el costo recurrente es prácticamente solo el VPS. A cambio, la provisión, los deploys, parches de seguridad y SSL los administras tú (este runbook cubre todo).

---

### Cadencia estimada

Fases 1-6 (backend en línea con cola y scheduler) ≈ medio día la primera vez. Fase 9-10 (frontend + DNS) ≈ 1-2 h. El resto es pruebas y respaldos. El cuello de botella operativo no será el servidor, sino el internet de cada sucursal y los respaldos: cuida esos dos.

---

## Qué falta (estado a 2026-06-19, desactualizado)

> **Desactualizado.** El estado vigente, con los pendientes del dueño y el orden de salida, está en "Estado al 15-oct-2026 y pendientes del dueño" de `DEPLOY-COOLIFY.md`. Esta lista queda como historial del plan sobre el VPS de Contabo; el fix de doble-venta, los `console.log` y la elección de seeders ya están resueltos en código.

**Hecho**
- [x] VPS aprovisionado (`vps3451515.trouble-free.net`, Contabo).
- [x] **Dominio** `cocos-francisco.com` comprado en **Cloudflare Registrar** (DNS ya en Cloudflare).

**Bloqueante antes de seguir**
- [ ] Confirmar que el VPS es **Ubuntu 24.04** (`lsb_release -a`).
- [ ] Elegir **proveedor de correo** (Resend recomendado) y crear sus DNS — bloqueante para reset de contraseña y cierre de caja (Fase 7.5).

**Servidor (Fases 1-6) — todo pendiente**
- [ ] Endurecer SO: usuario `deploy` con sudo, firewall UFW (22/80/443), deshabilitar login root por contraseña.
- [ ] Instalar stack: Nginx, PHP 8.4 + extensiones, MySQL, Composer, Git.
- [ ] Crear BD `plastigest` + usuario.
- [ ] Clonar repo, `composer install --no-dev`, `.env` de producción, `key:generate`.
- [ ] Subir el **JSON de Firebase** por `scp` (nunca al repo) y apuntar `FIREBASE_CREDENTIALS`.
- [ ] `migrate --force` + `db:seed --class=CompaniesSeeder --force` + `db:seed --force` (con `ADMIN_PASSWORD`), `storage:link`, permisos `www-data`, `config/route/view:cache`.
- [ ] Entrar como Super Admin y cambiar la contraseña; quitar `ADMIN_PASSWORD` del `.env`.
- [ ] Nginx server block (con `client_max_body_size 30M` y los `X-Forwarded-*` vaciados) + Certbot SSL.
- [ ] systemd `plastigest-worker` (cola, `--queue=notifications,default`) + cron del scheduler.

**Frontend / DNS (Fases 9-10)**
- [ ] Cloudflare Pages conectado a `plastigest-app-v3` con `EXPO_PUBLIC_*` de producción.
- [ ] Registros A (`api.`) y CNAME de Pages (`app.`) en Cloudflare DNS.

**Correo (Fase 7.5) — pendiente**
- [ ] Proveedor SMTP/API elegido y configurado (`MAIL_*` / `RESEND_KEY` en `.env`).
- [ ] SPF + DKIM + DMARC creados en Cloudflare DNS y verificados por el proveedor.
- [ ] Prueba real: reset de contraseña (OTP) y cierre de caja con PDF adjunto llegan a la bandeja.

**Operación / go-live (Fases 11-14)**
- [ ] Backups con `mysqldump` + `storage/app` + subida a R2/S3 y **restauración probada**.
- [ ] `APP_DEBUG=false`, CORS restringido al dominio real (no `*`).
- [ ] Verificar **fix de doble-venta** desplegado y `console.log` de debug limpiados.
- [ ] Prueba de humo completa (Fase 13).

**Decisiones resueltas**
- ✅ **Dominio:** `cocos-francisco.com` (comprado en Cloudflare). Ya aplicado a `APP_URL`, `FRONTEND_URL`, CORS, certs y vars del frontend en todo el plan.
- ✅ **Correo:** sí requiere proveedor en producción (los envíos van por la cola y los manda el worker; ver Fase 7.5). Recomendado **Resend**; falta solo crear la cuenta, la API key y los DNS.
