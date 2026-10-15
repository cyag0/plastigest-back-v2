# 🚀 PlastiGest Backend - Guía de Instalación

## 📋 Comandos para Clonar e Instalar el Proyecto

### 1. **Clonar el Repositorio**
```bash
git clone https://github.com/cyag0/plastigest-back-v2.git
cd plastigest-back-v2
```

### 2. **Configurar Variables de Entorno**
```bash
# Copiar el archivo de ejemplo
cp .env.example .env

# Editar las variables necesarias
nano .env
```

**Configuración mínima necesaria en `.env`:**
```env
APP_NAME="PlastiGest Backend"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

# Base de datos
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=plastigest
DB_USERNAME=sail
DB_PASSWORD=password

# Configuración de Sail
MYSQL_ROOT_PASSWORD=password
MYSQL_DATABASE=plastigest
MYSQL_USER=sail
MYSQL_PASSWORD=password
MYSQL_ALLOW_EMPTY_PASSWORD=1
```

### 3. **Instalar Dependencias de Composer**
```bash
# Si NO tienes Composer instalado localmente, usar Docker:
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# Si SÍ tienes Composer instalado localmente:
composer install
```

### 4. **Generar Clave de Aplicación**
```bash
# Con Sail (recomendado)
./vendor/bin/sail artisan key:generate

# O con PHP local
php artisan key:generate
```

### 5. **Iniciar Laravel Sail**
```bash
# Levantar los contenedores en segundo plano
./vendor/bin/sail up -d

# Verificar que los contenedores estén corriendo
./vendor/bin/sail ps
```

### 6. **Ejecutar Migraciones**
```bash
# Ejecutar todas las migraciones
./vendor/bin/sail artisan migrate

# Si quieres datos de prueba (opcional)
./vendor/bin/sail artisan db:seed
```

### 7. **Verificar Instalación**
```bash
# Verificar que la aplicación esté funcionando
curl http://localhost/api/auth/login

# O abrir en navegador
# http://localhost
```

---

## 🔧 Comandos Útiles de Sail

### **Gestión de Contenedores**
```bash
# Iniciar contenedores
./vendor/bin/sail up -d

# Detener contenedores
./vendor/bin/sail down

# Ver logs
./vendor/bin/sail logs

# Ver estado de contenedores
./vendor/bin/sail ps
```

### **Comandos Artisan**
```bash
# Ejecutar comandos artisan
./vendor/bin/sail artisan [comando]

# Ejemplos específicos:
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:crud Product
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan tinker
```

### **Base de Datos**
```bash
# Conectar a MySQL
./vendor/bin/sail mysql

# Resetear base de datos
./vendor/bin/sail artisan migrate:fresh

# Con seeders
./vendor/bin/sail artisan migrate:fresh --seed
```

### **Composer**
```bash
./vendor/bin/sail composer [comando]
```

---

## 🧪 Crear Datos de Prueba

### **1. Crear Usuario Administrador**
```bash
./vendor/bin/sail artisan tinker
```

```php
// En tinker:
$user = App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@plastigest.com',
    'password' => bcrypt('password123')
]);

$company = App\Models\Admin\Company::create([
    'name' => 'PlastiGest Demo',
    'business_name' => 'PlastiGest Demo S.A. de C.V.',
    'rfc' => 'DEMO123456789',
    'address' => 'Calle Demo 123, Ciudad Demo',
    'phone' => '+52 555 123 4567',
    'email' => 'demo@plastigest.com',
    'is_active' => true
]);

echo "Usuario creado: ID {$user->id}";
echo "Compañía creada: ID {$company->id}";
exit
```

### **2. Probar API de Autenticación**
```bash
# Hacer login
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@plastigest.com","password":"password123"}'

# Respuesta esperada:
# {"message":"Inicio de sesión exitoso","access_token":"...","user":{...}}
```

