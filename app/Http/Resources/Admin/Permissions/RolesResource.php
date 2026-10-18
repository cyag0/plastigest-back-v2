<?php

namespace App\Http\Resources\Admin\Permissions;

use App\Http\Resources\Resources;
use App\Models\Admin\Role;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class RolesResource extends Resources
{
    /**
     * @var Role $resource
     */
    public function formatter($resource, array $data, array $context): array
    {
        $editing = $context['editing'] ?? false;
        // Quienes tienen el rol: la lista ya trae cuántos (users_count); el
        // detalle los busca una vez para el conteo y para held_by_current_user.
        $holderIds = !$editing && array_key_exists('users_count', $resource->getAttributes())
            ? null
            : $resource->holderIds();

        $item = [
            'id' => $resource->id,
            'name' => $resource->name,
            'description' => $resource->description,
            'company_id' => $resource->company_id,
            // El listado y el selector de roles pintan el chip Activo/Inactivo con
            // este campo, así que va siempre y no solo en show/edit.
            'is_active' => $resource->is_active ?? true,
            // ¿El usuario actual puede dar (o quitar) este rol? La misma regla
            // que UserController al asignarlo: el selector de roles bloquea
            // los demás en vez de dejar que el guardado falle con 403.
            'assignable' => CurrentWorker::canAssignRole($resource),
            // Cuántas personas lo tienen (directo o en alguna sucursal, cada
            // una una vez): al desactivarlo, la app dice a cuántas les quita
            // los permisos. La lista lo trae en la misma consulta.
            'users_count' => $holderIds === null
                ? (int) $resource->getAttributes()['users_count']
                : count($holderIds),
        ];

        // Campos adicionales según el contexto
        if ($editing) {
            // Datos completos para show/edit
            $item['is_system'] = (bool) $resource->is_system;
            // Quien edita tiene este rol: no lo puede desactivar
            // (RolesController::guardActivation), y la app no lo ofrece.
            $item['held_by_current_user'] = in_array((int) Auth::id(), $holderIds ?? [], true);
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        // Permisos según contexto
        if ($resource->relationLoaded('permissions')) {
            if (!$editing) {
                // Para index: solo las primeras descripciones (para los chips)
                $item['permissions'] = $resource->permissions
                    ->take(10) // Limitar para performance
                    ->pluck('description');

                $item['permissions_count'] = $resource->permissions->count();
            } else {
                // Para show/edit: datos completos de permisos
                // Forzar que permissions sea un objeto (no array) para el frontend
                $permissions = (object)[];
                foreach ($resource->permissions as $permission) {
                    $permissions->{$permission->id} = true;
                }
                $item['permissions'] = $permissions;
            }
        }

        return $item;
    }
}
