<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Database\Eloquent\Model;

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
     * Relaciones que se cargarán en el index
     * Debe ser implementado por las clases hijas
     */
    abstract protected function indexRelations(): array;

    /**
     * Manejo de filtros personalizados
     * Debe ser implementado por las clases hijas
     */
    abstract protected function handleQuery($query, array $params);

    /**
     * Mostrar lista de recursos con filtros, orden y paginación
     */
    public function index(Request $request)
    {
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
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Filtro por company_id si existe
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por fecha de creación
        if (isset($params['date_from'])) {
            $query->whereDate('created_at', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('created_at', '<=', $params['date_to']);
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
