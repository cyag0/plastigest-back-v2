<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\InventoryCountResource;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\Task;
use App\Services\FirebaseService;
use App\Services\NotificationService;
use App\Services\TaskService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Barryvdh\DomPDF\Facade\Pdf;

class InventoryCountController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = InventoryCountResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = InventoryCount::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'location',
            'user',
            'details'

        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'location',
            'user',
            'details.product.unit',
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        $location = CurrentLocation::get();
        $locationId = $location ? $location->id : (isset($params['location_id']) ? $params['location_id'] : null);

        if (isset($params['location_id'])) {
            $query->where('location_id', $locationId);
        }

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['count_date_from'])) {
            $query->whereDate('count_date', '>=', $params['count_date_from']);
        }

        if (isset($params['count_date_to'])) {
            $query->whereDate('count_date', '<=', $params['count_date_to']);
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'count_date' => 'required|date',
            'location_id' => 'nullable|exists:locations,id',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'count_date' => 'sometimes|required|date',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'status' => 'sometimes|required|in:planning,counting,completed,cancelled',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Manejo unificado del proceso de creación/actualización
     * Este método maneja tanto store como update de forma unificada
     * Usa transacciones para operaciones seguras
     */
    protected function process($callback, array $data, $method = 'create'): Model
    {
        try {
            DB::beginTransaction();

            // Agregar el usuario autenticado y company_id
            if ($method === 'create') {
                $user = Auth::user();
                $company = CurrentCompany::get();
                $location = CurrentLocation::get();

                $data['user_id'] = $user->id;
                $data['company_id'] = $company->id;
                $data['location_id'] = $location?->id;
                $data['status'] = 'planning';

                $content = [
                    'products_count' => $location
                        ? Product::whereHas('locations', function ($q) use ($location) {
                            $q->where('location_id', $location->id);
                        })->count()
                        : 0,
                ];

                $data['content'] = $content;
            } else if ($method === 'update' && isset($data['status']) && $data['status'] === 'completed') {
                /** @var InventoryCount */
                $item = $callback($data);

                DB::commit();
                return $this->completeInventory($item);
            }

            // El callback ejecuta el store() o update() del modelo
            $model = $callback($data);

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function completeInventory(InventoryCount $inventoryCount)
    {
        try {
            DB::beginTransaction();

            // Obtener todos los productos de la ubicación del inventario
            $location = $inventoryCount->location;

            if (!$location) {
                throw new \Exception('El inventario no tiene una ubicación asignada');
            }

            // Obtener todos los detalles del inventario
            $details = $inventoryCount->details;

            $discrepancies = [];

            // Actualizar el stock en el pivote product_location solo cuando no coincidió el conteo
            foreach ($details as $detail) {
                // Solo actualizar si hubo diferencia (el conteo no coincidió con el sistema)
                if ($detail->difference != 0) {
                    // Registrar discrepancia
                    $discrepancies[] = [
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product->name,
                        'expected_quantity' => $detail->expected_quantity,
                        'counted_quantity' => $detail->counted_quantity,
                        'difference' => $detail->difference,
                    ];

                    // Actualizar el current_stock en la tabla pivote product_location
                    DB::table('product_location')
                        ->where('product_id', $detail->product_id)
                        ->where('location_id', $detail->location_id)
                        ->update([
                            'current_stock' => $detail->counted_quantity,
                            'updated_at' => now(),
                        ]);
                }
            }

            // Actualizar el estado del inventario a completado
            $inventoryCount->update([
                'status' => 'completed'
            ]);

            DB::commit();

            // Crear tarea para revisar discrepancias si las hay
            // La creación de la tarea ya envía notificación, no duplicar
            if (count($discrepancies) > 0) {
                $this->createDiscrepanciesTask($inventoryCount, $discrepancies);
            }

            // Verificar productos con stock bajo después de completar el inventario
            $this->checkLowStockAfterCount($inventoryCount->company_id, $location);

            return $inventoryCount;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Verificar productos con stock bajo después del conteo
     */
    protected function checkLowStockAfterCount($companyId, $location)
    {
        try {
            // Consultar productos donde current_stock < minimum_stock
            $lowStockProducts = DB::table('product_location')
                ->join('products', 'product_location.product_id', '=', 'products.id')
                ->where('product_location.location_id', $location->id)
                ->whereColumn('product_location.current_stock', '<', 'product_location.minimum_stock')
                ->where('product_location.active', true)
                ->select(
                    'products.id',
                    'products.name',
                    'products.code',
                    'product_location.current_stock',
                    'product_location.minimum_stock'
                )
                ->get()
                ->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'code' => $p->code,
                        'current_stock' => $p->current_stock,
                        'minimum_stock' => $p->minimum_stock,
                    ];
                })
                ->toArray();

            // Llamar al servicio de notificaciones centralizado
            NotificationService::notifyLowStockAfterCount(
                $companyId,
                $location->id,
                $location->name,
                $lowStockProducts
            );
        } catch (\Exception $e) {
            // No fallar el proceso de inventario si la notificación falla
            Log::error('Error checking low stock: ' . $e->getMessage());
        }
    }

    /**
     * Crear tarea para revisar discrepancias de inventario
     */
    protected function createDiscrepanciesTask(InventoryCount $inventoryCount, array $discrepancies)
    {
        try {
            $discrepanciesCount = count($discrepancies);

            $discrepanciesList = collect($discrepancies)->map(function ($disc) {
                $sign = $disc['difference'] > 0 ? '+' : '';
                return "- {$disc['product_name']}: {$sign}{$disc['difference']} (esperado: {$disc['expected_quantity']}, contado: {$disc['counted_quantity']})";
            })->take(10)->join("\n");

            $task = Task::create([
                'title' => "Revisar Discrepancias - Conteo #{$inventoryCount->id}",
                'description' => "Se encontraron {$discrepanciesCount} discrepancia(s) en el conteo '{$inventoryCount->name}'.\n\nProductos con diferencias:\n{$discrepanciesList}",
                'type' => 'stock_check',
                'priority' => $discrepanciesCount > 10 ? 'urgent' : 'high',
                'status' => 'pending',
                'due_date' => now()->addDay(),
                'company_id' => $inventoryCount->company_id,
                'location_id' => $inventoryCount->location_id,
                'assigned_to' => $inventoryCount->user_id,
                'assigned_users' => [],
                'is_recurring' => false,
            ]);

            Log::info('Discrepancies task created', [
                'task_id' => $task->id,
                'inventory_count_id' => $inventoryCount->id,
                'discrepancies_count' => $discrepanciesCount,
                'assigned_to' => $inventoryCount->user_id,
            ]);

            // 1. Notificar discrepancias encontradas
            NotificationService::notifyInventoryDiscrepancies(
                $inventoryCount->company_id,
                $inventoryCount->location_id,
                $inventoryCount->location->name,
                $inventoryCount->id,
                $inventoryCount->name,
                $inventoryCount->count_date,
                $discrepancies
            );

            // 2. Notificar asignación de tarea (solo al usuario asignado)
            app(TaskService::class)->notifyDiscrepanciesTaskCreated($task, $inventoryCount, $discrepancies);
        } catch (\Exception $e) {
            Log::error('Error creating discrepancies task', [
                'inventory_count_id' => $inventoryCount->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validar si se puede eliminar (opcional)
     */
    protected function canDelete(Model $model): array
    {
        // No permitir eliminar si ya está completado o en proceso
        if (in_array($model->status, ['completed', 'counting'])) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar un inventario completado o en proceso de conteo'
            ];
        }

        // No permitir eliminar si tiene detalles
        if ($model->details()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar un inventario con detalles registrados'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }

    /**
     * Generar URL firmada para el PDF
     */
    public function generatePdfUrl($id)
    {
        try {
            // Verificar que el inventario existe
            $inventoryCount = InventoryCount::findOrFail($id);

            // Generar URL firmada que expira en 1 hora
            $signedUrl = URL::temporarySignedRoute(
                'inventory-counts.pdf',
                now()->addHour(),
                ['id' => $id]
            );

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar URL del PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar PDF del conteo de inventario
     */
    public function generatePdf($id)
    {
        try {
            // Obtener el inventario con todas sus relaciones
            $inventoryCount = InventoryCount::with([
                'location',
                'user',
                'details.product.unit',
            ])->findOrFail($id);

            // Obtener la compañía actual
            $company = CurrentCompany::get();

            // Generar el PDF
            $pdf = Pdf::loadView('pdf.inventory-count', [
                'inventoryCount' => $inventoryCount,
                'company' => $company,
            ]);

            // Configurar el PDF
            $pdf->setPaper('letter', 'portrait');

            // Retornar el PDF como stream (no forzar descarga)
            // Esto permite que el frontend maneje la descarga correctamente
            return $pdf->stream('inventario-' . $inventoryCount->id . '-' . now()->format('Y-m-d') . '.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }
}
