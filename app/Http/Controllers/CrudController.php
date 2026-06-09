<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
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
     * Relaciones que se cargarán en el index
     * Debe ser implementado por las clases hijas
     */
    abstract protected function indexRelations(): array;

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
     * Mostrar lista de recursos con filtros, orden y paginación
     */
    public function index(Request $request)
    {
        if (!$this->canIndex()) {
            return $this->forbiddenResponse('listar este recurso');
        }

        // Obtener parámetros de la request
        $params = $request->all();

        // Crear query base del modelo
        $query = $this->model::query();

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
        // Filtro por búsqueda general
        if (isset($params['search']) && !empty($params['search'])) {
            $search = $params['search'];
            // Buscar en campos comunes (name, code, description)
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filtro por estado activo/inactivo
        // Acepta booleanos reales, "true"/"false" como string, 1/0, etc.
        if (isset($params['is_active'])) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        // Filtro por company_id si existe
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

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
        $sortBy = $params['sort_by'] ?? 'id';
        $sortDirection = $params['sort_direction'] ?? 'desc';

        // Validar dirección del ordenamiento
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * Obtener resultados (paginados o todos)
     */
    protected function getResults($query, array $params)
    {
        // Si se especifica explícitamente que se quiere paginación
        if (isset($params['paginated']) && $params['paginated'] == true) {
            $perPage = $params['per_page'] ?? 15;
            return $query->paginate($perPage);
        }

        // Si se especifica per_page sin paginated=true, también paginar (compatibilidad)
        if (isset($params['per_page']) && $params['per_page'] > 0) {
            return $query->paginate($params['per_page']);
        }

        // Por defecto, retornar todos los resultados sin paginación
        return $query->get();
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
            return response()->json([
                'message' => 'Error al obtener el recurso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar modelo por ID para show (con relaciones)
     */
    protected function findModelForShow($id): ?Model
    {
        $query = $this->model::query();

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

            // Retornar el recurso creado
            return new $this->resource($model, ['editing' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el recurso',
                'error' => $e->getMessage()
            ], 500);
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

            // Retornar el recurso actualizado
            return new $this->resource($model, ['editing' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el recurso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar modelo por ID
     */
    protected function findModel($id): ?Model
    {
        return $this->model::find($id);
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
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el recurso',
                'error' => $e->getMessage()
            ], 500);
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
