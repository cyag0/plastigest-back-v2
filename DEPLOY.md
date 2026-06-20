# Plan de despliegue — PlastiGest (web, sin Forge)

Runbook para sacar el beta a producción montando el servidor **a mano** sobre un VPS. Stack detectado:

- **Backend:** Laravel 12, PHP 8.2+, Sanctum (token Bearer), cola y caché en `database`, scheduler (`tasks:generate-recurring` diario 06:00), PDFs con dompdf, push con Firebase (kreait).
- **Frontend:** Expo 54 / Expo Router (web `output: "static"`). API por `EXPO_PUBLIC_API_URL`.
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
- [ ] Tener el **JSON de credenciales de Firebase** (service account) que ya usas en dev.
- [ ] **Commit + push del fix de doble-venta** y limpiar los `console.log` de debug en `inventory/products/form.tsx`.
- [ ] Decidir qué seeders corren en producción (Fase 4): solo datos de referencia (permisos, unidades), **no** datos demo de productos.

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
5. [ ] Salir y volver a entrar como `deploy`: `ssh deploy@IP`. (Opcional pero recomendado: deshabilitar login root y por contraseña en `/etc/ssh/sshd_config`.)

---

## Fase 2 — Instalar el stack (Nginx, PHP 8.3, MySQL, Composer)

```bash
# Nginx
sudo apt install -y nginx

# PHP 8.3 + extensiones que necesita el proyecto
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl

# MySQL
sudo apt install -y mysql-server
sudo mysql_secure_installation     # pon contraseña root y responde "Y" a los hardening

# Composer (global)
cd ~ && curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Git
sudo apt install -y git
```

> dompdf usa `gd` (ya incluido arriba) para imágenes en PDF. Firebase (kreait) usa curl/openssl (ya disponibles).

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
2. [ ] Crear `.env`:
   ```bash
   cp .env.example .env
   php artisan key:generate
   nano .env
   ```
   Ajustes de producción:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.cocos-francisco.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=plastigest
   DB_USERNAME=plastigest
   DB_PASSWORD=CONTRASEÑA_FUERTE

   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   FILESYSTEM_DISK=local

   FRONTEND_URL=https://app.cocos-francisco.com

   # Firebase (ajusta al nombre real de la var que lee tu FirebaseService)
   FIREBASE_CREDENTIALS=/var/www/plastigest/storage/app/firebase/credentials.json
   ```
3. [ ] Subir el JSON de Firebase a esa ruta (con `scp` desde tu máquina):
   ```bash
   scp credentials.json deploy@IP:/var/www/plastigest/storage/app/firebase/credentials.json
   ```
   **Nunca lo commitees al repo.**
4. [ ] Migraciones, seeders de referencia y enlace de storage:
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=UnitsSeeder --force
   # SOLO los seeders de referencia que necesites; NO los de datos demo
   php artisan storage:link
   ```
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
7. [ ] Crear el primer usuario admin / empresa / 8 sucursales (seeder propio o `php artisan tinker`).

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

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       client_max_body_size 20M;   # subidas de imágenes de productos

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
       }

       location ~ /\.(?!well-known).* { deny all; }
   }
   ```
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
ExecStart=/usr/bin/php /var/www/plastigest/artisan queue:work --sleep=3 --tries=3 --max-time=3600
WorkingDirectory=/var/www/plastigest

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now plastigest-worker
sudo systemctl status plastigest-worker     # verificar que está "active (running)"
```

**Scheduler** vía cron. `crontab -e` (como `deploy`) y agregar:
```cron
* * * * * cd /var/www/plastigest && php artisan schedule:run >> /dev/null 2>&1
```
Esto dispara `tasks:generate-recurring` a las 06:00.

---

## Fase 7 — CORS

1. [ ] En `config/cors.php`, restringir el origen a la web:
   ```php
   'allowed_origins' => [env('FRONTEND_URL', 'https://app.cocos-francisco.com')],
   'allowed_headers' => ['*'],       // cubre Authorization, X-Company-ID, X-Location-ID
   'supports_credentials' => false,  // usamos token Bearer, no cookies
   ```
