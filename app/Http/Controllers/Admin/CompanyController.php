<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Http\Resources\Admin\CompanyResource;
use App\Models\Admin\Company;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanyController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = CompanyResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Company::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el índice
            // Ejemplo: 'category', 'unit'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            // Agregar aquí las relaciones para el show
            // Ejemplo: 'category', 'unit', 'suppliers'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtro por estado activo/inactivo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Filtro por RFC
        if (isset($params['rfc'])) {
            $query->where('rfc', 'like', '%' . $params['rfc'] . '%');
        }

        // Filtro por ciudad (si está en la dirección)
        if (isset($params['city'])) {
            $query->where('address', 'like', '%' . $params['city'] . '%');
        }

        // Búsqueda en múltiples campos
        if (isset($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'business_name' => 'required|string|max:255',
            'rfc' => [
                'required',
                'string',
                'max:13',
                'regex:/^[A-Z]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                'unique:companies,rfc'
            ],
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]+$/',
            'email' => 'required|email|max:255|unique:companies,email',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'name' => 'required|string|max:255|unique:companies,name,' . $model->id,
            'business_name' => 'required|string|max:255',
            'rfc' => [
                'required',
                'string',
                'max:13',
                'regex:/^[A-Z]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                'unique:companies,rfc,' . $model->id
            ],
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]+$/',
            'email' => 'required|email|max:255|unique:companies,email,' . $model->id,
            'is_active' => 'nullable|boolean',
        ]);
    }

    /**
     * Procesar datos antes de crear (opcional)
     */
    protected function processStoreData(array $validatedData, Request $request): array
    {
        // Normalizar RFC a mayúsculas
        if (isset($validatedData['rfc'])) {
            $validatedData['rfc'] = strtoupper(trim($validatedData['rfc']));
        }

        // Normalizar email a minúsculas
        if (isset($validatedData['email'])) {
            $validatedData['email'] = strtolower(trim($validatedData['email']));
        }

        // Limpiar teléfono de espacios extra
        if (isset($validatedData['phone'])) {
            $validatedData['phone'] = trim($validatedData['phone']);
        }

        // Establecer is_active por defecto si no se proporciona
        if (!isset($validatedData['is_active'])) {
            $validatedData['is_active'] = true;
        }

        return $validatedData;
    }

    /**
     * Procesar datos antes de actualizar (opcional)
     */
    protected function processUpdateData(array $validatedData, Request $request, Model $model): array
    {
        // Normalizar RFC a mayúsculas
        if (isset($validatedData['rfc'])) {
            $validatedData['rfc'] = strtoupper(trim($validatedData['rfc']));
        }

        // Normalizar email a minúsculas
        if (isset($validatedData['email'])) {
            $validatedData['email'] = strtolower(trim($validatedData['email']));
        }

        // Limpiar teléfono de espacios extra
        if (isset($validatedData['phone'])) {
            $validatedData['phone'] = trim($validatedData['phone']);
        }

        return $validatedData;
    }

    /**
     * Manejo personalizado del proceso de creación/actualización
     * Usa transacciones para operaciones seguras
     */
    protected function process($callback, array $data, $method = 'create'): Model
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
        // Log de auditoría
        Log::info('Nueva compañía creada', [
            'company_id' => $model->id,
            'name' => $model->name,
            'rfc' => $model->rfc,
            'created_by' => $request->user()?->id ?? null
        ]);

        // Aquí podrías crear ubicación principal por defecto
        // $model->locations()->create([
        //     'name' => 'Oficina Principal',
        //     'address' => $model->address,
        //     'is_main' => true
        // ]);
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
        // Verificar si la compañía tiene usuarios asociados
        if ($model->users()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar la compañía porque tiene usuarios asociados'
            ];
        }

        // Verificar si la compañía tiene ubicaciones asociadas
        if ($model->locations()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar la compañía porque tiene ubicaciones asociadas'
            ];
        }

        // Verificar si la compañía tiene órdenes de compra/venta
        if ($model->purchaseOrders()->exists() || $model->salesOrders()->exists()) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar la compañía porque tiene órdenes asociadas'
            ];
        }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
