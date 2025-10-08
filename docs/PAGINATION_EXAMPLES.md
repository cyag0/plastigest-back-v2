// Ejemplo de uso de AppList con y sin paginación

// EJEMPLO 1: SIN PAGINACIÓN (comportamiento por defecto)
// Retorna todos los resultados sin paginar
<AppList
title="Usuarios"
service={Services.admin.users}
renderCard={({ item }) => ({
title: item.name,
description: item.email
})}
// usePagination={false} // Por defecto es false
/>

// EJEMPLO 2: CON PAGINACIÓN
// Solicita paginación al backend con parámetro paginated=true
<AppList
title="Compañías"
service={Services.admin.companies}
usePagination={true} // Habilitar paginación
itemsPerPage={10} // 10 elementos por página (default: 20)
renderCard={({ item }) => ({
title: item.name,
description: item.business_name
})}
/>

// CAMBIOS REALIZADOS:

// 1. BACKEND (CrudController.php):
// - getResults() ahora verifica parámetro 'paginated'
// - Si paginated=true, aplica paginación
// - Si no viene el parámetro, retorna todos los resultados

// 2. FRONTEND (AppList.tsx):
// - Nueva prop 'usePagination' (default: false)
// - Nueva prop 'itemsPerPage' (default: 20)
// - Solo envía parámetros de paginación si usePagination=true

// 3. TIPOS (crudService.ts):
// - Agregado 'paginated?: boolean' a IndexParams

// RESULTADO:
// - Por defecto: Sin paginación, retorna todos los resultados
// - Con usePagination=true: Solicita paginación al backend
// - Mantiene compatibilidad con código existente
