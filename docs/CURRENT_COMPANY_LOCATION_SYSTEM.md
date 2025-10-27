# Sistema de CurrentCompany y CurrentLocation

## Descripción

Este sistema proporciona una forma global de acceder a la empresa y sucursal actuales en toda la aplicación Laravel, utilizando headers HTTP para establecer el contexto y middlewares para procesarlos automáticamente.

## Componentes

### 1. Clases de Soporte

#### CurrentCompany (`app/Support/CurrentCompany.php`)
- Maneja el estado global de la empresa actual
- Utiliza propiedades estáticas para almacenar la instancia
- Métodos principales:
  - `set(Company $company)`: Establece la empresa actual
  - `get(): ?Company`: Obtiene la empresa actual
  - `id(): ?int`: Obtiene el ID de la empresa actual
  - `clear()`: Limpia la empresa actual

#### CurrentLocation (`app/Support/CurrentLocation.php`)
- Maneja el estado global de la sucursal actual
- Funcionalidad similar a CurrentCompany
- Métodos adicionales:
  - `name(): ?string`: Obtiene el nombre de la sucursal
  - `address(): ?string`: Obtiene la dirección de la sucursal

### 2. Middlewares

#### SetCurrentCompany (`app/Http/Middleware/SetCurrentCompany.php`)
- Procesa el header `X-Company-ID`
- Busca y establece la empresa automáticamente
- Se ejecuta en cada request de la API

#### SetCurrentLocation (`app/Http/Middleware/SetCurrentLocation.php`)
- Procesa el header `X-Location-ID`
- Busca y establece la sucursal automáticamente
- Se ejecuta en cada request de la API

### 3. Funciones Helper Globales (`app/Support/helpers.php`)

Funciones de acceso rápido:
- `current_company(): ?Company`
- `current_company_id(): ?int`
- `current_location(): ?Location`
- `current_location_id(): ?int`
- `set_current_company(?Company $company): void`
- `set_current_location(?Location $location): void`
- `clear_current_company(): void`
- `clear_current_location(): void`

## Uso

### En el Frontend

Enviar los headers en cada request:

```javascript
// axios config
const axiosInstance = axios.create({
  headers: {
    'X-Company-ID': currentCompanyId,
    'X-Location-ID': currentLocationId,
  }
});
```

### En el Backend (Controladores)

```php
// Acceder a la empresa actual
$currentCompany = current_company();
$currentCompanyId = current_company_id();

// Acceder a la sucursal actual
$currentLocation = current_location();
$currentLocationId = current_location_id();

// Usar en validaciones
if (!current_company_id()) {
    $rules['company_id'] = 'required|exists:companies,id';
} else {
    $rules['company_id'] = 'nullable|exists:companies,id';
}

// Usar en procesamientos
$validatedData['company_id'] = $request->input('company_id') ?? current_company_id();
```

### En Modelos

```php
// En un método de modelo
public function activateInAllLocations($companyId = null)
{
    $companyId = $companyId ?? current_company_id();
    // ... lógica del método
}
```

## Ventajas

1. **Simplicidad**: Una sola fuente de verdad para el contexto actual
2. **Automatización**: Los middlewares procesan automáticamente los headers
3. **Flexibilidad**: Se puede usar tanto con headers como programáticamente
4. **Consistencia**: Mismo patrón en toda la aplicación
5. **Rendimiento**: Evita múltiples consultas a la base de datos

## Configuración

Los middlewares están registrados en `bootstrap/app.php`:

```php
$middleware->api(prepend: [
    \Illuminate\Http\Middleware\HandleCors::class,
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \App\Http\Middleware\SetCurrentCompany::class,
    \App\Http\Middleware\SetCurrentLocation::class,
]);
```

Las funciones helper están registradas en `composer.json`:

```json
"autoload": {
    "files": [
        "app/Support/helpers.php"
    ]
}
```

## Ejemplo de Implementación en ProductController

El `ProductController` ha sido actualizado para usar este sistema:

1. **Validación flexible**: Si hay una empresa actual, no requiere `company_id` en el request
2. **Procesamiento automático**: Usa la empresa actual si no se especifica una
3. **Activación de ubicaciones**: Usa la ubicación actual como fallback

```php
// En validateStoreData()
if (!current_company_id()) {
    $rules['company_id'] = 'required|exists:companies,id';
} else {
    $rules['company_id'] = 'nullable|exists:companies,id';
}

// En processStoreData()
if (!$companyId && current_company_id()) {
    $companyId = current_company_id();
}

// En handleLocationActivation()
if (!$currentLocationId && current_location_id()) {
    $currentLocationId = current_location_id();
}
```

## Consideraciones

1. **Estado por Request**: El estado se resetea en cada nuevo request HTTP
2. **Headers Opcionales**: Los middlewares funcionan aunque no se envíen los headers
3. **Validación**: Los middlewares validan que los IDs correspondan a registros existentes
4. **Thread Safety**: Usar solo en contexto de request HTTP (no en jobs en background)

## Próximos Pasos

1. Aplicar este patrón a otros controladores (SupplierController, etc.)
2. Crear tests unitarios para los middlewares y clases de soporte
3. Documentar el uso en el frontend para los desarrolladores
4. Considerar caché para mejorar rendimiento si es necesario
