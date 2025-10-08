<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class MakeCrudController extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud {name} {--model=} {--resource=} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new CRUD controller, model and resource. Usage: make:crud Product or make:crud Admin/Users';

    /**
     * The filesystem instance.
     */
    protected $files;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $input = $this->argument('name');

        // Parse the input to extract names and namespace
        $names = $this->parseInput($input);

        $controllerName = $names['controller'];
        $modelName = $this->option('model') ?: $names['model'];
        $resourceName = $this->option('resource') ?: $names['resource'];
        $namespace = $names['namespace'];
        $controllerPath = $names['controller_path'];
        $resourcePath = $names['resource_path'];

        $this->info("Creating CRUD for: {$modelName}");
        $this->line("Controller: {$controllerName} (Namespace: {$namespace})");
        $this->line("Model: {$modelName}");
        $this->line("Resource: {$resourceName}");
        $this->line("");

        // Crear el modelo si no existe
        $this->createModel($modelName, $resourcePath);

        // Crear el resource si no existe
        $this->createResource($resourceName, $resourcePath, $names['resource_namespace'], $modelName);

        // Crear el controlador
        $this->createController($controllerName, $controllerPath, $namespace, $modelName, $resourceName, $names['resource_namespace']);

        $this->showImplementationExample($controllerName, $modelName, $resourceName, $namespace);

        return 0;
    }

    /**
     * Parse the input to extract controller, model, resource names and namespaces
     */
    protected function parseInput(string $input): array
    {
        // Si contiene "/" es un namespace (ej: Admin/Users)
        if (str_contains($input, '/')) {
            $parts = explode('/', $input);
            $modelName = end($parts);
            $namespaceParts = array_slice($parts, 0, -1);
            $namespace = 'App\\Http\\Controllers\\' . implode('\\', $namespaceParts);
            $controllerPath = implode('/', $namespaceParts);

            // Resource namespace
            $resourceNamespace = 'App\\Http\\Resources\\' . implode('\\', $namespaceParts);
            $resourcePath = implode('/', $namespaceParts);
        } else {
            // Si termina en Controller, extraer el nombre del modelo
            if (str_ends_with($input, 'Controller')) {
                $modelName = str_replace('Controller', '', $input);
            } else {
                $modelName = $input;
            }

            $namespace = 'App\\Http\\Controllers';
            $controllerPath = '';
            $resourceNamespace = 'App\\Http\\Resources';
            $resourcePath = '';
        }

        return [
            'controller' => $modelName . 'Controller',
            'model' => $modelName,
            'resource' => $modelName . 'Resource',
            'namespace' => $namespace,
            'controller_path' => $controllerPath,
            'resource_namespace' => $resourceNamespace,
            'resource_path' => $resourcePath,
        ];
    }

    /**
     * Create model if it doesn't exist
     */
    protected function createModel(string $modelName, string $resourcePath): void
    {
        $modelPath = app_path("Models/" . ($resourcePath ? $resourcePath . '/' : '') . "{$modelName}.php");

        if (!$this->files->exists($modelPath)) {
            $this->call('make:model', ['name' => ($resourcePath ? $resourcePath . '/' : '') . $modelName]);
            $this->info("✓ Model {$modelName} created");
        } else {
            $this->line("→ Model {$modelName} already exists");
        }
    }

    /**
     * Create resource if it doesn't exist
     */
    protected function createResource(string $resourceName, string $resourcePath, string $resourceNamespace, string $modelName): void
    {
        $fullResourcePath = app_path("Http/Resources/" . ($resourcePath ? $resourcePath . '/' : '') . "{$resourceName}.php");

        if (!$this->files->exists($fullResourcePath)) {
            // Generar el contenido del resource personalizado
            $content = $this->generateResourceContent($resourceName, $resourceNamespace, $modelName);

            $this->makeDirectory($fullResourcePath);
            $this->files->put($fullResourcePath, $content);

            $this->info("✓ Resource {$resourceName} created");
        } else {
            $this->line("→ Resource {$resourceName} already exists");
        }
    }

    /**
     * Generar el contenido del resource
     */
    protected function generateResourceContent(string $resourceName, string $resourceNamespace, string $modelName): string
    {
        $stub = $this->getResourceStub();

        return str_replace([
            '{{ namespace }}',
            '{{ class }}',
            '{{ model }}',
            '{{ modelVariable }}',
        ], [
            $resourceNamespace,
            $resourceName,
            $modelName,
            Str::camel($modelName),
        ], $stub);
    }

    /**
     * Obtener el stub del resource
     */
    protected function getResourceStub(): string
    {
        return '<?php

namespace {{ namespace }};

use App\Http\Resources\Resources;
use App\Models\{{ model }};
use Illuminate\Database\Eloquent\Model;

class {{ class }} extends Resources
{
    /**
     * Format the resource data
     *
     * @param {{ model }} $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext(\'editing\', false);

        $item = [
            \'id\' => $resource->id,
            // Agregar aquí los campos básicos del modelo
            // Ejemplo:
            // \'name\' => $resource->name,
            // \'description\' => $resource->description,
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item[\'is_active\'] = $resource->is_active ?? true;
            $item[\'created_at\'] = $resource->created_at?->toISOString();
            $item[\'updated_at\'] = $resource->updated_at?->toISOString();
        }

        // Ejemplo de manejo de relaciones
        // if ($resource->relationLoaded(\'relatedModel\')) {
        //     if (!$editing) {
        //         // Para index: datos simples
        //         $item[\'related_name\'] = $resource->relatedModel?->name;
        //     } else {
        //         // Para show/edit: datos completos
        //         $item[\'related_model\'] = $resource->relatedModel;
        //     }
        // }

        return $item;
    }
}
';
    }

    /**
     * Create controller
     */
    protected function createController(string $controllerName, string $controllerPath, string $namespace, string $modelName, string $resourceName, string $resourceNamespace): void
    {
        $fullControllerPath = app_path("Http/Controllers/" . ($controllerPath ? $controllerPath . '/' : '') . "{$controllerName}.php");

        if ($this->files->exists($fullControllerPath) && !$this->option('force')) {
            $this->error("Controller {$controllerName} already exists! Use --force to overwrite");
            return;
        }

        // Generar el contenido del controlador
        $content = $this->generateControllerContent($controllerName, $namespace, $modelName, $resourceName, $resourceNamespace);

        $this->makeDirectory($fullControllerPath);
        $this->files->put($fullControllerPath, $content);

        $this->info("✓ Controller {$controllerName} created");
    }

    /**
     * Generar el contenido del controlador
     */
    protected function generateControllerContent($controllerName, $namespace, $modelName, $resourceName, $resourceNamespace): string
    {
        $stub = $this->getStub();

        return str_replace([
            '{{ namespace }}',
            '{{ class }}',
            '{{ model }}',
            '{{ resource }}',
            '{{ resourceNamespace }}',
            '{{ modelVariable }}',
        ], [
            $namespace,
            $controllerName,
            $modelName,
            $resourceName,
            $resourceNamespace,
            Str::camel($modelName),
        ], $stub);
    }

    /**
     * Obtener el stub del controlador actualizado
     */
    protected function getStub(): string
    {
        return '<?php

namespace {{ namespace }};

use App\Http\Controllers\CrudController;
use {{ resourceNamespace }}\{{ resource }};
use App\Models\{{ model }};
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class {{ class }} extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = {{ resource }}::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = {{ model }}::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el índice
            // Ejemplo: \'category\', \'unit\'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el show
            // Ejemplo: \'category\', \'unit\', \'suppliers\'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Implementar filtros específicos del modelo
        // Ejemplo:
        // if (isset($params[\'category_id\'])) {
        //     $query->where(\'category_id\', $params[\'category_id\']);
        // }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            // Agregar aquí las reglas de validación para crear
            // Ejemplo:
            // \'name\' => \'required|string|max:255\',
            // \'email\' => \'required|email|unique:{{ modelVariable }}s,email\',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            // Agregar aquí las reglas de validación para actualizar
            // Ejemplo:
            // \'name\' => \'required|string|max:255\',
            // \'email\' => \'required|email|unique:{{ modelVariable }}s,email,\' . $model->id,
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Procesar datos antes de crear si es necesario
        // Ejemplo: agregar company_id del usuario autenticado
        // $validatedData[\'company_id\'] = auth()->user()->company_id;

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Procesar datos antes de actualizar si es necesario
        // Ejemplo: no permitir cambiar company_id
        // unset($validatedData[\'company_id\']);

        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras
     */
    protected function process($callback, array $data, $method = \'create\'): Model
    {
        try {
            DB::beginTransaction();

            $model = $callback($data);

            // Aquí puedes agregar lógica adicional específica del modelo
            // Ejemplo: manejar relaciones, archivos, etc.

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Acciones después de crear (opcional)
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Lógica adicional después de crear
        // Ejemplo: crear relaciones, enviar notificaciones, etc.
    }

    /**
     * Acciones después de actualizar (opcional)
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Lógica adicional después de actualizar
        // Ejemplo: sincronizar relaciones, logs, etc.
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // Validaciones para eliminar
        // Ejemplo:
        // if ($model->orders()->exists()) {
        //     return [
        //         \'can_delete\' => false,
        //         \'message\' => \'No se puede eliminar porque tiene órdenes asociadas\'
        //     ];
        // }

        return [
            \'can_delete\' => true,
            \'message\' => \'\'
        ];
    }
}
';
    }

    /**
     * Crear el directorio si no existe
     */
    protected function makeDirectory($path): void
    {
        if (!$this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0755, true);
        }
    }

    /**
     * Mostrar ejemplo de implementación
     */
    protected function showImplementationExample($controllerName, $modelName, $resourceName, $namespace): void
    {
        $routeName = Str::kebab($modelName);
        $controllerClass = str_replace('App\\Http\\Controllers\\', '', $namespace) . '\\' . $controllerName;
        $controllerClass = ltrim($controllerClass, '\\');

        $this->line('');
        $this->info('✅ CRUD generated successfully!');
        $this->line('');
        $this->info('Files created:');
        $this->line("• Controller: {$namespace}\\{$controllerName}");
        $this->line("• Resource: {$resourceName} (extends Resources)");
        $this->line("• Model: {$modelName}");
        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Add routes to your routes/api.php:");
        $this->line("   Route::apiResource('{$routeName}', {$controllerClass}::class);");
        $this->line('');
        $this->line("2. Implement the required methods in {$controllerName}:");
        $this->line("   • indexRelations() - Relations for index");
        $this->line("   • getShowRelations() - Relations for show");
        $this->line("   • handleQuery() - Custom filters");
        $this->line("   • validateStoreData() - Store validation");
        $this->line("   • validateUpdateData() - Update validation");
        $this->line('');
        $this->line("3. Customize the {$resourceName}:");
        $this->line("   • Add fields to formatter() method");
        $this->line("   • Handle relationships and context");
        $this->line('');
        $this->line("4. Add fillable fields to {$modelName} model");
        $this->line('');
        $this->info("API endpoints:");
        $this->line("GET    /api/{$routeName}     - List all");
        $this->line("POST   /api/{$routeName}     - Create new");
        $this->line("GET    /api/{$routeName}/1   - Show specific");
        $this->line("PUT    /api/{$routeName}/1   - Update");
        $this->line("DELETE /api/{$routeName}/1   - Delete");
    }
}