### **3. Probar CRUD de Workers**
```bash
# Usando el token obtenido del login
export TOKEN="tu_token_aqui"

# Listar workers
curl -H "Authorization: Bearer $TOKEN" \
     -H "Accept: application/json" \
     http://localhost/api/auth/admin/workers

# Crear worker
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{"company_id":1,"user_id":1,"employee_number":"EMP001","position":"Developer"}' \
     http://localhost/api/auth/admin/workers
```

---

## 📁 Estructura de Archivos Importantes

```
plastigest-back-v2/
├── .env.example                    # Variables de entorno de ejemplo
├── .copilot-instructions.md        # Instrucciones para GitHub Copilot
├── docker-compose.yml              # Configuración de Sail
├── composer.json                   # Dependencias de PHP
├── artisan                        # CLI de Laravel
├── app/
│   ├── Console/Commands/
│   │   └── MakeCrudController.php  # Comando personalizado make:crud
│   ├── Http/Controllers/
│   │   ├── CrudController.php      # Controlador base
│   │   └── Admin/                  # Controladores administrativos
│   ├── Models/Admin/               # Modelos del sistema
│   └── Http/Resources/             # Transformadores de API
├── database/
│   └── migrations/                 # Migraciones de base de datos
├── docs/                          # Documentación del proyecto
├── routes/
│   └── api.php                    # Rutas de la API
└── vendor/bin/sail                # Ejecutable de Laravel Sail
```

---

## ⚠️ Troubleshooting Común

### **Puerto 80 Ocupado**
```bash
# Si el puerto 80 está ocupado, cambiar en docker-compose.yml:
# "8080:80" en lugar de "80:80"

# Luego acceder a: http://localhost:8080
```

### **Problemas de Permisos**
```bash
# Dar permisos a directorios de Laravel
sudo chown -R $USER:$USER storage bootstrap/cache
chmod -R 755 storage bootstrap/cache
```

### **MySQL no Inicia**
```bash
# Verificar logs de MySQL
./vendor/bin/sail logs mysql

# Resetear contenedores
./vendor/bin/sail down -v
./vendor/bin/sail up -d
```

### **Composer Memory Limit**
```bash
# Si falla por memoria, aumentar límite:
php -d memory_limit=2G artisan [comando]

# O usar Docker para Composer
docker run --rm -v "$(pwd):/app" composer install
```

---

## 🚀 Desarrollo Post-Instalación

### **Crear Nuevos CRUDs**
```bash
# Generar CRUD completo automáticamente
./vendor/bin/sail artisan make:crud Product
./vendor/bin/sail artisan make:crud Admin/Customer

# Crear migración
./vendor/bin/sail artisan make:migration create_products_table

# Ejecutar migración
./vendor/bin/sail artisan migrate
```

### **Probar APIs**
```bash
# Ver todas las rutas disponibles
./vendor/bin/sail artisan route:list

# Filtrar por admin
./vendor/bin/sail artisan route:list | grep admin
```

---

## 📖 Recursos de Documentación

Una vez instalado, revisar estos archivos para entender el sistema:

- 📄 **`.copilot-instructions.md`** - Patrones de desarrollo
- 📄 **`docs/BACKEND_CRUD_GUIDE.md`** - Guía completa de CRUDs  
- 📄 **`docs/WORKERS_CRUD_EXAMPLE.md`** - Ejemplo funcional
- 📄 **`docs/FINAL_IMPLEMENTATION_SUMMARY.md`** - Resumen del sistema

---

## ✅ Checklist de Instalación Exitosa

- [ ] ✅ Repositorio clonado
- [ ] ✅ `.env` configurado
- [ ] ✅ Dependencias instaladas (`composer install`)
- [ ] ✅ Clave de aplicación generada (`key:generate`)
- [ ] ✅ Sail iniciado (`sail up -d`)
- [ ] ✅ Migraciones ejecutadas (`migrate`)
- [ ] ✅ Usuario de prueba creado
- [ ] ✅ Login API funcional
- [ ] ✅ CRUD Workers probado
- [ ] ✅ Documentación revisada

**¡Listo para desarrollar con PlastiGest!** 🚀