2. [ ] Commit + en el VPS: `git pull`, `php artisan config:cache`. (Cambios en config requieren re-cachear.)

---

## Fase 7.5 — Correo saliente (SMTP)

La app **sí manda correos** y hay que configurarlos: recuperación de contraseña con OTP (`PasswordResetMail`), notificaciones (`GenericNotificationMail`) y el **cierre de caja con PDF adjunto** (`CashClosingMail`, `CashClosingController@…`).

> ⚠️ **Importante:** estos correos se envían **sincrónicos** (`Mail::to(...)->send(...)`, no `->queue(...)`). Si el SMTP está mal o lento, la **petición HTTP se bloquea o falla** (p. ej. el cierre de caja). No basta con "dejarlo para después": sin `MAIL_*` válidos, esas acciones rompen en producción. (Mejora futura: pasar estos envíos a la cola con `->queue()`, ya hay worker en la Fase 6.)

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
   MAIL_SCHEME=tls            # o "ssl" para puerto 465
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
   Debe llegar a la bandeja (revisa spam). Si falla, mira `storage/logs/laravel.log`.
3. [ ] Probar un **flujo real**: solicitar reset de contraseña (OTP) y hacer un **cierre de caja** → que llegue el correo con el **PDF adjunto**.

> **Salida de SMTP en el VPS:** Contabo no suele bloquear el puerto **587/465** saliente, pero algunos proveedores VPS sí. Si los correos no salen, prueba el otro puerto o usa la API HTTPS del proveedor (Resend/Mailgun por API evita el puerto SMTP por completo).

> **DNS anti-spam (clave para que no caigan en spam):** crea en Cloudflare (Fase 10) los **SPF**, **DKIM** y un **DMARC** básico (`v=DMARC1; p=none; rua=mailto:postmaster@cocos-francisco.com`). Sin esto, Gmail/Outlook marcan o rechazan.

---

## Fase 8 — (Opcional) Almacenamiento en R2

Para el beta el disco local del VPS (80 GB) sirve. Si quieres sacar imágenes/PDF del servidor:

1. [ ] Crear bucket en Cloudflare R2 + token S3.
2. [ ] `.env`: `FILESYSTEM_DISK=s3` y las claves `AWS_*` apuntando al endpoint de R2; `php artisan config:cache`.
3. [ ] Verificar subidas y apertura de PDFs.

---

## Fase 9 — Frontend (Cloudflare Pages)

1. [ ] Cloudflare Dashboard → *Workers & Pages* → *Create* → *Pages* → conectar el repo `plastigest-app-v3`.
2. [ ] Build settings:
   - **Build command:** `npx expo export -p web`
   - **Output directory:** `dist`
3. [ ] Variables de entorno (`EXPO_PUBLIC_*` de producción; se **incrustan en el build**):
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
DATE=$(date +%F)
mysqldump -u plastigest -p'CONTRASEÑA_FUERTE' plastigest | gzip > /home/deploy/backups/plastigest-$DATE.sql.gz
# Subir a R2/S3 con rclone o aws-cli, y borrar locales > 14 días
find /home/deploy/backups -name "*.sql.gz" -mtime +14 -delete
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

