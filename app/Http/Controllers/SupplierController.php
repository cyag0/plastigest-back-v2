<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SupplierController extends CrudController
{
    /**
     * El resource que se usará para retornar en cada petición
     */
    protected string $resource = SupplierResource::class;

    /**
     * El modelo que manejará este controlador
     */
    protected string $model = Supplier::class;

    /**
     * Relaciones que se cargarán en el index
     */
    protected function indexRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Relaciones que se cargarán en show y después de crear/actualizar
     */
    protected function getShowRelations(): array
    {
        return [
            'company'
        ];
    }

    /**
     * Manejo de filtros personalizados
     */
    protected function handleQuery($query, array $params)
    {
        // Filtro por compañía
        if (isset($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        }

        // Filtro por estado activo/inactivo
        if (isset($params['is_active'])) {
            $query->where('is_active', $params['is_active']);
        }

        // Filtro por RFC
        if (isset($params['rfc'])) {
            $query->where('rfc', 'like', '%' . $params['rfc'] . '%');
        }

        // Búsqueda por nombre o razón social
        if (isset($params['search'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['search'] . '%')
                    ->orWhere('business_name', 'like', '%' . $params['search'] . '%');
            });
        }
    }

    /**
     * Validación para store
     */
    protected function validateStoreData(Request $request): array
    {
        if ($request->has('is_active')) {
            $val = $request->input('is_active');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_active' => (bool)$val]);
        }

        return $request->validate([
            'name' => 'required|string|max:150',
            'business_name' => 'nullable|string|max:255',
            'social_reason' => 'nullable|string|max:20',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);
    }

    /**
     * Validación para update
     */
    protected function validateUpdateData(Request $request, Model $model): array
    {
        if ($request->has('is_active')) {
            $val = $request->input('is_active');
            if (!is_bool($val)) {
                $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
            }
            $request->merge(['is_active' => (bool)$val]);
        }

        return $request->validate([
            'name' => 'required|string|max:150',
            'business_name' => 'nullable|string|max:255',
            'social_reason' => 'nullable|string|max:20',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
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

            $data["company_id"] = isset($data["company_id"][0]) ? $data["company_id"][0] : $data["company_id"];

            // El callback ejecuta el store() o update() del modelo
            $model = $callback($data);

            // Aquí puedes agregar lógica adicional específica del modelo
            // que se ejecutará tanto para crear como para actualizar
            //
            // Ejemplos:
            // - Manejar relaciones (sincronizar muchos a muchos)
            // - Procesar archivos adjuntos
            // - Actualizar campos calculados
            // - Registrar logs de auditoría
            // - Invalidar caché
            // - Enviar notificaciones
            //
            // Diferencial por método si es necesario:
            // if ($method === 'create') {
            //     // Lógica específica para creación
            //     $this->handleCreationSpecificLogic($model, $data);
            // } else if ($method === 'update') {
            //     // Lógica específica para actualización
            //     $this->handleUpdateSpecificLogic($model, $data);
            // }

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
        //         'can_delete' => false,
        //         'message' => 'No se puede eliminar porque tiene órdenes asociadas'
        //     ];
        // }

        return [
            'can_delete' => true,
            'message' => ''
        ];
    }
}
