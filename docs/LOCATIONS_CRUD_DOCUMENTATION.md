# CRUD de Sucursales (Locations) - Documentación

## 📋 Resumen de Implementación

Se ha creado un CRUD completo para la gestión de sucursales que permite a las compañías administrar múltiples locaciones de forma eficiente.

## 🛠️ Backend Implementado

### 1. **Migración de Base de Datos**

-   ✅ Columna `company_id` agregada a la tabla `locations`
-   ✅ Foreign key constraint con `companies` tabla
-   ✅ Índice compuesto para optimizar consultas por compañía y estado

### 2. **Modelo Location** (`/app/Models/Admin/Location.php`)

-   ✅ Relación `belongsTo` con Company
-   ✅ Campos fillable actualizados: `name`, `description`, `address`, `phone`, `email`, `company_id`
-   ✅ Relación cargada automáticamente

### 3. **LocationController** (`/app/Http/Controllers/Admin/LocationController.php`)

-   ✅ Extiende `CrudController` base
-   ✅ Validaciones completas para store y update
-   ✅ Filtros personalizados por tipo, código postal y ciudad
-   ✅ Relaciones cargadas: `company`
-   ✅ Procesamiento de datos con `is_active` por defecto

### 4. **LocationResource** (`/app/Http/Resources/Admin/LocationResource.php`)

-   ✅ Formateo contextual (index vs editing)
-   ✅ Datos de compañía incluidos según contexto
-   ✅ Campos: `id`, `name`, `description`, `address`, `phone`, `email`, `company_id`, `is_active`

## 🎨 Frontend Implementado

### 1. **Servicio** (`/utils/services/admin/locations/`)

-   ✅ Servicio CRUD completo usando `createCrudService`
-   ✅ Tipado con `App.Entities.Location`
-   ✅ Endpoint: `/auth/admin/locations`

### 2. **Tipos TypeScript** (`/utils/services/types.d.ts`)

-   ✅ Interfaz `Location` actualizada con campos correctos
-   ✅ Soporte para relación con `Company`
-   ✅ Campos opcionales manejados correctamente

### 3. **Pantallas Creadas**

#### **Index** (`/app/(tabs)/home/locations/index.tsx`)

-   ✅ Lista usando `AppList` component
-   ✅ Filtro automático por `company_id` usando `useSelectedCompany`
-   ✅ Cards con información detallada: dirección, teléfono, email
-   ✅ Avatar personalizado y chips de estado
-   ✅ Navegación a detalles y formulario

#### **Formulario** (`/app/(tabs)/home/locations/form.tsx`)

-   ✅ Formulario usando `AppForm` con `FormInput` components
-   ✅ Validación completa con Yup schema
-   ✅ `company_id` automático desde `useSelectedCompany`
-   ✅ Campo de compañía oculto pero informativo
-   ✅ Soporte para crear y editar
-   ✅ Switch para estado activo/inactivo

#### **Detalles** (`/app/(tabs)/home/locations/[id].tsx`)

-   ✅ Vista detallada con cards organizadas
-   ✅ Información de contacto, compañía y metadata
-   ✅ Botones de editar y eliminar
-   ✅ Confirmación de eliminación
-   ✅ Estados de loading y error

#### **Layout** (`/app/(tabs)/home/locations/_layout.tsx`)

-   ✅ Stack navigation configurado
-   ✅ Headers deshabilitados (usa AppBar custom)

## 🔧 Características Principales

### **Integración con Compañías**

-   🏢 **Relación 1:N**: Una compañía puede tener múltiples sucursales
-   🎯 **Filtro Automático**: Solo muestra sucursales de la compañía seleccionada
-   🔒 **Campo Oculto**: `company_id` se asigna automáticamente
-   📋 **Información Contextual**: Muestra datos de la compañía propietaria

### **UX/UI Consistente**

-   🎨 **Paleta de Colores**: Usa `palette.ts` para colores consistentes
-   📱 **Componentes Reutilizables**: `AppList`, `AppForm`, `AppBar`
-   ⚡ **Estados de Loading**: Indicadores visuales apropiados
-   ✅ **Validación**: Mensajes de error claros y validación en tiempo real

### **Funcionalidades Avanzadas**

-   🔍 **Búsqueda**: Por nombre, dirección y otros campos
-   🏷️ **Estados**: Activa/Inactiva con indicadores visuales
-   📊 **Información Completa**: Contacto, ubicación y metadata
-   🗑️ **Eliminación Segura**: Con confirmación

## 📁 Estructura de Archivos

```
Backend:
├── app/Models/Admin/Location.php
├── app/Http/Controllers/Admin/LocationController.php
├── app/Http/Resources/Admin/LocationResource.php
└── database/migrations/..._add_company_id_to_locations_table.php

Frontend:
├── app/(tabs)/home/locations/
│   ├── _layout.tsx
│   ├── index.tsx
│   ├── form.tsx
│   └── [id].tsx
├── utils/services/admin/locations/index.ts
├── utils/services/types.d.ts (actualizado)
└── hooks/useSelectedCompany.ts (usado)
```

## 🚀 Cómo Usar

1. **Seleccionar Compañía**: El sistema requiere una compañía seleccionada
2. **Listar Sucursales**: Automáticamente filtra por la compañía actual
3. **Crear Sucursal**: Formulario completo con validación
4. **Editar/Ver**: Acceso desde la lista o navegación directa
5. **Eliminar**: Con confirmación de seguridad

## 🔐 Seguridad y Validaciones

-   ✅ **Backend**: Validación de datos y foreign keys
-   ✅ **Frontend**: Esquemas de validación con Yup
-   ✅ **Autorización**: Solo sucursales de la compañía seleccionada
-   ✅ **Integridad**: Prevención de eliminación si hay dependencias

El CRUD está completamente funcional y listo para usar en producción! 🎉