Script `/var/www/plastigest/deploy.sh`:
```bash
#!/bin/bash
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

En `app.cocos-francisco.com`, ciclo real completo:

1. [ ] Login real → seleccionar empresa y sucursal.
2. [ ] **Vender** → confirmar que el **stock baja**.
3. [ ] Que la venta **entre a caja**.
4. [ ] **Cierre de caja** y que **cuadre**.
5. [ ] **Cancelar** una venta → el **stock regresa**.
6. [ ] Probar **ajuste de inventario** y **conteo semanal**.
7. [ ] Generar un **PDF** y que abra.
8. [ ] Desde **dos sucursales** confirmar que los stocks están aislados.

---

## Fase 14 — Go-live checklist

- [ ] `APP_DEBUG=false`.
- [ ] CORS restringido al dominio de la web (no `*`).
- [ ] `plastigest-worker` activo (`systemctl status`) y cron del scheduler puesto.
- [ ] **Correo en producción funcionando** (Fase 7.5): SPF/DKIM/DMARC en DNS y prueba real de reset de contraseña y cierre de caja con PDF.
- [ ] Backup diario **probado** (restauración verificada, fuera del servidor).
- [ ] Fix de doble-venta desplegado.
- [ ] Plan de internet de respaldo por sucursal (POS web = sin internet, sin ventas).
- [ ] (Opcional, fase 2) Web push: requiere `EXPO_PUBLIC_FIREBASE_VAPID_KEY` y un service worker `firebase-messaging-sw.js`. Se puede lanzar el beta sin esto.

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

## Qué falta (estado a 2026-06-19)

**Hecho**
- [x] VPS aprovisionado (`vps3451515.trouble-free.net`, Contabo).
- [x] **Dominio** `cocos-francisco.com` comprado en **Cloudflare Registrar** (DNS ya en Cloudflare).

**Bloqueante antes de seguir**
- [ ] Confirmar que el VPS es **Ubuntu 24.04** (`lsb_release -a`).
- [ ] Elegir **proveedor de correo** (Resend recomendado) y crear sus DNS — bloqueante para reset de contraseña y cierre de caja (Fase 7.5).

**Servidor (Fases 1-6) — todo pendiente**
- [ ] Endurecer SO: usuario `deploy` con sudo, firewall UFW (22/80/443), deshabilitar login root por contraseña.
- [ ] Instalar stack: Nginx, PHP 8.3 + extensiones, MySQL, Composer, Git.
- [ ] Crear BD `plastigest` + usuario.
- [ ] Clonar repo, `composer install --no-dev`, `.env` de producción, `key:generate`.
- [ ] Subir el **JSON de Firebase** por `scp` (nunca al repo) y apuntar `FIREBASE_CREDENTIALS`.
- [ ] `migrate --force` + seeders de **referencia** (Permission, Units), `storage:link`, permisos `www-data`, `config/route/view:cache`.
- [ ] Crear admin + empresa + 8 sucursales.
- [ ] Nginx server block + Certbot SSL.
- [ ] systemd `plastigest-worker` (cola) + cron del scheduler.

**Frontend / DNS (Fases 9-10)**
- [ ] Cloudflare Pages conectado a `plastigest-app-v3` con `EXPO_PUBLIC_*` de producción.
- [ ] Registros A (`api.`) y CNAME de Pages (`app.`) en Cloudflare DNS.

**Correo (Fase 7.5) — pendiente**
- [ ] Proveedor SMTP/API elegido y configurado (`MAIL_*` / `RESEND_KEY` en `.env`).
- [ ] SPF + DKIM + DMARC creados en Cloudflare DNS y verificados por el proveedor.
- [ ] Prueba real: reset de contraseña (OTP) y cierre de caja con PDF adjunto llegan a la bandeja.

**Operación / go-live (Fases 11-14)**
- [ ] Backups con `mysqldump` + subida a R2/S3 y **restauración probada**.
- [ ] `APP_DEBUG=false`, CORS restringido al dominio real (no `*`).
- [ ] Verificar **fix de doble-venta** desplegado y `console.log` de debug limpiados.
- [ ] Prueba de humo completa (Fase 13).

**Decisiones resueltas**
- ✅ **Dominio:** `cocos-francisco.com` (comprado en Cloudflare). Ya aplicado a `APP_URL`, `FRONTEND_URL`, CORS, certs y vars del frontend en todo el plan.
- ✅ **Correo:** sí requiere proveedor en producción (los envíos son síncronos; ver Fase 7.5). Recomendado **Resend**; falta solo crear la cuenta, la API key y los DNS.
