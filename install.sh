#!/bin/bash

# 🚀 PlastiGest Backend - Script de Instalación Automatizada
# Autor: GitHub Copilot
# Fecha: $(date)

set -e  # Salir si cualquier comando falla

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Función para imprimir mensajes con colores
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_step() {
    echo -e "${BLUE}🔄 $1${NC}"
}

# Función para verificar si un comando existe
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Banner de bienvenida
echo -e "${BLUE}"
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                 🚀 PlastiGest Backend Setup 🚀                ║"
echo "║                   Instalación Automatizada                   ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Verificar prerrequisitos
print_step "Verificando prerrequisitos..."

if ! command_exists docker; then
    print_error "Docker no está instalado. Por favor instala Docker primero."
    exit 1
fi

if ! command_exists docker-compose; then
    if ! docker compose version >/dev/null 2>&1; then
        print_error "Docker Compose no está disponible. Por favor instala Docker Compose."
        exit 1
    fi
    DOCKER_COMPOSE="docker compose"
else
    DOCKER_COMPOSE="docker-compose"
fi

print_success "Docker está disponible"

# Verificar si estamos en el directorio correcto
if [ ! -f "composer.json" ] || [ ! -f "artisan" ]; then
    print_error "No estás en el directorio raíz del proyecto Laravel."
    print_error "Asegúrate de estar en el directorio plastigest-back/"
    exit 1
fi

print_success "Directorio del proyecto correcto"

# Paso 1: Configurar variables de entorno
print_step "1. Configurando variables de entorno..."

if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
        print_success "Archivo .env creado desde .env.example"
    else
        print_error "No se encontró .env.example"
        exit 1
    fi
else
    print_warning "El archivo .env ya existe, no se sobreescribirá"
fi

# Paso 2: Instalar dependencias de Composer
print_step "2. Instalando dependencias de Composer..."

if command_exists composer; then
    # Con dependencias de desarrollo: Sail (vendor/bin/sail) es de require-dev.
    # Este script es para desarrollo local; producción se instala en Coolify
    # (ver DEPLOY-COOLIFY.md).
    print_info "Usando Composer local..."
    composer install
else
    print_info "Usando Composer via Docker..."
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd):/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        composer install --ignore-platform-reqs
fi

print_success "Dependencias de Composer instaladas"

# Verificar que Sail esté disponible
if [ ! -f "./vendor/bin/sail" ]; then
    print_error "Laravel Sail no se instaló correctamente"
    exit 1
fi

# Crear alias para Sail
SAIL="./vendor/bin/sail"

# Paso 3: Iniciar Laravel Sail
print_step "3. Iniciando Laravel Sail..."

# Detener cualquier contenedor previo
$SAIL down >/dev/null 2>&1 || true

# Iniciar contenedores
$SAIL up -d

# Esperar a que los contenedores estén listos
print_info "Esperando a que los contenedores estén listos..."
sleep 10

# Verificar que MySQL esté listo
print_step "Verificando conexión a MySQL..."
for i in {1..30}; do
    if $SAIL mysql -e "SELECT 1" >/dev/null 2>&1; then
        print_success "MySQL está listo"
        break
    fi
    if [ $i -eq 30 ]; then
        print_error "MySQL no está disponible después de 30 intentos"
        exit 1
    fi
    sleep 2
done

# Paso 4: Generar clave de aplicación (sail artisan necesita los contenedores arriba)
print_step "4. Generando clave de aplicación..."

# Verificar si ya existe una clave
if grep -q "APP_KEY=" .env && [ -n "$(grep "APP_KEY=" .env | cut -d'=' -f2)" ]; then
    print_warning "APP_KEY ya existe, omitiendo generación"
else
    $SAIL artisan key:generate --force
    print_success "Clave de aplicación generada"
fi

# Paso 5: Ejecutar migraciones
print_step "5. Ejecutando migraciones..."

$SAIL artisan migrate --force
print_success "Migraciones ejecutadas"

# Paso 6: Datos de desarrollo
print_step "6. Cargando datos de desarrollo (db:seed)..."

# En local DatabaseSeeder crea permisos, unidades, la empresa con sus
# sucursales, el Super Admin (ADMIN_EMAIL / ADMIN_PASSWORD del .env; sin
# ADMIN_PASSWORD usa "password", solo en local) y el catálogo.
$SAIL artisan db:seed

print_success "Datos de prueba creados"

# Paso 7: Verificar instalación
print_step "7. Verificando instalación..."

# Verificar que la aplicación responda
sleep 5
if curl -s http://localhost/api/health-check >/dev/null 2>&1; then
    print_success "Aplicación responde correctamente"
elif curl -s http://localhost >/dev/null 2>&1; then
    print_success "Aplicación disponible en http://localhost"
else
    print_warning "No se pudo verificar la respuesta de la aplicación"
    print_info "Esto podría ser normal si no hay una ruta de health-check"
fi

# Mostrar información final
echo -e "\n${GREEN}"
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                    🎉 ¡Instalación Completa! 🎉              ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "${BLUE}📋 Información de Acceso:${NC}"
echo "🌐 URL: http://localhost"
echo "📧 Admin: ADMIN_EMAIL del .env (por defecto admin@cocos-francisco.com)"
echo "🔑 Contraseña: ADMIN_PASSWORD del .env (sin definir: \"password\", solo en local)"
echo "🏢 Compañía: Cocos Francisco (8 sucursales)"

echo -e "\n${BLUE}🔧 Comandos útiles:${NC}"
echo "• Ver logs: $SAIL logs"
echo "• Detener: $SAIL down"
echo "• Reiniciar: $SAIL restart"
echo "• Artisan: $SAIL artisan [comando]"
echo "• MySQL: $SAIL mysql"
echo "• Tinker: $SAIL tinker"

echo -e "\n${BLUE}🧪 Probar la API:${NC}"
echo "curl -X POST http://localhost/api/auth/login \\"
echo "  -H \"Content-Type: application/json\" \\"
echo "  -H \"Accept: application/json\" \\"
echo "  -d '{\"email\":\"admin@cocos-francisco.com\",\"password\":\"password\"}'"

echo -e "\n${BLUE}📚 Documentación:${NC}"
echo "• Instalación: ./INSTALLATION.md"
echo "• Backend CRUD: ./docs/BACKEND_CRUD_GUIDE.md"
echo "• Instrucciones Copilot: ./.copilot-instructions.md"

echo -e "\n${GREEN}¡Listo para desarrollar con PlastiGest! 🚀${NC}"

# Verificar el estado final de los contenedores
echo -e "\n${BLUE}📊 Estado de Contenedores:${NC}"
$SAIL ps
