<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;

abstract class CrudController extends Controller
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model;

    /**
     * Columna de fecha principal del recurso.
     * Se usa como destino de los filtros date_from/date_to y start_date/end_date.
     * Estándar: date_from/date_to → filtro por esta columna.
     * Alias soportado: start_date/end_date apunta a la misma columna.
     * Sobrescribir en clases hijas para usar la fecha de dominio correcta.
     * Ejemplos: 'sale_date', 'movement_date', 'purchase_date'
     */
    protected string $dateColumn = 'created_at';

    /**
     * Prefijo del recurso para verificación de permisos.
     * Si se define, los métodos CRUD verificarán automáticamente que el usuario
     * tenga el permiso correspondiente antes de ejecutar la acción.
     *
     * Formato esperado: nombre del recurso en snake_case (ej. 'products', 'sales').
     * Permisos generados: {prefix}_list, {prefix}_create, {prefix}_update,
     *                     {prefix}_delete, {prefix}_read
     *
     * Dejar en null para deshabilitar las verificaciones automáticas (retrocompatibilidad).
     */
    protected ?string $permissionPrefix = null;

    /**
     * Columna de empresa del recurso. Se usa para aislar los datos por empresa
     * (tenant) en index/show/update/destroy. Si el modelo no tiene esta columna
     * (p. ej. permisos, usuarios, la propia compañía) se pone en null y la
     * clase hija resuelve el aislamiento por su cuenta.
     */
    protected ?string $companyColumn = 'company_id';

    /**
     * Columna de sucursal del recurso. Cuando está definida, los usuarios que
     * NO pueden ver todas las sucursales (no son administradores) solo ven los
     * registros de su sucursal actual. Los administradores (system_admin o
     * locations_all, ver CurrentWorker::canAccessAllLocations()) ven todas las
     * sucursales de la empresa. null = el recurso no se filtra por
     * sucursal (catálogos de empresa como productos, clientes, etc.).
     */
    protected ?string $locationColumn = null;

    /**
     * Tope de per_page en las listas paginadas. Sin tope, per_page=100000
     * traía la tabla completa en una sola respuesta.
     */
    protected int $maxPerPage = 100;

    /**
     * Tope de renglones de las listas sin paginar: all=true (selects) y las
     * peticiones sin paginated ni per_page, que varias pantallas usan para
     * traer la lista completa (productos de un conteo, sucursales, roles…).
     * Antes no había tope y una tabla que crece se devolvía completa.
     */
    protected int $maxUnpaginated = 1000;

    /**
     * Columnas por las que se puede ordenar (sort_by), además de id, name,
     * created_at, updated_at y $dateColumn cuando la tabla las tiene. Antes
     * sort_by iba directo a orderBy: una columna inexistente respondía 500 y
     * se podía ordenar por cualquier columna (sin índice o privada).
     *
     * @var string[]
     */
    protected array $sortable = [];

    /**
     * Cache de existencia de columnas por tabla, para no consultar el esquema
     * en cada petición.
     *
     * @var array<string,bool>
     */
    private static array $columnCache = [];

    /**
     * Relaciones que se cargarán en el index
     * Debe ser implementado por las clases hijas
     */
    abstract protected function indexRelations(): array;

    // ─── Aislamiento por empresa/sucursal (tenant) ────────────────────────────

    /**
     * ¿La tabla del modelo tiene la columna indicada? (cacheado)
     */
    protected function modelHasColumn(string $column): bool
    {
        $table = (new $this->model)->getTable();
        $key = $table . '.' . $column;

        return self::$columnCache[$key] ??= Schema::hasColumn($table, $column);
    }

    /**
     * Empresa del registro nuevo. Si la petición no la trae, se usa la del
     * contexto (antes formularios como Categorías fallaban con "company_id es
     * obligatorio"). Si trae otra, el usuario tiene que pertenecer a ella:
     * antes se podía crear un registro en cualquier empresa mandando su id.
     */
    protected function resolveStoreCompany(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $column = $this->companyColumn;
        if (!$column || !$this->modelHasColumn($column)) {
            return null;
        }

        // Sin empresa en el contexto no se crea nada: el company_id quedaba
        // como lo mandara el cliente. SetCurrentCompany ya lo corta antes.
        if (!($current = CurrentCompany::id())) {
            return $this->companyRequiredResponse();
        }

        $requested = $request->input($column);
        if ($requested === null || $requested === '') {
            $request->merge([$column => $current]);

            return null;
        }

        if (is_numeric($requested) && (int) $requested !== (int) $current
            && !CurrentWorker::canAccessCompany((int) $requested)) {
            return $this->forbiddenResponse('crear registros en otra empresa');
        }

        return null;
    }

    /**
     * Aplica el aislamiento por empresa y sucursal a una query del modelo.
     * Es el mecanismo central: index(), findModel() y findModelForShow() lo
     * usan, así que show/update/destroy de un id de otra empresa responden 404.
     */
    protected function applyTenantScope($query)
    {
        if ($this->companyColumn && $this->modelHasColumn($this->companyColumn)) {
            $companyId = CurrentCompany::id();

            if (!$companyId) {
                // Sin empresa en el contexto no se devuelve nada. Antes la
                // consulta salía sin filtro de empresa: todos los registros de
                // todas las empresas. SetCurrentCompany ya corta esas
                // peticiones; esto es la segunda barrera.
                $query->whereRaw('1 = 0');
            } else {
                // Los registros sin empresa son catálogos globales compartidos
                // (unidades, tipos de inventario): visibles para todas las
                // empresas. Los registros con empresa solo los ve esa empresa.
                $query->where(function ($q) use ($companyId) {
                    $q->where($this->companyColumn, $companyId)
                        ->orWhereNull($this->companyColumn);
                });
            }
        }

        if (
            $this->locationColumn
            && $this->modelHasColumn($this->locationColumn)
            && !CurrentWorker::canAccessAllLocations()
            && ($locationId = CurrentLocation::id())
        ) {
            $query->where($this->locationColumn, $locationId);
        }

        return $query;
    }

    /**
     * Query base del modelo ya aislada por empresa/sucursal.
     */
    protected function scopedQuery()
    {
        return $this->applyTenantScope($this->model::query());
    }

    /**
     * Manejo de filtros personalizados
     * Debe ser implementado por las clases hijas
     */
    abstract protected function handleQuery($query, array $params);

    // ─── Verificaciones de permisos ──────────────────────────────────────────

    /**
     * Verificar si el usuario actual puede listar el recurso.
     * Retorna null si no hay prefijo configurado (sin restricción).
     */
    protected function canIndex(): bool
    {
        if (!$this->permissionPrefix) {
            return true;
        }
        return CurrentWorker::hasPermission("{$this->permissionPrefix}_list");
    }

    /**
     * Verificar si el usuario actual puede crear el recurso.
     * Retorna true si no hay prefijo configurado (sin restricción).
     */
    protected function canStore(): bool
    {
        if (!$this->permissionPrefix) {
            return true;
        }
        return CurrentWorker::hasPermission("{$this->permissionPrefix}_create");
    }

    /**
     * Verificar si el usuario actual puede editar/actualizar el recurso.
     * Retorna true si no hay prefijo configurado (sin restricción).
     */
    protected function canEdit(): bool
    {
        if (!$this->permissionPrefix) {
            return true;
        }
        return CurrentWorker::hasPermission("{$this->permissionPrefix}_update");
    }

    /**
     * Verificar si el usuario actual puede eliminar el recurso.
     * Complementa al método canDelete() existente (que valida reglas de negocio).
     * Retorna true si no hay prefijo configurado (sin restricción).
     */
    protected function canDestroy(): bool
    {
        if (!$this->permissionPrefix) {
            return true;
        }
        return CurrentWorker::hasPermission("{$this->permissionPrefix}_delete");
    }

    /**
     * Verificar si el usuario actual puede ver un recurso individual.
     * Retorna true si no hay prefijo configurado (sin restricción).
     */
    protected function canRead(): bool
    {
        if (!$this->permissionPrefix) {
            return true;
        }
        return CurrentWorker::hasPermission("{$this->permissionPrefix}_read");
    }

    /**
     * Respuesta estándar de acceso denegado (403).
     */
    protected function forbiddenResponse(string $action = 'realizar esta acción')
    {
        return response()->json([
            'message' => "No tienes permiso para {$action}.",
        ], 403);
    }

    /**
     * Falta la empresa del contexto (X-Company-ID). Mismo `code` que
     * SetCurrentCompany: la app muestra el selector de empresa.
     */
    protected function companyRequiredResponse()
    {
        return response()->json([
            'message' => 'Selecciona una empresa para continuar.',
            'code' => 'company_required',
        ], 403);
    }

    /**
     * Mostrar lista de recursos con filtros, orden y paginación
     */
    public function index(Request $request)
    {
        if (!$this->canIndex()) {
            return $this->forbiddenResponse('listar este recurso');
        }

        // Obtener parámetros de la request
        $params = $request->all();

        // Crear query base del modelo, ya aislada por empresa/sucursal.
        $query = $this->scopedQuery();

        // Cargar relaciones definidas
        $relations = $this->indexRelations();
        if (!empty($relations)) {
            $query->with($relations);
        }

        // Aplicar filtros básicos
        $this->applyBasicFilters($query, $params);

        // Aplicar filtros personalizados (implementados por clases hijas)
        $this->handleQuery($query, $params);

        // Aplicar ordenamiento
        $this->applyOrdering($query, $params);

        // Obtener resultados paginados o todos
        $results = $this->getResults($query, $params);

        // Retornar usando el resource definido
        return $this->resource::collection($results);
    }

    /**
     * Aplicar filtros básicos comunes
     */
    protected function applyBasicFilters($query, array $params)
    {
        // Filtro por búsqueda general. Solo busca en las columnas que existen en
        // la tabla: muchas (permisos, roles, notificaciones, ventas…) no tienen
        // code/description y la búsqueda genérica respondía 500 ("Unknown column").
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            $columns = array_values(array_filter(
                ['name', 'code', 'description'],
                fn($column) => $this->modelHasColumn($column)
            ));

            if (!empty($columns)) {
                $query->where(function ($q) use ($search, $columns) {
                    foreach ($columns as $i => $column) {
                        $i === 0
                            ? $q->where($column, 'like', "%{$search}%")
                            : $q->orWhere($column, 'like', "%{$search}%");
                    }
                });
            }
        }

        // Filtro por estado activo/inactivo
        // Acepta booleanos reales, "true"/"false" como string, 1/0, etc.
        if (isset($params['is_active']) && $this->modelHasColumn('is_active')) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        // El filtro por empresa ya no se toma del cliente: lo aplica
        // applyTenantScope() con la empresa del contexto (X-Company-ID validado).

        // Filtro por fecha principal del recurso.
        // Acepta date_from/date_to (estándar) o start_date/end_date (alias para compatibilidad).
        $dateFrom = $params['date_from'] ?? $params['start_date'] ?? null;
        $dateTo   = $params['date_to']   ?? $params['end_date']   ?? null;

        if ($dateFrom) {
            $query->whereDate($this->dateColumn, '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate($this->dateColumn, '<=', $dateTo);
        }
    }

    /**
     * Aplicar ordenamiento
     */
    protected function applyOrdering($query, array $params)
    {
        // Solo columnas permitidas; cualquier otra ordena por id.
        $sortBy = $params['sort_by'] ?? 'id';
        if (!is_string($sortBy) || !in_array($sortBy, $this->sortableColumns(), true)) {
            $sortBy = 'id';
        }

        $sortDirection = $params['sort_direction'] ?? 'desc';

        // Validar dirección del ordenamiento
        if (!is_string($sortDirection) || !in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        // Con la tabla: una consulta con joins no se vuelve ambigua.
        $query->orderBy($query->getModel()->getTable() . '.' . $sortBy, $sortDirection);
    }

    /**
     * Columnas válidas para sort_by (ver $sortable).
     *
     * @return string[]
     */
    protected function sortableColumns(): array
    {
        $columns = array_unique(['id', 'name', 'created_at', 'updated_at', $this->dateColumn, ...$this->sortable]);

        return array_values(array_filter($columns, fn($column) => $this->modelHasColumn($column)));
    }

    /**
     * Obtener resultados (paginados o todos)
     */
    protected function getResults($query, array $params)
    {
        $paginated = filter_var($params['paginated'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $perPage = is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : 0;

        // paginated=true (15 por página si no manda per_page) o per_page sin
        // paginated (compatibilidad); siempre con tope.
        if ($paginated || $perPage > 0) {
            return $query->paginate(min($perPage > 0 ? $perPage : 15, $this->maxPerPage));
        }

        // Sin paginar (all=true o sin per_page): la lista completa, con tope.
        $results = $query->limit($this->maxUnpaginated)->get();

        if ($results->count() >= $this->maxUnpaginated) {
            Log::warning('Lista sin paginar recortada al tope', [
                'controller' => static::class,
                'max' => $this->maxUnpaginated,
            ]);
        }

        return $results;
    }

    /**
     * Mostrar un recurso específico
     */
    public function show($id)
    {
        if (!$this->canRead()) {
            return $this->forbiddenResponse('ver este recurso');
        }

        try {
            // Buscar el modelo
            $model = $this->findModelForShow($id);

            if (!$model) {
                return response()->json([
                    'message' => 'Recurso no encontrado'
                ], 404);
            }

            // Procesar después de encontrar (para lógica adicional)
            $this->afterShow($model);

            // Retornar el recurso
            return new $this->resource($model, ['editing' => true]);
        } catch (\Exception $e) {
            return $this->errorResponse($e, 'Error al obtener el recurso');
        }
    }

    /**
     * Buscar modelo por ID para show (con relaciones)
     */
    protected function findModelForShow($id): ?Model
    {
        // Aislada por empresa/sucursal: un id de otra empresa responde 404.
        $query = $this->scopedQuery();

        // Cargar relaciones si están definidas
        $relations = $this->getShowRelations();
        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    /**
     * Acciones después de encontrar el modelo en show - puede ser sobrescrito por clases hijas
     */
    protected function afterShow(Model $model): void
    {
        // Por defecto no hace nada, las clases hijas pueden implementar lógica adicional
        // Ej: incrementar contadores de visualización, logs, etc.
    }

    /**
     * Almacenar un nuevo recurso
     */
    public function store(Request $request)
    {
        if (!$this->canStore()) {
            return $this->forbiddenResponse('crear este recurso');
        }

        if ($denied = $this->resolveStoreCompany($request)) {
            return $denied;
        }

        try {
            // Validar los datos de entrada
            $validatedData = $this->validateStoreData($request);

            // Procesar los datos antes de crear (implementado por clases hijas)
            $processedData = $this->processStoreData($validatedData, $request);

            // Crear el modelo
            $model = $this->process(fn($data) => $this->create($data), $processedData, 'create');

            // Cargar relaciones si están definidas
            $relations = $this->getShowRelations();
            if (!empty($relations)) {
                $model->load($relations);
            }

            // Procesar después de crear (para relaciones adicionales, etc.)
            $this->afterStore($model, $request);

            // afterStore es justo donde se escriben las relaciones (imágenes,
            // ingredientes, material de empaque…), así que hay que releerlas:
            // las cargadas arriba son de antes y saldrían vacías.
            if (!empty($relations)) {
                $model->load($relations);
            }

            // Retornar el recurso creado
            return new $this->resource($model, ['editing' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e, 'Error al crear el recurso');
        }
    }


    /**
     * Crear un nuevo recurso (alternativa a store)
     */
    public function create(array $data): Model
    {
        return $this->model::create($data);
    }

    public function edit(array $data, int $id): Model
    {
        $model = $this->findModel($id);
        if (!$model) {
            throw new \Exception('Recurso no encontrado');
        }

        $model->update($data);
        return $model;
    }

    /**
     * Validación para store - debe ser implementado por clases hijas
     */
    abstract protected function validateStoreData(Request $request): array;

    /**
     * Procesar datos antes de crear - puede ser sobrescrito por clases hijas
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        return $validatedData;
    }

    /**
     * Relaciones que se cargarán en show y después de crear
     * Debe ser implementado por las clases hijas
     */
    abstract protected function getShowRelations(): array;

    /**
     * Acciones después de crear - puede ser sobrescrito por clases hijas
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Por defecto no hace nada, las clases hijas pueden implementar lógica adicional
    }

    /**
     * Actualizar un recurso existente
     */
    public function update(Request $request, $id)
    {
        if (!$this->canEdit()) {
            return $this->forbiddenResponse('editar este recurso');
        }

        try {
            // Buscar el modelo
            $model = $this->findModel($id);

            if (!$model) {
                return response()->json([
                    'message' => 'Recurso no encontrado'
                ], 404);
            }

            // Validar los datos de entrada
            $validatedData = $this->validateUpdateData($request, $model);

            // Procesar los datos antes de actualizar
            $processedData = $this->processUpdateData($validatedData, $request, $model);

            // Actualizar el modelo
            $model = $this->process(fn($data) => $this->edit($data, $id), $processedData, 'update');

            // Cargar relaciones si están definidas
            $relations = $this->getShowRelations();
            if (!empty($relations)) {
                $model->load($relations);
            }

            // Procesar después de actualizar
            $this->afterUpdate($model, $request);

            // Igual que en store: afterUpdate reescribe las relaciones, así que
            // se releen para no devolver el estado anterior.
            if (!empty($relations)) {
                $model->load($relations);
            }

            // Retornar el recurso actualizado
            return new $this->resource($model, ['editing' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e, 'Error al actualizar el recurso');
        }
    }

    /**
     * Buscar modelo por ID
     */
    protected function findModel($id): ?Model
    {
        // Aislada por empresa/sucursal: update/destroy de un id de otra empresa
        // responde 404.
        return $this->scopedQuery()->find($id);
    }

    /**
     * Validación para update - debe ser implementado por clases hijas
     */
    abstract protected function validateUpdateData(Request $request, Model $model): array;

    /**
     * Procesar datos antes de actualizar - puede ser sobrescrito por clases hijas
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        return $validatedData;
    }

    protected function process($callback, array $data, $method): Model
    {
        return $callback($data);
    }

    /**
     * Acciones después de actualizar - puede ser sobrescrito por clases hijas
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Por defecto no hace nada, las clases hijas pueden implementar lógica adicional
    }

    /**
     * Eliminar un recurso
     */
    public function destroy($id)
    {
        if (!$this->canDestroy()) {
            return $this->forbiddenResponse('eliminar este recurso');
        }

        try {
            // Buscar el modelo
            $model = $this->findModel($id);

            if (!$model) {
                return response()->json([
                    'message' => 'Recurso no encontrado'
                ], 404);
            }

            // Validar si se puede eliminar
            $canDelete = $this->canDelete($model);
            if (!$canDelete['can_delete']) {
                return response()->json([
                    'message' => $canDelete['message']
                ], 422);
            }

            // Procesar antes de eliminar
            $this->beforeDestroy($model);

            // Eliminar el modelo
            $deleted = $model->delete();

            if (!$deleted) {
                return response()->json([
                    'message' => 'Error al eliminar el recurso'
                ], 500);
            }

            // Procesar después de eliminar
            $this->afterDestroy($model);

            // Retornar respuesta exitosa
            return response()->json([
                'message' => 'Recurso eliminado exitosamente'
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            // Una llave foránea lo impide: el registro está en uso (productos
            // con esa categoría, compras de ese proveedor, stock en la
            // sucursal…). Antes salía un 500 genérico sin explicación.
            if (($e->errorInfo[1] ?? null) === 1451 || $e->getCode() === '23000') {
                return response()->json([
                    'message' => 'No se puede eliminar porque está en uso. Puedes desactivarlo en su lugar.',
                ], 422);
            }

            return $this->errorResponse($e, 'Error al eliminar el recurso');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 'Error al eliminar el recurso');
        }
    }

    /**
     * Validar si el modelo se puede eliminar - puede ser sobrescrito por clases hijas
     */
    protected function canDelete(Model $model): array
    {
        // Por defecto permite eliminar
        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Acciones antes de eliminar - puede ser sobrescrito por clases hijas
     */
    protected function beforeDestroy(Model $model): void
    {
        // Por defecto no hace nada, las clases hijas pueden implementar lógica adicional
        // Ej: crear respaldos, validaciones adicionales, etc.
    }

    /**
     * Acciones después de eliminar - puede ser sobrescrito por clases hijas
     */
    protected function afterDestroy(Model $model): void
    {
        // Por defecto no hace nada, las clases hijas pueden implementar lógica adicional
        // Ej: limpiar archivos, logs, notificaciones, etc.
    }
}
